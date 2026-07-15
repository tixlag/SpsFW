<?php

/**
 * M0 closure — operationId reconciliation generator (cross-repo DEV tool).
 *
 * Produces docs/metadata_compiler_audit/operation_id_reconciliation.tsv by cross-referencing:
 *   (R) the 377 route keys from docs/metadata_compiler_audit/route_inventory.txt (local snapshot of N)
 *   (S) ALL HTTP operations parsed from next/.cache/swagger/openapi.yml (Symfony YAML; read-only)
 *   (P) generated react-query functions from public_next/src/lk-openapi/react-query/endpoints (read-only)
 *
 * This script REFERENCES the sibling repos (N, P) by path; SpsFW's clean checkout does NOT depend on
 * them at runtime — only the committed TSV output is the artifact. Re-run after spec/client changes.
 *
 * Usage: php gen_operation_id_reconciliation.php [spec.yml] [route_inventory.txt] [P_endpoints_dir] [out.tsv]
 * Defaults point at the sibling repos on this host.
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

$specPath = $argv[1] ?? '/home/tixlag/PhpstormProjects/lk.sps38.pro/next/.cache/swagger/openapi.yml';
$routesPath = $argv[2] ?? __DIR__ . '/route_inventory.txt';
$pDir = $argv[3] ?? '/home/tixlag/PhpstormProjects/lk.sps38.pro/public_next/src/lk-openapi/react-query/endpoints';
$outPath = $argv[4] ?? __DIR__ . '/operation_id_reconciliation.tsv';

/**
 * Normalize a path for cross-source matching: collapse path params to {} so that
 * param-NAME differences do not break the join. Required because swagger-php emits
 * snake_case path params ({code_1c}) while orval rewrites the generated url with
 * camelCase TS vars (${code1c}). Structure (param positions) is preserved.
 */
function normPath(string $p): string
{
    $p = preg_replace('/\$\{[^}]+\}/', '{}', $p); // JS template literal ${x}
    $p = preg_replace('/\{[^}]+\}/', '{}', $p);   // OpenAPI {x}
    return $p;
}

/* ---------- (R) routes: METHOD:normpath -> [controller::method, origPath] ---------- */
$routes = [];  // key -> ['ctrl'=>, 'path'=>]
foreach (file($routesPath, FILE_IGNORE_NEW_LINES) as $line) {
    if ($line === '' || str_starts_with($line, '#')) {
        continue;
    }
    $c = explode("\t", $line);
    if (count($c) >= 3 && in_array($c[0], ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'], true)) {
        $key = $c[0] . ':' . normPath($c[1]);
        $routes[$key] = ['ctrl' => $c[2], 'path' => $c[1]];
    }
}

/* ---------- (S) spec: parse ALL operations ---------- */
$specOps = []; // METHOD:normpath -> ['opId'=>|null, 'path'=>orig]
$spec = Yaml::parseFile($specPath);
foreach ($spec['paths'] ?? [] as $path => $methods) {
    if (!is_array($methods)) {
        continue;
    }
    foreach ($methods as $method => $op) {
        $method = strtoupper($method);
        if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'], true)) {
            continue; // skip `parameters`, `summary`, etc.
        }
        $key = $method . ':' . normPath($path);
        $specOps[$key] = [
            'opId' => is_array($op) && array_key_exists('operationId', $op) ? $op['operationId'] : null,
            'path' => $path,
        ];
    }
}

/* ---------- (P) generated client: METHOD:normpath -> function name ---------- */
$pFuncs = []; // key -> ['fn'=>, 'url'=>orig]
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pDir)) as $f) {
    if ($f->isFile() && $f->getExtension() === 'ts') {
        $src = file_get_contents($f->getPathname());
        // Split into export blocks; keep blocks that contain a mutatorInstance call.
        $parts = preg_split('/^export const /m', $src);
        foreach ($parts as $block) {
            if (!str_contains($block, 'mutatorInstance')) {
                continue;
            }
            if (!preg_match('/^(\w+)\s*=/', $block, $m)) {
                continue;
            }
            $fn = $m[1];
            if (!preg_match('/url:\s*`([^`]+)`/', $block, $u)) {
                continue;
            }
            if (!preg_match("/method:\s*'([A-Z]+)'/", $block, $meth)) {
                continue;
            }
            $key = $meth[1] . ':' . normPath($u[1]);
            $pFuncs[$key] = ['fn' => $fn, 'url' => $u[1]];
        }
    }
}

/* ---------- union + per-row classification ---------- */
$allKeys = array_unique(array_merge(array_keys($routes), array_keys($specOps), array_keys($pFuncs)));
sort($allKeys);

$rows = [];
$counts = [
    'routes_total' => count($routes),
    'spec_paths' => count($spec['paths'] ?? []),
    'spec_ops' => count($specOps),
    'routes_in_spec' => 0,        // R ∩ S
    'routes_absent_in_spec' => 0, // R \ S
    'spec_absent_in_routes' => 0, // S \ R
    'explicit' => 0,
    'method_fallback' => 0,
    'spec_only' => 0,
    'missing' => 0,
    'in_p_client' => 0,
    'spec_in_p' => 0,             // S ∩ P
    'spec_not_in_p' => 0,         // S \ P
    'p_not_in_spec' => 0,         // P \ S
    'explicit_fn_matches_opid' => 0,
    'explicit_fn_mismatch' => 0,
];

foreach ($allKeys as $key) {
    [$method] = explode(':', $key, 2);
    $inSpec = array_key_exists($key, $specOps);
    $inRoute = array_key_exists($key, $routes);
    $inP = array_key_exists($key, $pFuncs);

    // Display path: prefer route (source of truth), then spec, then P url (originals, not normalized).
    $path = $inRoute ? $routes[$key]['path'] : ($inSpec ? $specOps[$key]['path'] : ($inP ? $pFuncs[$key]['url'] : '-'));
    $ctrl = $inRoute ? $routes[$key]['ctrl'] : '-';
    $opId = ($inSpec && $specOps[$key]['opId'] !== null) ? $specOps[$key]['opId'] : '-';
    $hasOpId = $inSpec && $specOps[$key]['opId'] !== null;
    $fn = $inP ? $pFuncs[$key]['fn'] : '-';
    $qk = $fn; // generated_query_key == generated_function under useOperationIdAsQueryKey (see finding)

    if ($inSpec && $inRoute) {
        $counts['routes_in_spec']++;
    }
    if ($inRoute && !$inSpec) {
        $counts['routes_absent_in_spec']++;
    }
    if ($inSpec && !$inRoute) {
        $counts['spec_absent_in_routes']++;
    }

    // id_source + canonical
    $methodName = $inRoute ? (strpos($routes[$key]['ctrl'], '::') !== false ? explode('::', $routes[$key]['ctrl'])[1] : $routes[$key]['ctrl']) : '-';
    if ($hasOpId) {
        $idSource = 'explicit';
        $canonical = $specOps[$key]['opId'];
        $counts['explicit']++;
        if ($inP) {
            if ($fn === $specOps[$key]['opId']) {
                $counts['explicit_fn_matches_opid']++;
            } else {
                $counts['explicit_fn_mismatch']++;
            }
        }
    } elseif ($inSpec && $inRoute) {
        $idSource = 'method-fallback';
        $canonical = $methodName;
        $counts['method_fallback']++;
    } elseif ($inSpec) { // in spec, no route
        $idSource = 'spec-only';
        $canonical = '-';
        $counts['spec_only']++;
    } else { // in route, not in spec (no operation to derive an id from)
        $idSource = 'missing';
        $canonical = $methodName; // proposed M9 fallback to method name
        $counts['missing']++;
    }

    if ($inP) {
        $counts['in_p_client']++;
    }
    if ($inSpec && $inP) {
        $counts['spec_in_p']++;
    }
    if ($inSpec && !$inP) {
        $counts['spec_not_in_p']++;
    }
    if (!$inSpec && $inP) {
        $counts['p_not_in_spec']++;
    }

    $rows[] = [$method, $path, $ctrl, $inSpec ? 'Y' : 'N', $opId, $idSource, $fn, $qk, $canonical];
}

/* ---------- write TSV ---------- */
$fp = fopen($outPath, 'w');
fwrite($fp, "# operationId reconciliation — generated by gen_operation_id_reconciliation.php\n");
fwrite($fp, "# sources: R=route_inventory.txt (local snapshot of N)  S=next/.cache/swagger/openapi.yml  P=public_next/.../endpoints\n");
foreach ($counts as $k => $v) {
    fwrite($fp, "# count.$k=$v\n");
}
fwrite($fp, "METHOD\tpath\tcontroller::method\tpresent_in_legacy_spec\tlegacy_operation_id\tid_source\tgenerated_function\tgenerated_query_key\tcanonical_id\n");
foreach ($rows as $r) {
    fwrite($fp, implode("\t", $r) . "\n");
}
fclose($fp);

/* ---------- stdout summary ---------- */
echo "routes_total={$counts['routes_total']} spec_paths={$counts['spec_paths']} spec_ops={$counts['spec_ops']}\n";
echo "R∩S={$counts['routes_in_spec']}  R\\S={$counts['routes_absent_in_spec']}  S\\R={$counts['spec_absent_in_routes']}\n";
echo "explicit={$counts['explicit']} method-fallback={$counts['method_fallback']} spec-only={$counts['spec_only']} missing={$counts['missing']}\n";
echo "S∩P={$counts['spec_in_p']} S\\P={$counts['spec_not_in_p']} P\\S={$counts['p_not_in_spec']} (generated functions={$counts['in_p_client']})\n";
echo "explicit-id ops: fn==opId={$counts['explicit_fn_matches_opid']} fn!=opId={$counts['explicit_fn_mismatch']}\n";
echo "rows=" . count($rows) . " written to $outPath\n";
