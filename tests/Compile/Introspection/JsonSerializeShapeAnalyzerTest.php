<?php

declare(strict_types=1);

use SpsFW\Core\Compile\Introspection\JsonSerializeShapeAnalyzer;
use SpsFW\Tests\Compile\Introspection\Fixtures\JsChildDto;
use SpsFW\Tests\Compile\Introspection\Fixtures\JsChildPlusDto;
use SpsFW\Tests\Compile\Introspection\Fixtures\JsCondDto;
use SpsFW\Tests\Compile\Introspection\Fixtures\JsDelegateDto;
use SpsFW\Tests\Compile\Introspection\Fixtures\JsDynamicDto;
use SpsFW\Tests\Compile\Introspection\Fixtures\JsLitDto;
use SpsFW\Tests\Compile\Introspection\Fixtures\JsMergeDto;
use SpsFW\Tests\Compile\Introspection\Fixtures\JsOpaqueValueDto;
use SpsFW\Tests\Compile\Introspection\Fixtures\JsSpreadDto;

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once __DIR__ . '/fixtures/JsonSerializeFixture.php';

/**
 * Step 9.5 §2: JsonSerializeShapeAnalyzer — conservative static projection of jsonSerialize() wire shapes.
 * One assertion per pattern: literal array (incl. scalar-value transforms on properties, aliases, literal scalars),
 * the `parent::jsonSerialize() + […]` family (left-wins), array_merge (right-wins), spread, single-hop delegation,
 * and the NON-provable guards (get_object_vars, runtime branching, an opaque service-call value).
 */
$analyzer = new JsonSerializeShapeAnalyzer();
$wireNames = static function (string $class) use ($analyzer): array {
    $p = $analyzer->project($class);
    return array_map(static fn ($k): string => $k->wireName, $p->keys);
};

// LITERAL_ARRAY — exact key set, order preserved; aliases (created/maybe) map to properties; literal scalars typed.
$lit = $analyzer->project(JsLitDto::class);
assert_true($lit->provable, 'LITERAL_ARRAY: provable');
assert_same(['id', 'name', 'created', 'maybe', 'kind', 'count', 'ratio', 'active', 'nothing'], $wireNames(JsLitDto::class), 'LITERAL_ARRAY: exact wire keys in order');
$byWire = [];
foreach ($lit->keys as $k) {
    $byWire[$k->wireName] = $k;
}
assert_same('createdAt', $byWire['created']->propertyName, 'alias `created` maps to the $createdAt property');
assert_same('flag', $byWire['maybe']->propertyName, 'nullsafe transform `maybe` maps to the $flag property');
assert_same('string', $byWire['kind']->literalType, 'literal string value ⇒ literalType string');
assert_same('int', $byWire['count']->literalType, 'literal int value ⇒ literalType int');
assert_same('float', $byWire['ratio']->literalType, 'literal float value ⇒ literalType float');
assert_same('bool', $byWire['active']->literalType, 'literal true ⇒ literalType bool');
assert_same('null', $byWire['nothing']->literalType, 'literal null ⇒ literalType null');
assert_same(null, $byWire['id']->literalType, 'property-backed key has no literalType');

// LITERAL_PLUS_PARENT — `$r = parent::jsonSerialize(); $r += […]; return $r;` (left-wins).
assert_same(['base', 'extra'], $wireNames(JsChildDto::class), 'LITERAL_PLUS_PARENT (+= idiom): parent keys then child additions');
assert_same(['base', 'extra2'], $wireNames(JsChildPlusDto::class), 'LITERAL_PLUS_PARENT (`parent + […]`): parent keys then child additions');

// MERGE_SPREAD — array_merge (right-wins) and spread.
assert_same(['base', 'merged'], $wireNames(JsMergeDto::class), 'array_merge(parent, […]) ⇒ parent keys then additions');
assert_same(['base', 'spread'], $wireNames(JsSpreadDto::class), '[...parent::jsonSerialize(), …] ⇒ parent keys then additions');

// Single-hop delegation — `return $this->entity->jsonSerialize();` resolves to the property's class shape.
assert_same(['entity_id'], $wireNames(JsDelegateDto::class), 'delegation: resolves to the property type\'s jsonSerialize() keys');

// NON-provable: get_object_vars($this) — dynamic key set.
assert_true(!$analyzer->project(JsDynamicDto::class)->provable, 'get_object_vars($this) ⇒ NOT provable (dynamic key set)');
assert_true($analyzer->project(JsDynamicDto::class)->reason !== null, 'unprovable projection carries a reason');

// NON-provable: a runtime branch (if) choosing/augmenting keys.
assert_true(!$analyzer->project(JsCondDto::class)->provable, 'a runtime branch in the body ⇒ NOT provable');

// NON-provable: an opaque service-call value (a method on $this, not a property transform).
assert_true(!$analyzer->project(JsOpaqueValueDto::class)->provable, 'an opaque value (arbitrary method call) ⇒ NOT provable (no exhaustive schema)');

echo "JsonSerialize shape analyzer passed\n";
