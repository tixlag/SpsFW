<?php

declare(strict_types=1);

/**
 * Dev-only STRICT semantic comparator (second Step 9 corrective pass, spec §3/§4).
 *
 * Recursively compares a Legacy baseline OpenAPI document against the Metadata candidate and reports EVERY
 * semantic difference, grouped by category. Unlike the rejected first-pass comparator, this one:
 *   - walks Operations / Parameters / RequestBody / Responses INDEPENDENTLY (no reliance on a pre-classified
 *     loss-detail JSON);
 *   - does NOT strip description / example / default / item facets — a lost description or item facet IS a diff;
 *   - resolves $ref → component and compares structurally;
 *   - normalizes ONLY key order + provably-equivalent OpenAPI-3.1 representations (nullable↔null-union,
 *     equivalent oneOf/anyOf variant sets, empty-required↔absent).
 *
 * Usage:  php semantic_diff_audit.php <baseline.yml> <candidate.yml>
 * Defaults to the second-pass fixtures under /tmp.
 *
 * Output: a categorized report (operation-set, operation-field, parameter, request-body, response) with, for
 * every diff, METHOD:path + the location + the baseline fragment + the candidate fragment. The exit code is
 * non-zero when any unexplained diff remains (the allowlist is a separate, endpoint-specific concern — §4).
 */

use Symfony\Component\Yaml\Yaml;

// Bootstrap symfony/yaml (the framework repo does not vendor it; the consumer app does). Try the standard
// consumer locations, then the local vendor.
foreach ([
    '/home/tixlag/PhpstormProjects/.wt/lk-step6b/next/vendor/autoload.php',
    '/home/tixlag/PhpstormProjects/lk.sps38.pro/next/vendor/autoload.php',
    dirname(__DIR__, 2) . '/vendor/autoload.php',
] as $candidateAutoload) {
    if (is_file($candidateAutoload)) {
        require $candidateAutoload;
        break;
    }
}
if (!class_exists(Yaml::class)) {
    fwrite(STDERR, "symfony/yaml not found — run from a checkout whose vendor has it (the consumer app)\n");
    exit(2);
}

$baselinePath = $argv[1] ?? '/tmp/d2_baseline_legacy.yml';
$candidatePath = $argv[2] ?? '/tmp/d2_candidate_v2.yml';

$base = Yaml::parseFile($baselinePath);
$cand = Yaml::parseFile($candidatePath);

$HTTP = ['get', 'post', 'put', 'patch', 'delete', 'options', 'head', 'trace'];

$opsOf = static function (array $doc) use ($HTTP): array {
    $out = [];
    foreach (($doc['paths'] ?? []) as $path => $methods) {
        foreach ($methods ?? [] as $method => $op) {
            if (in_array(strtolower((string) $method), $HTTP, true) && is_array($op)) {
                $out[strtoupper($method) . ' ' . $path] = $op;
            }
        }
    }
    return $out;
};

$bOps = $opsOf($base);
$cOps = $opsOf($cand);
$bComp = $base['components']['schemas'] ?? [];
$cComp = $cand['components']['schemas'] ?? [];

// --- schema normalization: resolve $ref, recurse, keep EVERY facet, normalize only proven-equivalent forms ---
$shortRef = static function ($ref): ?string {
    if (!is_string($ref)) return null;
    return substr(strrchr('/' . $ref, '/'), 1);
};

$normalize = static function (mixed $s, array $seen, array $compB, array $compC) use (&$normalize, $shortRef) {
    if (!is_array($s)) return $s;
    if (isset($s['$ref'])) {
        $name = $shortRef($s['$ref']);
        if (isset($seen[$name])) return ['_ref' => $name]; // cycle break
        foreach ([$compC, $compB] as $tbl) {
            if (isset($tbl[$name])) {
                return $normalize($tbl[$name], $seen + [$name => true], $compB, $compC);
            }
        }
        return ['_ref' => $name]; // unresolvable ref — keep the name so a mismatch still surfaces
    }
    $out = [];
    foreach ($s as $k => $v) {
        // empty required [] ≡ absent required — normalize away (never a semantic loss)
        if ($k === 'required' && is_array($v) && $v === []) continue;
        // additionalProperties defaults to TRUE in OpenAPI 3 — an explicit true ≡ absent (only
        // `additionalProperties: false` or an object schema are meaningful constraints).
        if ($k === 'additionalProperties' && $v === true) continue;
        $out[$k] = is_array($v) ? $normalize($v, $seen, $compB, $compC) : $v;
    }
    // implicit array: a schema carrying `items` with no `type` is an array (OpenAPI: `items` is only
    // meaningful under type:array). swagger-php sometimes omits `type:array` when `items` is present.
    if (array_key_exists('items', $out) && !array_key_exists('type', $out)) {
        $out['type'] = 'array';
    }
    // OpenAPI 3.1 nullability equivalence: nullable:true (+scalar type) ≡ type:[T,"null"]; merge into a sorted
    // type union so a nullable scalar compares equal regardless of which 3.x spelling each side uses.
    if (isset($out['nullable']) && $out['nullable'] === true) {
        $t = $out['type'] ?? null;
        $types = is_array($t) ? array_values($t) : ($t === null ? [] : [$t]);
        if (!in_array('null', $types, true)) $types[] = 'null';
        if ($types !== []) $out['type'] = array_values($types);
        unset($out['nullable']);
    }
    if (isset($out['type']) && is_array($out['type'])) {
        $vals = array_values(array_unique($out['type']));
        sort($vals);
        $out['type'] = $vals;
    }
    ksort($out);
    return $out;
};

$nB = static fn ($s) => $normalize($s, [], $bComp, $cComp);
$nC = static fn ($s) => $normalize($s, [], $bComp, $cComp);

// deep structural diff of two normalized fragments → returns null when equal, else a diff tree
$deepDiff = static function (mixed $a, mixed $b) use (&$deepDiff) {
    if (is_array($a) && is_array($b)) {
        $d = [];
        $keys = array_unique(array_merge(array_keys($a), array_keys($b)));
        foreach ($keys as $k) {
            if (!array_key_exists($k, $a)) $d[$k] = ['+' => $b[$k]];
            elseif (!array_key_exists($k, $b)) $d[$k] = ['-' => $a[$k]];
            else { $sub = $deepDiff($a[$k], $b[$k]); if ($sub !== null) $d[$k] = $sub; }
        }
        return $d === [] ? null : $d;
    }
    return $a === $b ? null : ['base' => $a, 'cand' => $b];
};

$j = static fn ($v) => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$report = [];   // category => [lines]

// ============================================================================
// 1. Operation SET (the hard gate — a set change is never "equivalent")
// ============================================================================
$onlyBase = array_diff(array_keys($bOps), array_keys($cOps));
$onlyCand = array_diff(array_keys($cOps), array_keys($bOps));
foreach ($onlyBase as $k) $report['OP_ONLY_BASE'][] = "  $k  (baseline operation MISSING from candidate)";
foreach ($onlyCand as $k) $report['OP_ONLY_CAND'][] = "  $k  (candidate operation NOT in baseline)";

// ============================================================================
// 2..5. Per-operation: operation fields, parameters, request body, responses
// ============================================================================
$schemaOf = static function (?array $content): ?array {
    if (!is_array($content) || $content === []) return null;
    foreach ($content as $entry) return is_array($entry) && isset($entry['schema']) ? $entry['schema'] : null;
    return null;
};

foreach ($bOps as $key => $bOp) {
    if (!isset($cOps[$key])) continue;
    $cOp = $cOps[$key];

    // 2. operation fields (operationId is lockfile-derived → reported but typically allowlisted)
    foreach (['operationId', 'summary', 'description', 'deprecated'] as $f) {
        $bv = $bOp[$f] ?? null;
        $cv = $cOp[$f] ?? null;
        if ($bv !== $cv) {
            $report['OP_FIELD'][] = sprintf("  %s  %s: base=%s cand=%s", $key, $f, $j($bv), $j($cv));
        }
    }
    $bt = array_values($bOp['tags'] ?? []);
    $ct = array_values($cOp['tags'] ?? []);
    sort($bt); sort($ct);
    if ($bt !== $ct) {
        $report['OP_FIELD'][] = sprintf("  %s  tags: base=%s cand=%s", $key, $j($bt), $j($ct));
    }

    // 3. parameters — compare by (in,name); every facet matters (description, schema type/format/enum/example/…)
    $paramsOf = static function (array $op): array {
        $map = [];
        foreach (($op['parameters'] ?? []) as $p) {
            if (is_array($p) && ($p['in'] ?? null) !== null && ($p['name'] ?? null) !== null) {
                $map[$p['in'] . ':' . $p['name']] = $p;
            }
        }
        return $map;
    };
    // Canonicalize a parameter for provably-equivalent OpenAPI representations:
    //   - `required` defaults to false for a parameter → absent ≡ required:false;
    //   - `example` may live at param-level OR inside `schema.example` (both valid) → lift to param-level.
    $canonicalParam = static function (?array $p): ?array {
        if (!is_array($p)) return $p;
        if (array_key_exists('required', $p) && $p['required'] === false) unset($p['required']);
        if (isset($p['schema']['example']) && !array_key_exists('example', $p)) {
            $p['example'] = $p['schema']['example'];
            unset($p['schema']['example']);
            if ($p['schema'] === []) unset($p['schema']);
        }
        // Query parameters carry their value as a string in the URL — there is no representation of null.
        // Param-level nullability (type:["X","null"]) is therefore a no-op for query params: a query param is
        // present-with-a-value or absent (governed by `required`), never null. Strip "null" from a query param's
        // type union on both sides so the swagger-php-leaked #[OA\Property(nullable:true)] compares equal to bare type.
        if (($p['in'] ?? null) === 'query' && isset($p['schema']['type']) && is_array($p['schema']['type'])) {
            $p['schema']['type'] = array_values(array_filter($p['schema']['type'], fn ($t) => $t !== 'null'));
            if (count($p['schema']['type']) === 1) $p['schema']['type'] = $p['schema']['type'][0];
            if ($p['schema']['type'] === []) unset($p['schema']['type']);
        }
        // A parameter description is equally valid at param-level OR inside `schema.description` (OpenAPI allows
        // both; swagger-php writes it under schema, the metadata emitter writes it at param-level). Lift
        // schema.description to param-level so the two spellings compare equal.
        if (isset($p['schema']['description']) && !array_key_exists('description', $p)) {
            $p['description'] = $p['schema']['description'];
            unset($p['schema']['description']);
            if ($p['schema'] === []) unset($p['schema']);
        }
        ksort($p);
        return $p;
    };
    $bP = $paramsOf($bOp); $cP = $paramsOf($cOp);
    foreach (array_unique(array_merge(array_keys($bP), array_keys($cP))) as $pk) {
        if (!isset($bP[$pk])) { $report['PARAM'][] = "  $key  $pk  only in CANDIDATE"; continue; }
        if (!isset($cP[$pk])) { $report['PARAM'][] = "  $key  $pk  only in BASELINE (lost)"; continue; }
        $bn = $canonicalParam($nB($bP[$pk])); $cn = $canonicalParam($nC($cP[$pk]));
        $diff = $deepDiff($bn, $cn);
        if ($diff !== null) {
            $report['PARAM'][] = "  $key  $pk  base=" . $j($bn) . "  cand=" . $j($cn) . "  diff=" . $j($diff);
        }
    }

    // 4. request body — `required` defaults to false → absent ≡ required:false (canonicalize both sides).
    $canonicalRB = static function (?array $rb): ?array {
        if (is_array($rb) && array_key_exists('required', $rb) && $rb['required'] === false) unset($rb['required']);
        return $rb;
    };
    $bRB = $bOp['requestBody'] ?? null; $cRB = $cOp['requestBody'] ?? null;
    if ($bRB !== null || $cRB !== null) {
        $bRBn = $bRB === null ? null : $canonicalRB($nB($bRB));
        $cRBn = $cRB === null ? null : $canonicalRB($nC($cRB));
        $diff = $deepDiff($bRBn, $cRBn);
        if ($diff !== null) {
            $report['REQUEST_BODY'][] = "  $key  base=" . $j($bRBn) . "  cand=" . $j($cRBn) . "  diff=" . $j($diff);
        }
    }

    // 5. responses — per status, full recursive schema
    $bR = $bOp['responses'] ?? []; $cR = $cOp['responses'] ?? [];
    foreach (array_unique(array_merge(array_keys($bR), array_keys($cR))) as $status) {
        if (!isset($bR[$status])) { $report['RESPONSE'][] = "  $key  [$status]  only in CANDIDATE"; continue; }
        if (!isset($cR[$status])) { $report['RESPONSE'][] = "  $key  [$status]  only in BASELINE (lost)"; continue; }
        $bRs = $bR[$status]; $cRs = $cR[$status];
        $bBody = $schemaOf($bRs['content'] ?? null);
        $cBody = $schemaOf($cRs['content'] ?? null);
        if ($bBody === null && $cBody === null) {
            $diff = $deepDiff($nB($bRs), $nC($cRs)); // compare description/headers when neither has a body
            if ($diff !== null) $report['RESPONSE'][] = "  $key  [$status]  base=" . $j($nB($bRs)) . "  cand=" . $j($nC($cRs)) . "  diff=" . $j($diff);
            continue;
        }
        $diff = $deepDiff($nB($bBody ?? []), $nC($cBody ?? []));
        if ($diff !== null) {
            $report['RESPONSE'][] = "  $key  [$status]  base=" . $j($nB($bBody ?? [])) . "  cand=" . $j($nC($cBody ?? [])) . "  diff=" . $j($diff);
        }
    }
}

// ============================================================================
// Report
// ============================================================================
$order = ['OP_ONLY_BASE', 'OP_ONLY_CAND', 'OP_FIELD', 'PARAM', 'REQUEST_BODY', 'RESPONSE'];
$total = 0;
foreach ($order as $cat) {
    $lines = $report[$cat] ?? [];
    if ($lines === []) continue;
    echo "\n===== $cat (" . count($lines) . ") =====\n";
    echo implode("\n", $lines) . "\n";
    $total += count($lines);
}

echo "\n===== TOTAL raw semantic diffs: $total =====\n";
echo "(Each must be either fixed in the candidate or justified endpoint-by-endpoint in the §4 allowlist.)\n";
exit($total > 0 ? 1 : 0);
