<?php

declare(strict_types=1);

use SpsFW\Core\Compile\Route\RuleGraphParity;

require_once dirname(__DIR__, 2) . '/bootstrap.php';

/**
 * Step 7 (M5): the pure parity comparator that backs the Metadata-mode gate. The DECISION is strict `===` (PHP array
 * identity: same keys, same ORDER, same value types, recursively); {@see RuleGraphParity::compare()} only LOCALIZES
 * the first divergence for the blocking diagnostic. These cases pin every divergence kind the gate must surface.
 */

// ============================================================================
// Identical graphs (incl. nested_rules) ⇒ null — parity holds, the gate stays silent.
// ============================================================================
assert_same(null, RuleGraphParity::compare([], []), 'two empty graphs are identical');
assert_same(
    null,
    RuleGraphParity::compare(['email' => ['type' => 'string', 'required' => [true]]], ['email' => ['type' => 'string', 'required' => [true]]]),
    'identical scalar graphs ⇒ null',
);
assert_same(
    null,
    RuleGraphParity::compare(
        ['child' => ['ref' => 'X', 'nested_rules' => ['tag' => ['type' => 'string']]]],
        ['child' => ['ref' => 'X', 'nested_rules' => ['tag' => ['type' => 'string']]]],
    ),
    'identical nested graphs ⇒ null',
);

// ============================================================================
// A property present in legacy only (metadata dropped it) — kind + the legacy side.
// ============================================================================
$diff = RuleGraphParity::compare(['a' => ['type' => 'string']], []);
assert_true($diff !== null, 'a legacy-only property is a divergence');
assert_same('a', $diff['path'], 'the legacy-only property path is reported');
assert_same('present-in-legacy-only', $diff['kind'], 'kind is present-in-legacy-only');
assert_same(['type' => 'string'], $diff['legacy'], 'the legacy side is carried');
assert_same(null, $diff['metadata'], 'the metadata side is null');

// ============================================================================
// A property present in metadata only (legacy lacks it).
// ============================================================================
$diff = RuleGraphParity::compare([], ['a' => ['type' => 'string']]);
assert_true($diff !== null, 'a metadata-only property is a divergence');
assert_same('a', $diff['path'], 'the metadata-only property path is reported');
assert_same('present-in-metadata-only', $diff['kind'], 'kind is present-in-metadata-only');
assert_same(['type' => 'string'], $diff['metadata'], 'the metadata side is carried');

// ============================================================================
// A scalar value differs (e.g. minimum: 1 vs 2).
// ============================================================================
$diff = RuleGraphParity::compare(['age' => ['type' => 'integer', 'minimum' => 1]], ['age' => ['type' => 'integer', 'minimum' => 2]]);
assert_same('age.minimum', $diff['path'], 'the diverging scalar is pinpointed to its key path');
assert_same('value-differs', $diff['kind'], 'kind is value-differs');
assert_same(1, $diff['legacy'], 'legacy minimum carried');
assert_same(2, $diff['metadata'], 'metadata minimum carried');

// ============================================================================
// TYPE pinning: required stored as the ARRAY [true] vs the BOOL true is a DIVERGENCE (the Validator contract
// checks `$rules['required'] !== [true]` — a bool would break runtime validation). The gate must catch it.
// ============================================================================
$diff = RuleGraphParity::compare(['x' => ['required' => [true]]], ['x' => ['required' => true]]);
assert_true($diff !== null, 'required:[true] (array) vs required:true (bool) is a divergence');
assert_same('x.required', $diff['path'], 'the type mismatch is pinpointed');
assert_same('value-differs', $diff['kind'], 'a type mismatch is a value-differs');

// int vs string type difference on the same key
$diff = RuleGraphParity::compare(['x' => ['minimum' => 1]], ['x' => ['minimum' => '1']]);
assert_true($diff !== null, 'int 1 vs string "1" is a divergence (strict types)');

// ============================================================================
// Nested divergence: recurse into nested_rules to pinpoint the leaf (not just the top-level property).
// ============================================================================
$diff = RuleGraphParity::compare(
    ['child' => ['ref' => 'X', 'nested_rules' => ['tag' => ['type' => 'string'], 'n' => ['type' => 'integer']]]],
    ['child' => ['ref' => 'X', 'nested_rules' => ['tag' => ['type' => 'string'], 'n' => ['type' => 'string']]]],
);
assert_same('child.nested_rules.n.type', $diff['path'], 'the nested leaf divergence is pinpointed through nested_rules');
assert_same('value-differs', $diff['kind'], 'the nested divergence kind is value-differs');

// a nested property present in legacy nested_rules only
$diff = RuleGraphParity::compare(
    ['child' => ['ref' => 'X', 'nested_rules' => ['tag' => ['type' => 'string']]]],
    ['child' => ['ref' => 'X', 'nested_rules' => []]],
);
assert_same('child.nested_rules.tag', $diff['path'], 'a missing nested property is localized to its nested path');

// ============================================================================
// Key ORDER differs (same keys + values, different order) — `===` is order-sensitive, so this is a real parity break
// (var_export byte-parity depends on key order). The comparator reports it rather than returning null.
// ============================================================================
$diff = RuleGraphParity::compare(
    ['a' => ['type' => 'string'], 'b' => ['type' => 'integer']],
    ['b' => ['type' => 'integer'], 'a' => ['type' => 'string']],
);
assert_true($diff !== null, 'same keys+values but different order is a divergence');
assert_same('order-differs', $diff['kind'], 'a top-level order difference is reported as order-differs');

// nested rule-map key order differs (e.g. default/real_name emitted in a different sequence)
$diff = RuleGraphParity::compare(
    ['x' => ['default' => 'd', 'real_name' => 'x', 'type' => 'string']],
    ['x' => ['real_name' => 'x', 'default' => 'd', 'type' => 'string']],
);
assert_true($diff !== null, 'a different key order inside a property rule map is a divergence');
assert_same('x', $diff['path'], 'the order divergence is attributed to its property');
assert_same('order-differs', $diff['kind'], 'the inner order difference is order-differs');

// ============================================================================
// The first divergence wins (the comparator returns the FIRST one, not all) — deterministic reporting.
// ============================================================================
$diff = RuleGraphParity::compare(
    ['a' => ['type' => 'string'], 'b' => ['type' => 'integer']],
    ['a' => ['type' => 'integer'], 'b' => ['type' => 'string']],
);
assert_same('a.type', $diff['path'], 'the FIRST divergence (a.type) is reported when several exist');

echo "RuleGraphParity passed\n";
