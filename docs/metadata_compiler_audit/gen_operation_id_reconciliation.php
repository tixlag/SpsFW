<?php

/**
 * M0 closure — operationId reconciliation generator (cross-repo DEV tool, rev. 2).
 *
 * Produces docs/metadata_compiler_audit/operation_id_reconciliation.tsv by cross-referencing:
 *   (R) the 377 route keys from route_inventory.txt (local snapshot of N: .cache/compiled_routes.php)
 *   (S) ALL HTTP operations parsed from next/.cache/swagger/openapi.yml (Symfony YAML; read-only)
 *   (P) generated react-query client from public_next/.../react-query/endpoints (read-only)
 *
 * rev. 2 changes vs rev. 1 (driven by review):
 *   - query/mutation keys are EXTRACTED from P (the get{Fn}QueryKey getter's returned array), never
 *     fabricated as qk=fn. Operations without a key getter (mutations) get '-'.
 *   - normalized-key collisions inside R / S / P are DETECTED and reported, not silently overwritten.
 *   - route-only (R\S) and spec-only (S\R) operations are itemized for MANUAL classification
 *     (exclude|add|stale); classification is applied from operation_id_classification.php.
 *   - route-only ops are NOT fixed as bare method-name: the candidate id is the new convention
 *     <ControllerShort><Method> with a uniqueness check; excluded ops are kept OUT of the lockfile.
 *   - spec-only/stale ops are NOT auto-carried into the operationId lockfile.
 *
 * rev. 3 changes vs rev. 2 (pre-M9 policy + column split):
 *   - canonical_id is SEPARATED from the bare method-name: a new migration_candidate_id column holds the
 *     method-name a legacy op WOULD receive in a coordinated M9 (may collide); canonical_id holds only the
 *     id READY to pin (explicit opId or route-only <ControllerShort><Method>). Colliding bare names are
 *     therefore never presented as ready lockfile ids.
 *   - pre-M9 policy: 39 explicit ids preserved; 306 method-fallback ops keep operationId=null (do NOT
 *     change P) -> lockfile=deferred, canonical_id='-'; controller-qualified id only in coordinated M9.
 *   - route-only 'pending' resolution: public-vs-internal undecided -> held OUT (lockfile=out) until owner.
 *   - canonical_collisions counts only READY ids (expect 0); migration_candidate_collisions counts the
 *     bare method-name collisions of the deferred M9 set.
 *
 * This script REFERENCES the sibling repos (N, P) by path; SpsFW's clean checkout does NOT depend on
 * them at runtime — only the committed TSV output is the artifact. Re-run after spec/client changes.
 *
 * Usage: php gen_operation_id_reconciliation.php [spec.yml] [route_inventory.txt] [P_endpoints_dir] [out.tsv]
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

$specPath   = $argv[1] ?? '/home/tixlag/PhpstormProjects/lk.sps38.pro/next/.cache/swagger/openapi.yml';
$routesPath = $argv[2] ?? __DIR__ . '/route_inventory.txt';
$pDir       = $argv[3] ?? '/home/tixlag/PhpstormProjects/lk.sps38.pro/public_next/src/lk-openapi/react-query/endpoints';
$outPath    = $argv[4] ?? __DIR__ . '/operation_id_reconciliation.tsv';
$classPath  = __DIR__ . '/operation_id_classification.php';

$HTTP = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'];

/**
 * Normalize a path for cross-source matching: collapse path params to {} so param-NAME differences
 * do not break the join. Required because swagger-php emits snake_case params ({code_1c}) while orval
 * rewrites the generated url with camelCase TS vars (${code1c}). Param POSITIONS are preserved.
 */
function normPath(string $p): string
{
    $p = preg_replace('/\$\{[^}]+\}/', '{}', $p); // JS template literal ${x}
    $p = preg_replace('/\{[^}]+\}/', '{}', $p);   // OpenAPI {x}
    return $p;
}

/** Controller short name for the <ControllerShort><Method> convention: strip namespace + 'Controller'. */
function controllerShort(string $controllerMethod): string
{
    [$class] = explode('::', $controllerMethod, 2) + [1 => ''];
    $short = substr($class, (strrpos($class, '\\') ?: -1) + 1);
    if (str_ends_with($short, 'Controller')) {
        $short = substr($short, 0, -strlen('Controller'));
    }
    return $short;
}

function methodName(string $controllerMethod): string
{
    $parts = explode('::', $controllerMethod, 2);
    return $parts[1] ?? ($parts[0] ?? '-');
}

/**
 * Load a key=>record map with collision detection.
 * Returns [map, collisions] where collisions is a list of [key, firstOriginal, duplicateOriginal].
 */
function loadKeyed(array $entries, string $source): array
{
    $map = [];
    $collisions = [];
    foreach ($entries as [$key, $original, $record]) {
        if (isset($map[$key])) {
            $collisions[] = ['source' => $source, 'key' => $key, 'first' => $map[$key]['original'], 'dup' => $original];
        } else {
            $map[$key] = ['original' => $original, 'record' => $record];
        }
    }
    return [$map, $collisions];
}

/* ---------- (R) routes: METHOD:normpath -> controller::method ---------- */
$routeEntries = [];
foreach (file($routesPath, FILE_IGNORE_NEW_LINES) as $line) {
    if ($line === '' || str_starts_with($line, '#')) {
        continue;
    }
    $c = explode("\t", $line);
    if (count($c) >= 3 && in_array($c[0], $HTTP, true)) {
        $key = $c[0] . ':' . normPath($c[1]);
        $routeEntries[] = [$key, $c[0] . ' ' . $c[1], ['ctrl' => $c[2], 'path' => $c[1]]];
    }
}
[$routes, $routeCollisions] = loadKeyed($routeEntries, 'R');

/* ---------- (S) spec: parse ALL operations ---------- */
$spec = Yaml::parseFile($specPath);
$specEntries = [];
foreach ($spec['paths'] ?? [] as $path => $methods) {
    if (!is_array($methods)) {
        continue;
    }
    foreach ($methods as $method => $op) {
        $method = strtoupper($method);
        if (!in_array($method, $HTTP, true)) {
            continue; // skip `parameters`, `summary`, etc.
        }
        $key = $method . ':' . normPath($path);
        $opId = is_array($op) && array_key_exists('operationId', $op) ? $op['operationId'] : null;
        $specEntries[] = [$key, $method . ' ' . $path, ['opId' => $opId, 'path' => $path]];
    }
}
[$specOps, $specCollisions] = loadKeyed($specEntries, 'S');

/* ---------- (P) generated client: fn + REAL query key ---------- */
$pEntries = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pDir)) as $f) {
    if (!$f->isFile() || $f->getExtension() !== 'ts') {
        continue;
    }
    $src = file_get_contents($f->getPathname());

    // operation functions: blocks calling mutatorInstance -> fn, url, method
    $blocks = preg_split('/^export const /m', $src);
    $fnUrl = []; // fn -> [url, method]
    foreach ($blocks as $block) {
        if (!str_contains($block, 'mutatorInstance')) {
            continue;
        }
        if (!preg_match('/^(\w+)\s*=/', $block, $m)) {
            continue;
        }
        $fn = $m[1];
        if (preg_match('/url:\s*`([^`]+)`/', $block, $u) && preg_match("/method:\s*'([A-Z]+)'/", $block, $meth)) {
            $fnUrl[$fn] = [$u[1], $meth[1]];
        }
    }

    // query key getters: get{X}QueryKey = ... => { return [ "keyName", ... ] as const; }
    // The key name is the first string literal of the returned array.
    $keyByName = []; // getterName -> key content
    if (preg_match_all('/export const (get\w+QueryKey)\b.*?return\s*\[\s*"([^"]+)"/s', $src, $gm, PREG_SET_ORDER)) {
        foreach ($gm as $g) {
            $keyByName[$g[1]] = $g[2];
        }
    }

    foreach ($fnUrl as $fn => [$url, $method]) {
        $key = $method . ':' . normPath($url);
        $getter = 'get' . ucfirst($fn) . 'QueryKey';
        $qk = $keyByName[$getter] ?? null; // null => no key getter (mutation)
        $pEntries[] = [$key, $method . ' ' . $url, ['fn' => $fn, 'url' => $url, 'qk' => $qk]];
    }
}
[$pFuncs, $pCollisions] = loadKeyed($pEntries, 'P');

/* ---------- manual classification for the 32 route-only + 2 spec-only gaps ---------- */
/** @var array<string, array{resolution: string, reason: string}> $classification */
$classification = [];
if (is_file($classPath)) {
    $classification = require $classPath;
}

/* ---------- union + per-row classification ---------- */
$allKeys = array_unique(array_merge(array_keys($routes), array_keys($specOps), array_keys($pFuncs)));
sort($allKeys);

$rows = [];
$counts = [
    'routes_total' => count($routes),
    'spec_paths' => count($spec['paths'] ?? []),
    'spec_ops' => count($specOps),
    'routes_in_spec' => 0,
    'route_only' => 0,
    'spec_only' => 0,
    'explicit' => 0,
    'method_fallback' => 0,
    'in_p_client' => 0,
    'has_query_key' => 0,
    'qk_equals_fn' => 0,
    'qk_differs_fn' => 0,
    'spec_in_p' => 0,
    'spec_not_in_p' => 0,
    'p_not_in_spec' => 0,
    'explicit_fn_matches_opid' => 0,
    'explicit_fn_mismatch' => 0,
    'lockfile_in' => 0,
    'lockfile_deferred' => 0,
    'lockfile_out' => 0,
    'route_only_add' => 0,
    'route_only_pending' => 0,
    'route_only_exclude' => 0,
    'route_only_stale' => 0,
];

// new-convention uniqueness check across route-only ADD candidates
$proposedIds = [];
$proposedCollisions = [];

foreach ($allKeys as $key) {
    [$method] = explode(':', $key, 2);
    $inSpec = isset($specOps[$key]);
    $inRoute = isset($routes[$key]);
    $inP = isset($pFuncs[$key]);

    $path  = $inRoute ? $routes[$key]['record']['path'] : ($inSpec ? $specOps[$key]['record']['path'] : ($inP ? $pFuncs[$key]['record']['url'] : '-'));
    $ctrl  = $inRoute ? $routes[$key]['record']['ctrl'] : '-';
    $opId  = ($inSpec && $specOps[$key]['record']['opId'] !== null) ? $specOps[$key]['record']['opId'] : '-';
    $hasOpId = $inSpec && $specOps[$key]['record']['opId'] !== null;
    $fn    = $inP ? $pFuncs[$key]['record']['fn'] : '-';
    $qk    = ($inP && $pFuncs[$key]['record']['qk'] !== null) ? $pFuncs[$key]['record']['qk'] : '-';
    $mName = $inRoute ? methodName($ctrl) : '-';

    if ($inSpec && $inRoute) { $counts['routes_in_spec']++; }
    if ($inRoute && !$inSpec) { $counts['route_only']++; }
    if ($inSpec && !$inRoute) { $counts['spec_only']++; }
    if ($inP) { $counts['in_p_client']++; }
    if ($inP && $pFuncs[$key]['record']['qk'] !== null) {
        $counts['has_query_key']++;
        if ($pFuncs[$key]['record']['qk'] === $fn) { $counts['qk_equals_fn']++; } else { $counts['qk_differs_fn']++; }
    }
    if ($inSpec && $inP) { $counts['spec_in_p']++; }
    if ($inSpec && !$inP) { $counts['spec_not_in_p']++; }
    if (!$inSpec && $inP) { $counts['p_not_in_spec']++; }

    // id_source / migration_candidate_id / canonical_id / lockfile / classification.
    // canonical_id is the id READY to pin in the lockfile (non-colliding). migration_candidate_id is the
    // bare method-name a legacy op WOULD receive in a coordinated M9 — it may collide, so it is kept
    // SEPARATE from canonical_id: colliding bare names are never presented as ready lockfile ids.
    // Membership-first: a spec-only op is an orphan even when it carries an explicit operationId,
    // so it is classified spec-only (not carried into the lockfile) rather than hidden as 'explicit'.
    $idSource = '-';
    $canonical = '-';
    $migrationCandidate = '-';
    $lockfile = 'out';
    $classificationLabel = $classification[$key] ?? null;

    if ($inRoute && $inSpec) {
        // documented route
        if ($hasOpId) {
            // pre-M9 policy: the 39 explicit ids are PRESERVED verbatim.
            $idSource = 'explicit';
            $canonical = $specOps[$key]['record']['opId'];
            $lockfile = 'in';
            $counts['explicit']++;
            if ($inP) {
                if ($fn === $canonical) { $counts['explicit_fn_matches_opid']++; } else { $counts['explicit_fn_mismatch']++; }
            }
        } else {
            // pre-M9 policy: a legacy op without operationId keeps operationId=null (do NOT change P).
            // The bare method-name is only a MIGRATION CANDIDATE (collides -> not a ready lockfile id);
            // a controller-qualified id for these 306 ops is introduced only in the coordinated M9 step.
            $idSource = 'method-fallback';
            $migrationCandidate = $mName;
            $lockfile = 'deferred';
            $counts['method_fallback']++;
        }
    } elseif ($inRoute && !$inSpec) {
        // route-only: candidate id is the NEW convention <ControllerShort><Method>, not bare method-name
        $idSource = 'route-only';
        $csMethod = controllerShort($ctrl) . ucfirst($mName);
        $res = $classificationLabel['resolution'] ?? 'exclude';
        if ($res === 'add') {
            $canonical = $csMethod; // ready to pin under the new convention
            $lockfile = 'in';
            $counts['route_only_add']++;
            if (isset($proposedIds[$csMethod])) {
                $proposedCollisions[] = ['id' => $csMethod, 'first' => $proposedIds[$csMethod], 'dup' => $key];
            } else {
                $proposedIds[$csMethod] = $key;
            }
        } elseif ($res === 'pending') {
            // public-vs-internal undecided -> held OUT until the owner confirms
            $lockfile = 'out';
            $counts['route_only_pending']++;
        } elseif ($res === 'stale') {
            $counts['route_only_stale']++;
        } else {
            $counts['route_only_exclude']++;
        }
    } else {
        // in spec / client but NOT in routes -> orphan/stale; an explicit opId here is still an orphan
        // and must NOT be carried into the operationId lockfile.
        $idSource = 'spec-only';
        $lockfile = 'out';
    }

    if ($lockfile === 'in') { $counts['lockfile_in']++; }
    elseif ($lockfile === 'deferred') { $counts['lockfile_deferred']++; }
    else { $counts['lockfile_out']++; }
    $cls = $classificationLabel ? ($classificationLabel['resolution'] . ': ' . $classificationLabel['reason']) : '-';

    $rows[] = [$method, $path, $ctrl, $inSpec ? 'Y' : 'N', $opId, $idSource, $fn, $qk, $migrationCandidate, $canonical, $lockfile, $cls];
}

/* ---------- canonical-id uniqueness: only READY ids (lockfile=in) become lockfile entries ---------- */
$canonicalSeen = [];
$canonicalCollisions = [];
foreach ($rows as $r) {
    if ($r[10] !== 'in' || $r[9] === '-' || $r[9] === '') {
        continue;
    }
    $cid = $r[9];
    if (isset($canonicalSeen[$cid])) {
        $canonicalCollisions[] = ['id' => $cid, 'first' => $canonicalSeen[$cid], 'dup' => $r[0] . ' ' . $r[1]];
    } else {
        $canonicalSeen[$cid] = $r[0] . ' ' . $r[1];
    }
}

/* ---------- migration-candidate uniqueness: bare method-names (the deferred M9 set, NOT ready ids) ---------- */
$migrationSeen = [];
$migrationCollisions = [];
foreach ($rows as $r) {
    if ($r[8] === '-' || $r[8] === '') {
        continue;
    }
    $mc = $r[8];
    if (isset($migrationSeen[$mc])) {
        $migrationCollisions[] = ['id' => $mc, 'first' => $migrationSeen[$mc], 'dup' => $r[0] . ' ' . $r[1]];
    } else {
        $migrationSeen[$mc] = $r[0] . ' ' . $r[1];
    }
}

/* ---------- write TSV ---------- */
$allCollisions = array_merge($routeCollisions, $specCollisions, $pCollisions);
$fp = fopen($outPath, 'w');
fwrite($fp, "# operationId reconciliation (rev. 3) — generated by gen_operation_id_reconciliation.php\n");
fwrite($fp, "# sources: R=route_inventory.txt  S=next/.cache/swagger/openapi.yml  P=public_next/.../endpoints\n");
fwrite($fp, "# query_key is EXTRACTED from P's get{Fn}QueryKey getter (mutations have none -> -)\n");
fwrite($fp, "# canonical_id = id READY to pin (explicit opId | route-only <ControllerShort><Method>); migration_candidate_id = bare method-name a legacy op WOULD get in M9 (may collide, NOT a ready lockfile id)\n");
foreach ($counts as $k => $v) {
    fwrite($fp, "# count.$k=$v\n");
}
fwrite($fp, "# collisions_detected=" . count($allCollisions) . "\n");
foreach ($allCollisions as $c) {
    fwrite($fp, "# collision {$c['source']} {$c['key']} :: first={$c['first']} dup={$c['dup']}\n");
}
fwrite($fp, "# proposed_id_collisions=" . count($proposedCollisions) . "\n");
foreach ($proposedCollisions as $c) {
    fwrite($fp, "# proposed-collision {$c['id']} :: first={$c['first']} dup={$c['dup']}\n");
}
fwrite($fp, "# canonical_collisions=" . count($canonicalCollisions) . "\n");
foreach ($canonicalCollisions as $c) {
    fwrite($fp, "# canonical-collision {$c['id']} :: first={$c['first']} dup={$c['dup']}\n");
}
fwrite($fp, "# migration_candidate_collisions=" . count($migrationCollisions) . "\n");
foreach ($migrationCollisions as $c) {
    fwrite($fp, "# migration-candidate-collision {$c['id']} :: first={$c['first']} dup={$c['dup']}\n");
}
fwrite($fp, "METHOD\tpath\tcontroller::method\tin_spec\tlegacy_op_id\tid_source\tgenerated_function\tquery_key\tmigration_candidate_id\tcanonical_id\tlockfile\tclassification\n");
foreach ($rows as $r) {
    fwrite($fp, implode("\t", $r) . "\n");
}
fclose($fp);

/* ---------- stdout summary ---------- */
echo "routes_total={$counts['routes_total']} spec_paths={$counts['spec_paths']} spec_ops={$counts['spec_ops']}\n";
echo "R∩S={$counts['routes_in_spec']}  route-only(R\\S)={$counts['route_only']}  spec-only(S\\R)={$counts['spec_only']}\n";
echo "explicit={$counts['explicit']} method-fallback={$counts['method_fallback']} route-only={$counts['route_only']} spec-only={$counts['spec_only']}\n";
echo "P: total={$counts['in_p_client']} with_query_key={$counts['has_query_key']} (qk==fn={$counts['qk_equals_fn']}, qk!=fn={$counts['qk_differs_fn']})\n";
echo "S∩P={$counts['spec_in_p']} S\\P={$counts['spec_not_in_p']} P\\S={$counts['p_not_in_spec']}\n";
echo "explicit-id ops: fn==opId={$counts['explicit_fn_matches_opid']} fn!=opId={$counts['explicit_fn_mismatch']}\n";
echo "lockfile: in={$counts['lockfile_in']} deferred={$counts['lockfile_deferred']} out={$counts['lockfile_out']} (route-only add={$counts['route_only_add']} pending={$counts['route_only_pending']} exclude={$counts['route_only_exclude']} stale={$counts['route_only_stale']})\n";
echo "collisions: R=" . count($routeCollisions) . " S=" . count($specCollisions) . " P=" . count($pCollisions) . " proposed_id=" . count($proposedCollisions) . " canonical=" . count($canonicalCollisions) . " migration_candidate=" . count($migrationCollisions) . "\n";
echo "rows=" . count($rows) . " written to $outPath\n";

/* ---------- gap detail (for manual classification) ---------- */
echo "\n=== ROUTE-ONLY (R\\S) — " . $counts['route_only'] . " ops ===\n";
foreach ($rows as $r) {
    if ($r[5] === 'route-only') {
        echo "  {$r[0]} {$r[1]}  |  {$r[2]}  |  canonical={$r[9]} lockfile={$r[10]}\n";
    }
}
echo "\n=== SPEC-ONLY (S\\R) — " . $counts['spec_only'] . " ops ===\n";
foreach ($rows as $r) {
    if ($r[5] === 'spec-only') {
        echo "  {$r[0]} {$r[1]}  |  opId={$r[4]}  |  fn={$r[6]} qk={$r[7]}\n";
    }
}
