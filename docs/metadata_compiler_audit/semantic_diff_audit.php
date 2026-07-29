<?php

declare(strict_types=1);

/**
 * Dev-only STRICT semantic comparator — Step 9 corrective pass 3 (spec §1/§2).
 *
 * A baseline-vs-candidate OpenAPI structural comparator that reports EVERY semantic difference and (optionally)
 * enforces a machine-readable allowlist. It replaces the rejected pass-2 comparator, which had false negatives:
 *
 *   FN1 (side-blind $ref): both sides resolved a `$ref` against candidate-then-baseline components, masking a
 *       same-named component that changed (e.g. UserDto.id string vs integer compared equal). HERE: a baseline
 *       `$ref` resolves ONLY against baseline components; a candidate `$ref` ONLY against candidate components.
 *       An unresolved `$ref` is its own diff. `$ref` siblings (description/nullable/example/…) are preserved by
 *       overlaying them on the resolved component. Cycle breaking is per-side.
 *   FN2 (partial response): only the first media-type schema was compared, so a lost header, a changed
 *       description, a media-type swap (application/pdf → application/json) or a dropped second content type were
 *       invisible. HERE: the WHOLE response object (description/headers/links/every content type/each media-type
 *       schema/examples/encoding) is compared per status.
 *   FN3 (op-contract gaps): security/servers/externalDocs were not compared. HERE: the full operation contract is
 *       compared — operationId/summary/description/tags/deprecated/EFFECTIVE security (root overridden by op)/
 *       servers/externalDocs/parameters/requestBody/responses.
 *   FN4 (parameter false equivalences + silent dedup): param description↔schema.description and param example↔
 *       schema.example were lifted to equivalence; query nullability was stripped; `{items}` without `type:array`
 *       was equated to a real array; `oneOf`↔`anyOf` were never the focus; duplicate `(in,name)` params silently
 *       overwrote each other in a map. HERE: none of those are equated; a duplicate `(in,name)` is an AUDIT FAILURE.
 *
 * The ONLY normalizations applied are PROVABLY-equivalent REPRESENTATION forms (order/degenerate), each documented:
 *   (N1) object key order → sorted;
 *   (N2) `required` → values sorted, empty `required:[]` dropped (degenerate — required is a set);
 *   (N3) `enum` → values sorted (enum is a set);
 *   (N4) `oneOf`/`anyOf` member lists → sorted by canonical member (alternative order within ONE union keyword is
 *        not semantic; the keyword itself — oneOf vs anyOf — is NEVER changed);
 *   (N5) side-specific `$ref` → component resolution with sibling overlay + per-side cycle break.
 * Everything else — additionalProperties, nullable vs type:[T,"null"], items-without-type:array, param-level vs
 * schema-level description/example, format/default/min/max/example, content types, headers — is a REAL diff.
 *
 * Allowlist (§2): when `--allowlist=<file>` is passed, the comparator matches every actual diff against the
 * machine-readable allowlist by the EXACT key (category · METHOD:path · JSON pointer · baseline-fragment hash ·
 * candidate-fragment hash). It succeeds ONLY when unexplained=0 AND stale_allowlist=0 AND duplicate_matches=0 AND
 * every diff is matched exactly once. R1 (StandardErrorPolicy) entries are re-validated against the policy sidecar
 * (--policy) and the comparator-computed flags. A markdown report is generated from the machine result.
 *
 * Usage:
 *   php semantic_diff_audit.php <baseline.yml> <candidate.yml> [--dump=out.json]
 *   php semantic_diff_audit.php <baseline.yml> <candidate.yml> --allowlist=al.json [--policy=policy.json]
 *
 * This file is ALSO a library: tests/Compile/Audit/ComparatorFixtureTest.php includes it and calls semantic_diff()
 * and apply_allowlist() on in-memory documents.
 */

use Symfony\Component\Yaml\Yaml;

// ============================================================================
// Library bootstrap: PREFER THE LOCAL vendor (this repo's own, which has
// symfony/yaml AND maps `SpsFW\` -> `src/`). A CONSUMER project's autoload also
// registers a `SpsFW\` PSR-4 rule pointing at an OLDER packaged copy of the
// framework; loading it in-process SHADOWS `src/` for any framework class not yet
// loaded (Router, Route, …), which corrupts compile tests run in the same process
// (test-isolation bug). The consumer autoload is a LAST-RESORT fallback only for
// runs from a checkout without a local vendor.
// ============================================================================
(function (): void {
    if (class_exists(Yaml::class, false)) {
        return; // already loaded (e.g. tests/bootstrap.php pulled in the local vendor)
    }
    $local = dirname(__DIR__, 2) . '/vendor/autoload.php';
    if (is_file($local)) {
        require $local;
        return;
    }
    foreach ([
        dirname(__DIR__, 3) . '/.wt/lk-step6b/next/vendor/autoload.php',
        dirname(__DIR__, 3) . '/lk.sps38.pro/next/vendor/autoload.php',
    ] as $candidate) {
        if (is_file($candidate)) {
            require $candidate;
            return;
        }
    }
})();
if (!class_exists(Yaml::class)) {
    fwrite(STDERR, "symfony/yaml not found — run from a checkout whose vendor has it\n");
    exit(2);
}

// ============================================================================
// Core normalization + hashing primitives
// ============================================================================

function audit_shortref(string $ref): string
{
    // #/components/schemas/Foo → Foo
    return substr(strrchr('/' . $ref, '/'), 1);
}

/**
 * Canonical JSON for hashing/sorting: sorted keys (the normalizer already sorts), unicode/slashes unescaped.
 * NOTE: must be deterministic across runs — booleans/integers stay native; we do not cast.
 */
function audit_canon(mixed $v): string
{
    return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function audit_hash(mixed $normalized): string
{
    return hash('sha256', audit_canon($normalized));
}

/**
 * Side-specific recursive normalization. `$comp` is THIS SIDE's components.schemas only; `$seen` is this side's
 * active resolution stack (per-side cycle break). Siblings of a `$ref` are overlaid on the resolved component
 * (OpenAPI 3.1 allows `$ref` siblings). An unresolved `$ref` keeps its name + siblings so a one-side-unresolved
 * ref still surfaces as a diff.
 */
function audit_normalize(mixed $s, array $seen, ?array $comp): mixed
{
    if (!is_array($s)) {
        return $s;
    }
    if (array_key_exists('$ref', $s) && is_string($s['$ref'])) {
        $name = audit_shortref($s['$ref']);
        $siblings = array_diff_key($s, ['$ref' => true]);
        if (isset($seen[$name])) {
            return ['_cycle' => $name]; // per-side cycle break
        }
        if (is_array($comp) && array_key_exists($name, $comp)) {
            $resolved = $comp[$name];
            $merged = is_array($resolved) ? array_merge($resolved, $siblings) : $siblings; // siblings override
            return audit_normalize($merged, $seen + [$name => true], $comp);
        }
        // unresolved on this side: keep the name + siblings so a mismatch against the other (resolved) side shows
        $out = ['_unresolved_ref' => $name];
        foreach ($siblings as $k => $v) {
            $out[$k] = audit_normalize($v, $seen, $comp);
        }
        ksort($out);
        return $out;
    }
    $out = [];
    foreach ($s as $k => $v) {
        if ($k === 'required' && is_array($v)) {
            $vals = array_values(array_unique($v));
            sort($vals);
            if ($vals !== []) {
                $out['required'] = $vals; // (N2) degenerate empty required dropped
            }
            continue;
        }
        if ($k === 'enum' && is_array($v)) {
            $normed = array_map(static fn ($e) => is_array($e) ? audit_normalize($e, $seen, $comp) : $e, array_values($v));
            usort($normed, static fn ($a, $b): int => audit_canon($a) <=> audit_canon($b)); // (N3)
            $out['enum'] = $normed;
            continue;
        }
        if (($k === 'oneOf' || $k === 'anyOf') && is_array($v) && array_is_list($v)) {
            $normed = array_map(static fn ($m): mixed => audit_normalize($m, $seen, $comp), array_values($v));
            usort($normed, static fn ($a, $b): int => audit_canon($a) <=> audit_canon($b)); // (N4)
            $out[$k] = $normed;
            continue;
        }
        $out[$k] = is_array($v) ? audit_normalize($v, $seen, $comp) : $v;
    }
    ksort($out); // (N1)
    return $out;
}

/**
 * Effective security for an operation: operation-level `security` overrides the root `security` when present
 * (even an empty operation `security: []` means "no auth"). Absent operation security inherits the root.
 */
function audit_effective_security(array $op, mixed $rootSecurity): array
{
    $sec = array_key_exists('security', $op) ? $op['security'] : $rootSecurity;
    if (!is_array($sec)) {
        return [];
    }
    $normed = [];
    foreach ($sec as $req) {
        $normed[] = audit_normalize($req, [], null);
    }
    usort($normed, static fn ($a, $b): int => audit_canon($a) <=> audit_canon($b)); // security alternatives: order-insensitive
    return $normed;
}

function audit_tags(array $op): array
{
    $t = array_values($op['tags'] ?? []);
    sort($t);
    return $t;
}

/**
 * A response body is "empty/unconstrained" (R1 baseline condition) when it has no content, empty content, or every
 * media type's schema imposes no constraint (`{}`, `{description:…}`, bare `{type:object}`, or bare `{type:array}`
 * with no items).
 */
function audit_body_unconstrained(mixed $resp): bool
{
    if (!is_array($resp)) {
        return true;
    }
    $content = $resp['content'] ?? null;
    if (!is_array($content) || $content === []) {
        return true;
    }
    foreach ($content as $entry) {
        $schema = $entry['schema'] ?? null;
        if (!audit_schema_unconstrained($schema)) {
            return false;
        }
    }
    return true;
}

function audit_schema_unconstrained(mixed $s): bool
{
    if (!is_array($s) || $s === []) {
        return true;
    }
    $keys = array_values(array_diff(array_keys($s), ['description']));
    if ($keys === []) {
        return true;
    }
    if (count($keys) === 1 && ($s['type'] ?? null) === 'object') {
        return true;
    }
    if (count($keys) === 1 && ($s['type'] ?? null) === 'array') {
        return true;
    }
    return false;
}

/** The candidate body is the canonical StandardErrorPolicy Error envelope iff it is a `$ref` to the `Error` schema. */
function audit_body_is_error(mixed $resp): bool
{
    if (!is_array($resp)) {
        return false;
    }
    $content = $resp['content'] ?? null;
    if (!is_array($content) || $content === []) {
        return false;
    }
    foreach ($content as $entry) {
        $schema = $entry['schema'] ?? null;
        if (is_array($schema) && isset($schema['$ref']) && audit_shortref($schema['$ref']) === 'Error') {
            return true;
        }
    }
    return false;
}

// ============================================================================
// Operation index
// ============================================================================

function audit_ops_of(array $doc): array
{
    static $http = ['get', 'post', 'put', 'patch', 'delete', 'options', 'head', 'trace'];
    $out = [];
    foreach (($doc['paths'] ?? []) as $path => $methods) {
        if (!is_array($methods)) {
            continue;
        }
        foreach ($methods as $method => $op) {
            if (in_array(strtolower((string) $method), $http, true) && is_array($op)) {
                $key = strtoupper($method) . ' ' . $path;
                $out[$key] = ['method' => strtoupper($method), 'path' => $path, 'op' => $op];
            }
        }
    }
    return $out;
}

/**
 * Parameters by (in,name). Returns ['map' => [...], 'duplicates' => [['op_key'=>…,'in'=>…,'name'=>…], …]].
 * A duplicate (in,name) within one operation is an audit failure (the comparator cannot pick one reliably).
 */
function audit_params_of(string $opKey, array $op): array
{
    $map = [];
    $duplicates = [];
    foreach (($op['parameters'] ?? []) as $p) {
        if (!is_array($p) || ($p['in'] ?? null) === null || ($p['name'] ?? null) === null) {
            continue;
        }
        $pk = $p['in'] . ':' . $p['name'];
        if (isset($map[$pk])) {
            $duplicates[] = ['op_key' => $opKey, 'in' => $p['in'], 'name' => $p['name']];
        }
        $map[$pk] = $p; // last-wins; duplicates are reported separately as PARAM_DUP
    }
    return ['map' => $map, 'duplicates' => $duplicates];
}

// ============================================================================
// Core: compute every semantic diff between two documents.
// Returns list<diff-record>. A record's MATCH KEY is category·method_path·pointer·baseline_hash·candidate_hash.
// ============================================================================

function semantic_diff(array $base, array $cand): array
{
    $bComp = $base['components']['schemas'] ?? [];
    $cComp = $cand['components']['schemas'] ?? [];
    $bRootSec = $base['security'] ?? null;
    $cRootSec = $cand['security'] ?? null;
    $nB = static fn ($s): mixed => audit_normalize($s, [], $bComp);
    $nC = static fn ($s): mixed => audit_normalize($s, [], $cComp);

    $records = [];
    $rec = static function (string $cat, array $bInfo, string $pointer, mixed $bNorm, mixed $cNorm, array $extra = []) use (&$records): void {
        $bh = $bNorm === null ? 'absent' : audit_hash($bNorm);
        $ch = $cNorm === null ? 'absent' : audit_hash($cNorm);
        if ($bh === $ch) {
            return; // identical normalized fragment — no diff
        }
        $records[] = array_merge([
            'category' => $cat,
            'method' => $bInfo['method'] ?? null,
            'path' => $bInfo['path'] ?? null,
            'method_path' => $bInfo['key'] ?? null,
            'pointer' => $pointer,
            'baseline_hash' => $bh,
            'candidate_hash' => $ch,
            'baseline_fragment' => $bNorm,
            'candidate_fragment' => $cNorm,
            'status' => null,
            'candidate_is_error' => false,
            'baseline_empty' => false,
        ], $extra);
    };

    $bOps = audit_ops_of($base);
    $cOps = audit_ops_of($cand);

    // ---- operation set ----
    foreach (array_diff(array_keys($bOps), array_keys($cOps)) as $key) {
        $info = $bOps[$key];
        $rec('OP_ONLY_BASE', $info + ['key' => $key], '@operation', $nB($info['op']), null, ['baseline_empty' => false]);
    }
    foreach (array_diff(array_keys($cOps), array_keys($bOps)) as $key) {
        $info = $cOps[$key];
        $rec('OP_ONLY_CAND', $info + ['key' => $key], '@operation', null, $nC($info['op']));
    }

    // ---- per-operation contract ----
    foreach ($bOps as $key => $b) {
        if (!isset($cOps[$key])) {
            continue;
        }
        $c = $cOps[$key];
        $info = ['method' => $b['method'], 'path' => $b['path'], 'key' => $key];
        $bOp = $b['op'];
        $cOp = $c['op'];

        // scalar op fields
        foreach (['operationId', 'summary', 'description', 'deprecated'] as $f) {
            $bv = array_key_exists($f, $bOp) ? $bOp[$f] : null;
            $cv = array_key_exists($f, $cOp) ? $cOp[$f] : null;
            if ($bv !== $cv) {
                $rec('OP_FIELD', $info, $f, $nB($bv), $nC($cv));
            }
        }
        // tags (set order)
        $bt = audit_tags($bOp);
        $ct = audit_tags($cOp);
        if ($bt !== $ct) {
            $rec('OP_FIELD', $info, 'tags', $nB($bt), $nC($ct));
        }
        // servers / externalDocs if present on either side
        foreach (['servers', 'externalDocs'] as $f) {
            if (array_key_exists($f, $bOp) || array_key_exists($f, $cOp)) {
                $rec('OP_FIELD', $info, $f, $nB($bOp[$f] ?? null), $nC($cOp[$f] ?? null));
            }
        }
        // effective security (root overridden by op)
        $bSec = audit_effective_security($bOp, $bRootSec);
        $cSec = audit_effective_security($cOp, $cRootSec);
        if (audit_canon($bSec) !== audit_canon($cSec)) {
            $rec('SECURITY', $info, 'security', $bSec, $cSec);
        }

        // parameters (duplicate detection is an audit failure)
        $bP = audit_params_of($key, $bOp);
        $cP = audit_params_of($key, $cOp);
        foreach (array_merge($bP['duplicates'], $cP['duplicates']) as $dup) {
            $records[] = [
                'category' => 'PARAM_DUP', 'method' => $info['method'], 'path' => $info['path'], 'method_path' => $key,
                'pointer' => 'parameters.' . $dup['in'] . '.' . $dup['name'],
                'baseline_hash' => 'duplicate', 'candidate_hash' => 'duplicate',
                'baseline_fragment' => null, 'candidate_fragment' => null,
                'status' => null, 'candidate_is_error' => false, 'baseline_empty' => false,
            ];
        }
        foreach (array_unique(array_merge(array_keys($bP['map']), array_keys($cP['map']))) as $pk) {
            [$in, $name] = explode(':', $pk, 2);
            $bp = $bP['map'][$pk] ?? null;
            $cp = $cP['map'][$pk] ?? null;
            $rec('PARAM', $info, 'parameters.' . $in . '.' . $name, $bp === null ? null : $nB($bp), $cp === null ? null : $nC($cp));
        }

        // requestBody (whole)
        $bRb = array_key_exists('requestBody', $bOp) ? $bOp['requestBody'] : null;
        $cRb = array_key_exists('requestBody', $cOp) ? $cOp['requestBody'] : null;
        if ($bRb !== null || $cRb !== null) {
            $rec('REQUEST_BODY', $info, 'requestBody', $bRb === null ? null : $nB($bRb), $cRb === null ? null : $nC($cRb));
        }

        // responses (whole, per status)
        $bRs = $bOp['responses'] ?? [];
        $cRs = $cOp['responses'] ?? [];
        foreach (array_unique(array_merge(array_keys($bRs), array_keys($cRs))) as $status) {
            $bPresent = isset($bRs[$status]);
            $cPresent = isset($cRs[$status]);
            $bRaw = $bPresent ? $bRs[$status] : null;
            $cRaw = $cPresent ? $cRs[$status] : null;
            $rec('RESPONSE', $info, 'responses.' . $status, $bPresent ? $nB($bRaw) : null, $cPresent ? $nC($cRaw) : null, [
                'status' => (string) $status,
                'candidate_is_error' => audit_body_is_error($cRaw),
                'baseline_empty' => !$bPresent || audit_body_unconstrained($bRaw),
            ]);
        }
    }

    return $records;
}

// ============================================================================
// Machine-allowlist matcher (§2). Succeeds ONLY when all gates are zero.
// ============================================================================

/**
 * @param array<int, array> $diffs       output of semantic_diff()
 * @param array<int, array> $allowlist   entries: {category, method_path, pointer, baseline_hash, candidate_hash, rule, proof}
 * @param array|null        $policy      { "METHOD /path": ["400","401",...] } from the runner's policy sidecar
 */
function apply_allowlist(array $diffs, array $allowlist, ?array $policy): array
{
    $policyStatuses = static function (array $d) use ($policy): bool {
        if ($policy === null) {
            return true; // no sidecar: cannot disqualify R1 — trust the comparator flags only
        }
        $set = $policy[$d['method_path']] ?? null;
        return is_array($set) && in_array((string) $d['status'], array_map('strval', $set), true);
    };

    $actualKey = static fn (array $d, bool $withRule = false): string =>
        $d['category'] . "\x1f" . $d['method_path'] . "\x1f" . $d['pointer'] . "\x1f" . $d['baseline_hash'] . "\x1f" . $d['candidate_hash'];

    // group actuals + entries by key
    $actualByKey = [];
    foreach ($diffs as $d) {
        $actualByKey[$actualKey($d)][] = $d;
    }
    $entryByKey = [];
    foreach ($allowlist as $e) {
        $ek = ($e['category'] ?? '') . "\x1f" . ($e['method_path'] ?? '') . "\x1f" . ($e['pointer'] ?? '') . "\x1f" . ($e['baseline_hash'] ?? '') . "\x1f" . ($e['candidate_hash'] ?? '');
        $entryByKey[$ek][] = $e;
    }

    $unexplained = [];
    $stale = [];
    $duplicates = [];
    $matched = 0;
    $perRule = [];

    $allKeys = array_unique(array_merge(array_keys($actualByKey), array_keys($entryByKey)));
    foreach ($allKeys as $k) {
        $actuals = $actualByKey[$k] ?? [];
        $entries = $entryByKey[$k] ?? [];
        // R1 condition gate: an actual only "counts" as matchable if every R1 entry targeting it holds its conditions.
        $r1Valid = true;
        foreach ($entries as $e) {
            if (($e['rule'] ?? '') !== 'R1') {
                continue;
            }
            foreach ($actuals as $d) {
                if (!($d['candidate_is_error'] && $d['baseline_empty'] && $policyStatuses($d))) {
                    $r1Valid = false; // the R1 entry's proof conditions do not hold for this actual diff
                }
            }
        }
        $a = $r1Valid ? count($actuals) : 0;
        $l = count($entries);
        if ($a >= 1 && $l >= 1) {
            $matched += min($a, $l);
            $rule = ($entries[0]['rule'] ?? '?');
            $perRule[$rule] = ($perRule[$rule] ?? 0) + min($a, $l);
            if (count($actuals) > 1 || count($entries) > 1) {
                $duplicates[] = ['key' => $k, 'actuals' => count($actuals), 'entries' => count($entries)];
            }
        }
        for ($i = 0; $i < count($actuals) - min($a, $l); $i++) {
            $unexplained[] = $actuals[$i];
        }
        for ($i = 0; $i < count($entries) - min($a, $l); $i++) {
            $stale[] = $entries[$i];
        }
        // When an R1 entry's proof conditions do not hold, $a collapses to 0 above, so the two loops already push
        // every actual → unexplained and every entry → stale. (No separate R1 branch — it would double-count.)
    }

    return [
        'total_diffs' => count($diffs),
        'matched' => $matched,
        'unexplained' => $unexplained,
        'stale' => $stale,
        'duplicates' => $duplicates,
        'per_rule' => $perRule,
    ];
}

// ============================================================================
// CLI — runs only when this file is executed directly, NOT when included as a library (the fixture test sets the
// AUDIT_COMPARATOR_AS_LIBRARY sentinel before requiring this file).
// ============================================================================

if (defined('AUDIT_COMPARATOR_AS_LIBRARY')) {
    return;
}

(function (): void {
    global $argv;
    $positional = [];
    $allowlistPath = null;
    $policyPath = null;
    $dumpPath = null;
    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--allowlist=')) {
            $allowlistPath = substr($arg, strlen('--allowlist='));
        } elseif (str_starts_with($arg, '--policy=')) {
            $policyPath = substr($arg, strlen('--policy='));
        } elseif (str_starts_with($arg, '--dump=')) {
            $dumpPath = substr($arg, strlen('--dump='));
        } else {
            $positional[] = $arg;
        }
    }
    if (count($positional) < 2) {
        fwrite(STDERR, "usage: semantic_diff_audit.php <baseline.yml> <candidate.yml> [--allowlist=al.json] [--policy=policy.json] [--dump=out.json]\n");
        exit(2);
    }
    [$baselinePath, $candidatePath] = $positional;
    foreach ([$baselinePath, $candidatePath] as $p) {
        if (!is_file($p)) {
            fwrite(STDERR, "not a file: $p\n");
            exit(2);
        }
    }

    $base = Yaml::parseFile($baselinePath);
    $cand = Yaml::parseFile($candidatePath);
    $diffs = semantic_diff(is_array($base) ? $base : [], is_array($cand) ? $cand : []);

    if ($dumpPath !== null) {
        file_put_contents($dumpPath, json_encode(['baseline' => $baselinePath, 'candidate' => $candidatePath, 'diffs' => $diffs], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    // category counts (exact)
    $byCat = [];
    foreach ($diffs as $d) {
        $byCat[$d['category']] = ($byCat[$d['category']] ?? 0) + 1;
    }
    $order = ['OP_ONLY_BASE', 'OP_ONLY_CAND', 'PARAM_DUP', 'OP_FIELD', 'SECURITY', 'PARAM', 'REQUEST_BODY', 'RESPONSE'];
    echo "===== raw semantic diffs (" . count($diffs) . ") =====\n";
    foreach ($order as $cat) {
        if (($byCat[$cat] ?? 0) > 0) {
            echo sprintf("  %-14s %d\n", $cat, $byCat[$cat]);
        }
    }

    if ($allowlistPath !== null) {
        $allowlist = json_decode((string) file_get_contents($allowlistPath), true);
        if (!is_array($allowlist)) {
            fwrite(STDERR, "allowlist not valid JSON: $allowlistPath\n");
            exit(2);
        }
        $policy = null;
        if ($policyPath !== null && is_file($policyPath)) {
            $policy = json_decode((string) file_get_contents($policyPath), true);
        }
        $gate = apply_allowlist($diffs, $allowlist, is_array($policy) ? $policy : null);
        echo "\n===== allowlist gate =====\n";
        echo "  total_diffs   = " . $gate['total_diffs'] . "\n";
        echo "  matched       = " . $gate['matched'] . "\n";
        echo "  unexplained   = " . count($gate['unexplained']) . "\n";
        echo "  stale_allowlist = " . count($gate['stale']) . "\n";
        echo "  duplicate_matches = " . count($gate['duplicates']) . "\n";
        if (!empty($gate['per_rule'])) {
            echo "  per_rule:\n";
            foreach ($gate['per_rule'] as $rule => $n) {
                echo sprintf("    %-10s %d\n", $rule, $n);
            }
        }
        $ok = count($gate['unexplained']) === 0 && count($gate['stale']) === 0 && count($gate['duplicates']) === 0;
        echo "\n===== RESULT: " . ($ok ? 'PASS' : 'FAIL') . " =====\n";
        exit($ok ? 0 : 1);
    }

    exit(count($diffs) > 0 ? 1 : 0);
})();
