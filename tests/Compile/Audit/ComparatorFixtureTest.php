<?php

declare(strict_types=1);

/**
 * Step 9 pass-3: pins the STRICT semantic comparator (docs/metadata_compiler_audit/semantic_diff_audit.php) against
 * 15 in-memory fixture pairs + the §1.1 same-name-component regression. Each fixture is a minimal baseline vs
 * candidate OpenAPI document; the assertions encode exactly which diffs the comparator MUST find (and, for the
 * negative cases, must NOT find). This is the unit-level proof that the pass-2 false negatives are gone.
 */

require_once dirname(__DIR__, 3) . '/tests/bootstrap.php';
define('AUDIT_COMPARATOR_AS_LIBRARY', true);
require_once dirname(__DIR__, 3) . '/docs/metadata_compiler_audit/semantic_diff_audit.php';
// The comparator declares no namespace — semantic_diff()/apply_allowlist() are global.

/** Build a one-operation document with given components.schemas. */
function cmp_doc(array $op, array $schemas = []): array
{
    return [
        'paths' => ['/x' => ['get' => $op]],
        'components' => ['schemas' => $schemas],
    ];
}

/** Count diffs of a category, optionally at a pointer. */
function cmp_count(array $diffs, string $cat, ?string $pointer = null): int
{
    $n = 0;
    foreach ($diffs as $d) {
        if ($d['category'] === $cat && ($pointer === null || $d['pointer'] === $pointer)) {
            $n++;
        }
    }
    return $n;
}

// ----------------------------------------------------------------------------
// 1. Same-name component changed (§1.1 regression) — baseline UserDto.id=string vs candidate =integer. Both sides
//    $ref UserDto; the comparator MUST resolve each against its OWN components and find the diff.
// ----------------------------------------------------------------------------
$base = cmp_doc(
    ['responses' => ['200' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/UserDto']]]]]],
    ['UserDto' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]]],
);
$cand = cmp_doc(
    ['responses' => ['200' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/UserDto']]]]]],
    ['UserDto' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]]],
);
$d = semantic_diff($base, $cand);
assert_same(1, count($d), 'fixture1: same-name component change is found');
assert_same('RESPONSE', $d[0]['category'], 'fixture1: diff is a RESPONSE');
assert_same('responses.200', $d[0]['pointer'], 'fixture1: diff points at the response');

// ----------------------------------------------------------------------------
// 2. $ref sibling changed — description sibling differs; the underlying component is identical.
// ----------------------------------------------------------------------------
$schemas = ['X' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]]];
$base = cmp_doc(['responses' => ['200' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/X', 'description' => 'a']]]]]], $schemas);
$cand = cmp_doc(['responses' => ['200' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/X', 'description' => 'b']]]]]], $schemas);
assert_same(1, count(semantic_diff($base, $cand)), 'fixture2: $ref sibling change is found (sibling NOT discarded)');
// negative: identical sibling ⇒ no diff
$cand2 = cmp_doc(['responses' => ['200' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/X', 'description' => 'a']]]]]], $schemas);
assert_same(0, count(semantic_diff($base, $cand2)), 'fixture2-neg: identical $ref sibling ⇒ no diff');

// ----------------------------------------------------------------------------
// 3. Response header lost while the body schema is identical.
// ----------------------------------------------------------------------------
$body = ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]];
$base = cmp_doc(['responses' => ['200' => ['headers' => ['X-Total' => ['description' => 'total', 'schema' => ['type' => 'integer']]], 'content' => ['application/json' => ['schema' => $body]]]]]);
$cand = cmp_doc(['responses' => ['200' => ['content' => ['application/json' => ['schema' => $body]]]]]);
assert_same(1, cmp_count(semantic_diff($base, $cand), 'RESPONSE', 'responses.200'), 'fixture3: lost header (same body) is a diff');

// ----------------------------------------------------------------------------
// 4. Response description lost while the schema is identical.
// ----------------------------------------------------------------------------
$base = cmp_doc(['responses' => ['200' => ['description' => 'OK', 'content' => ['application/json' => ['schema' => $body]]]]]);
$cand = cmp_doc(['responses' => ['200' => ['description' => 'Different', 'content' => ['application/json' => ['schema' => $body]]]]]);
assert_same(1, cmp_count(semantic_diff($base, $cand), 'RESPONSE', 'responses.200'), 'fixture4: changed description (same schema) is a diff');

// ----------------------------------------------------------------------------
// 5. Media type changed (application/pdf → application/json).
// ----------------------------------------------------------------------------
$base = cmp_doc(['responses' => ['200' => ['content' => ['application/pdf' => ['schema' => ['type' => 'string', 'format' => 'binary']]]]]]);
$cand = cmp_doc(['responses' => ['200' => ['content' => ['application/json' => ['schema' => $body]]]]]);
assert_same(1, cmp_count(semantic_diff($base, $cand), 'RESPONSE', 'responses.200'), 'fixture5: media-type change is a diff');

// ----------------------------------------------------------------------------
// 6. Second content type lost.
// ----------------------------------------------------------------------------
$base = cmp_doc(['responses' => ['200' => ['content' => ['application/json' => ['schema' => $body], 'application/pdf' => ['schema' => ['type' => 'string', 'format' => 'binary']]]]]]);
$cand = cmp_doc(['responses' => ['200' => ['content' => ['application/json' => ['schema' => $body]]]]]);
assert_same(1, cmp_count(semantic_diff($base, $cand), 'RESPONSE', 'responses.200'), 'fixture6: lost second content type is a diff');

// ----------------------------------------------------------------------------
// 7. Effective security changed (operation declares bearer; candidate inherits anonymous root).
// ----------------------------------------------------------------------------
$base = ['paths' => ['/x' => ['get' => ['security' => [['bearerAuth' => []]], 'responses' => ['200' => ['description' => 'ok']]]]], 'components' => ['schemas' => []]];
$cand = ['paths' => ['/x' => ['get' => ['responses' => ['200' => ['description' => 'ok']]]]], 'components' => ['schemas' => []]];
assert_same(1, cmp_count(semantic_diff($base, $cand), 'SECURITY', 'security'), 'fixture7: effective-security change is a diff');

// ----------------------------------------------------------------------------
// 8. Duplicate (in,name) parameter is an audit failure (PARAM_DUP).
// ----------------------------------------------------------------------------
$dupOp = ['parameters' => [['in' => 'query', 'name' => 'x', 'schema' => ['type' => 'string']], ['in' => 'query', 'name' => 'x', 'schema' => ['type' => 'integer']]], 'responses' => ['200' => ['description' => 'ok']]];
$base = ['paths' => ['/x' => ['get' => ['parameters' => [['in' => 'query', 'name' => 'x', 'schema' => ['type' => 'string']]], 'responses' => ['200' => ['description' => 'ok']]]]], 'components' => ['schemas' => []]];
$cand = ['paths' => ['/x' => ['get' => $dupOp]], 'components' => ['schemas' => []]];
assert_true(cmp_count(semantic_diff($base, $cand), 'PARAM_DUP') >= 1, 'fixture8: duplicate (in,name) parameter is an audit failure');

// ----------------------------------------------------------------------------
// 9. Query nullability changed — type:[string,null] is NOT equivalent to type:string.
// ----------------------------------------------------------------------------
$baseOp = ['parameters' => [['in' => 'query', 'name' => 'x', 'schema' => ['type' => ['string', 'null']]]], 'responses' => ['200' => ['description' => 'ok']]];
$candOp = ['parameters' => [['in' => 'query', 'name' => 'x', 'schema' => ['type' => 'string']]], 'responses' => ['200' => ['description' => 'ok']]];
assert_same(1, cmp_count(semantic_diff(cmp_doc($baseOp), cmp_doc($candOp)), 'PARAM', 'parameters.query.x'), 'fixture9: query nullability change is a diff (not auto-equated)');

// ----------------------------------------------------------------------------
// 10. items-only ({items:…}) is NOT equivalent to a typed array ({type:array,items:…}).
// ----------------------------------------------------------------------------
$baseOp = ['parameters' => [['in' => 'query', 'name' => 'x', 'schema' => ['items' => ['type' => 'integer']]]], 'responses' => ['200' => ['description' => 'ok']]];
$candOp = ['parameters' => [['in' => 'query', 'name' => 'x', 'schema' => ['type' => 'array', 'items' => ['type' => 'integer']]]], 'responses' => ['200' => ['description' => 'ok']]];
assert_same(1, cmp_count(semantic_diff(cmp_doc($baseOp), cmp_doc($candOp)), 'PARAM', 'parameters.query.x'), 'fixture10: items-only ≠ typed array');

// ----------------------------------------------------------------------------
// 11. oneOf is NOT equivalent to anyOf even with identical members.
// ----------------------------------------------------------------------------
$members = [['type' => 'object', 'properties' => ['id' => ['type' => 'string']]], ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]]];
$base = cmp_doc(['responses' => ['200' => ['content' => ['application/json' => ['schema' => ['oneOf' => $members]]]]]]);
$cand = cmp_doc(['responses' => ['200' => ['content' => ['application/json' => ['schema' => ['anyOf' => $members]]]]]]);
assert_same(1, cmp_count(semantic_diff($base, $cand), 'RESPONSE', 'responses.200'), 'fixture11: oneOf ≠ anyOf (same members)');

// ----------------------------------------------------------------------------
// 12. Stale allowlist entry — an entry with no matching actual diff ⇒ stale=1.
// ----------------------------------------------------------------------------
$cleanBase = cmp_doc(['responses' => ['200' => ['description' => 'ok']]]);
$staleEntry = [['category' => 'RESPONSE', 'method_path' => 'GET /x', 'pointer' => 'responses.200', 'baseline_hash' => 'deadbeef', 'candidate_hash' => 'feedface', 'rule' => 'G', 'proof' => 'none']];
$gate = apply_allowlist(semantic_diff($cleanBase, $cleanBase), $staleEntry, null);
assert_same(1, count($gate['stale']), 'fixture12: stale allowlist entry detected');
assert_same(0, $gate['matched'], 'fixture12: nothing matched');

// ----------------------------------------------------------------------------
// 13. One diff matching two allowlist rules (two entries with the same key) ⇒ duplicate.
// ----------------------------------------------------------------------------
$diffs13 = semantic_diff($base, $cand); // fixture11's oneOf/anyOf single diff
$one = $diffs13[0];
$twoEntries = [
    ['category' => $one['category'], 'method_path' => $one['method_path'], 'pointer' => $one['pointer'], 'baseline_hash' => $one['baseline_hash'], 'candidate_hash' => $one['candidate_hash'], 'rule' => 'G', 'proof' => 'r1'],
    ['category' => $one['category'], 'method_path' => $one['method_path'], 'pointer' => $one['pointer'], 'baseline_hash' => $one['baseline_hash'], 'candidate_hash' => $one['candidate_hash'], 'rule' => 'G', 'proof' => 'r2'],
];
$gate = apply_allowlist($diffs13, $twoEntries, null);
assert_same(1, count($gate['duplicates']), 'fixture13: one diff matching two rules ⇒ duplicate_matches=1');

// ----------------------------------------------------------------------------
// 14. Unresolvable ref on one side (candidate references a component it lacks) ⇒ diff.
// ----------------------------------------------------------------------------
$base = cmp_doc(
    ['responses' => ['200' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Missing']]]]]],
    ['Missing' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]]],
);
$cand = cmp_doc(
    ['responses' => ['200' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Missing']]]]]],
    [], // Missing absent on the candidate side
);
assert_same(1, cmp_count(semantic_diff($base, $cand), 'RESPONSE', 'responses.200'), 'fixture14: one-side-unresolvable ref is a diff');

// ----------------------------------------------------------------------------
// 15. Exact clean match ⇒ 0 diffs.
// ----------------------------------------------------------------------------
$identical = cmp_doc(['responses' => ['200' => ['description' => 'ok', 'content' => ['application/json' => ['schema' => $body]]]]], ['X' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]]]);
assert_same(0, count(semantic_diff($identical, $identical)), 'fixture15: identical documents ⇒ 0 diffs');

echo "ComparatorFixtureTest passed\n";
