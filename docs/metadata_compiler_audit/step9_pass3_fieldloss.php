<?php

/**
 * Dev-only Step 9 pass-3 FIELD-LOSS scanner (NOT a committed test). For every RESPONSE (non-error) and REQUEST_BODY
 * diff, recursively collects the property-name sets from baseline and candidate schemas and reports where they
 * diverge — surfacing REAL losses (candidate missing/wrong fields vs the baseline contract) vs legitimate residuals
 * (candidate ⊇ baseline modulo non-contractual hints). This is the "fix only the real losses it detects" instrument.
 *
 *   php step9_pass3_fieldloss.php [mode]
 *      mode = lost   (only candidate-missing-baseline-field cases)  [default]
 *      mode = all    (every divergence with both deltas)
 */

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

ini_set('memory_limit', '2048M');
require dirname(__DIR__, 2) . '/vendor/autoload.php';
define('AUDIT_COMPARATOR_AS_LIBRARY', true);
require __DIR__ . '/semantic_diff_audit.php';

$mode = $argv[1] ?? 'lost';

$base = '/tmp/p3_base.yml';
$cand = '/tmp/p3_cand.yml';
if (!is_file($base) || !is_file($cand)) {
    fwrite(STDERR, "run step9_pass3_analyze.php first to build /tmp/p3_base.yml + /tmp/p3_cand.yml\n");
    exit(2);
}

/** Recursively collect every property NAME reachable in a schema. Only `properties` is a name→schema map; `items`
 *  and union members are SINGLE schemas (recurse, do NOT collect their keys, which are schema keywords). */
$keywords = array_flip(['title', 'description', 'format', 'example', 'required', 'properties', 'items', 'schema',
    'enum', 'type', 'oneOf', 'anyOf', 'allOf', 'additionalProperties', '$ref', 'nullable', 'default', 'minimum',
    'maximum', 'minLength', 'maxLength', 'minItems', 'maxItems', 'pattern', 'exclusiveMinimum', 'exclusiveMaximum',
    'readOnly', 'writeOnly', 'deprecated', 'xml', 'externalDocs', 'discriminator']);
$fieldNames = static function (mixed $s) use (&$fieldNames, $keywords): array {
    $names = [];
    if (!is_array($s)) {
        return $names;
    }
    if (isset($s['properties']) && is_array($s['properties'])) {
        foreach ($s['properties'] as $name => $child) {
            if (is_string($name) && !isset($keywords[$name])) {
                $names[$name] = true;
            }
            if (is_array($child)) {
                foreach ($fieldNames($child) as $cn => $_) {
                    $names[$cn] = true;
                }
            }
        }
    }
    if (isset($s['items']) && is_array($s['items'])) {
        foreach ($fieldNames($s['items']) as $cn => $_) {
            $names[$cn] = true;
        }
    }
    foreach (['oneOf', 'anyOf', 'allOf'] as $k) {
        if (isset($s[$k]) && is_array($s[$k])) {
            foreach ($s[$k] as $child) {
                if (is_array($child)) {
                    foreach ($fieldNames($child) as $cn => $_) {
                        $names[$cn] = true;
                    }
                }
            }
        }
    }
    return $names;
};

/** Walk into a response/requestBody fragment to its first media-type schema (handles $ref-unresolved too). */
$schemaOf = static function (mixed $frag): mixed {
    if (!is_array($frag)) {
        return null;
    }
    // requestBody or response: content -> first media type -> schema
    $content = $frag['content'] ?? ($frag['schema'] ?? null);
    if (is_array($content)) {
        foreach ($content as $entry) {
            if (is_array($entry) && isset($entry['schema'])) {
                return $entry['schema'];
            }
        }
    }
    return null;
};

$diffs = semantic_diff(Yaml::parseFile($base), Yaml::parseFile($cand));
$rows = array_values(array_filter($diffs, fn ($x) => in_array($x['category'], ['RESPONSE', 'REQUEST_BODY'], true) && !$x['candidate_is_error']));

$report = [];
foreach ($rows as $x) {
    $bSchema = $schemaOf($x['baseline_fragment']);
    $cSchema = $schemaOf($x['candidate_fragment']);
    $bFields = array_keys($fieldNames($bSchema));
    $cFields = array_keys($fieldNames($cSchema));
    sort($bFields); sort($cFields);
    $bOnly = array_values(array_diff($bFields, $cFields));
    $cOnly = array_values(array_diff($cFields, $bFields));
    if ($bOnly === [] && $cOnly === []) {
        continue; // same field set — only descriptions/examples/format differ (non-contractual)
    }
    if ($mode === 'lost' && $bOnly === []) {
        continue; // candidate strictly adds fields (baseline-incomplete) — not a loss
    }
    $report[] = ['mp' => $x['method_path'], 'ptr' => $x['pointer'], 'status' => $x['status'], 'cat' => $x['category'], 'baseline_only' => $bOnly, 'candidate_only' => $cOnly];
}

echo "field-set divergences (" . count($report) . " of " . count($rows) . " non-error RESPONSE/REQUEST_BODY diffs)\n\n";
foreach ($report as $r) {
    echo "• [{$r['cat']}] {$r['mp']} @ {$r['ptr']}" . ($r['status'] ? " status={$r['status']}" : "") . "\n";
    if ($r['baseline_only'] !== []) { echo "    baseline-ONLY (candidate LOST): " . implode(', ', $r['baseline_only']) . "\n"; }
    if ($r['candidate_only'] !== []) { echo "    candidate-only (candidate ADDED): " . implode(', ', $r['candidate_only']) . "\n"; }
}
