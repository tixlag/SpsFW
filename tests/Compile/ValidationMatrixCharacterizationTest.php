<?php

declare(strict_types=1);

use SpsFW\Core\Exceptions\ValidationException;
use SpsFW\Core\Validation\Validator;

require_once dirname(__DIR__) . '/bootstrap.php';

/**
 * Characterization (Шаг 0, §14): pins the EXACT behaviour of the cached-rule
 * validation consumer that the future compile-time compiler MUST stay compatible with.
 *
 * Target: Validator::validateDtoWithCachedRules() (private static) — the runtime path
 * used when route cache `dtos[*].rules` is present (Router.php:1054).
 *
 * `required` is represented as the ARRAY [true] (see framework's own
 * src/Core/Auth/Dto/AccessRulesDto.php: `required: [true]`). The check is
 * `$rules['required'] !== [true]` (Validator.php:77) and `$ruleValue[0] === true`
 * (Validator.php:305).
 *
 * These tests must keep passing unchanged through M2/M5/M8 — they are the contract.
 */
final class MatrixFixtureDto
{
    public mixed $reqOnly;
    public mixed $optOnly;
    public mixed $nullableStr;
    public mixed $typedInt;
    public mixed $enumField;
    public mixed $uuidField;
}

function matrixExpectValidationException(callable $fn, string $message): void
{
    try {
        $fn();
    } catch (ValidationException $e) {
        return; // expected
    } catch (\Throwable $e) {
        $class = $e::class;
        assert_true(false, $message . " — threw {$class} instead of ValidationException: {$e->getMessage()}");
        return;
    }
    assert_true(false, $message . ' — expected ValidationException was NOT thrown');
}

$validate = new ReflectionMethod(Validator::class, 'validateDtoWithCachedRules');

// Ruleset A — isolates the `required` quirks (only one field is processed).
$requiredRules = ['reqOnly' => ['real_name' => 'reqOnly', 'required' => [true]]];

$dto = $validate->invoke(null, MatrixFixtureDto::class, ['reqOnly' => 'x'], $requiredRules);
assert_same('x', $dto->reqOnly, 'required field accepts a non-empty string');

$dto = $validate->invoke(null, MatrixFixtureDto::class, ['reqOnly' => 0], $requiredRules);
assert_same(0, $dto->reqOnly, 'int 0 does NOT trigger required (rawValue !== 0 is false)');

$dto = $validate->invoke(null, MatrixFixtureDto::class, ['reqOnly' => []], $requiredRules);
assert_same([], $dto->reqOnly, 'empty array does NOT trigger required (rawValue !== [] is false)');

matrixExpectValidationException(
    fn () => $validate->invoke(null, MatrixFixtureDto::class, ['reqOnly' => '0'], $requiredRules),
    "string '0' DOES trigger required (empty('0') is true and '0' !== 0 strict)"
);
matrixExpectValidationException(
    fn () => $validate->invoke(null, MatrixFixtureDto::class, ['reqOnly' => false], $requiredRules),
    'bool false DOES trigger required (empty(false) and false !== 0 strict)'
);
matrixExpectValidationException(
    fn () => $validate->invoke(null, MatrixFixtureDto::class, [], $requiredRules),
    'missing required+non-nullable field triggers required (null falls through to validateOpenApi)'
);
matrixExpectValidationException(
    fn () => $validate->invoke(null, MatrixFixtureDto::class, ['reqOnly' => null], $requiredRules),
    'explicit null on required+non-nullable behaves like missing'
);

// Ruleset B — no required field, so missing values never short-circuit the case under test.
$otherRules = [
    'optOnly'     => ['real_name' => 'optOnly'],
    'nullableStr' => ['real_name' => 'nullableStr', 'required' => [true], 'nullable' => true, 'type' => 'string'],
    'typedInt'    => ['real_name' => 'typedInt', 'type' => 'integer'],
    'enumField'   => ['real_name' => 'enumField', 'enum' => ['A', 'B']],
    'uuidField'   => ['real_name' => 'uuidField', 'format' => 'uuid'],
];

// nullable overrides required
$dto = $validate->invoke(null, MatrixFixtureDto::class, [], $otherRules);
assert_same(null, $dto->nullableStr, 'required+nullable field with missing value resolves to null (no throw)');

// optional field: missing -> default/null, no throw
$dto = $validate->invoke(null, MatrixFixtureDto::class, [], $otherRules);
assert_same(null, $dto->optOnly, 'optional field with missing value resolves to null');

// type coercion (integer)
$dto = $validate->invoke(null, MatrixFixtureDto::class, ['typedInt' => '5'], $otherRules);
assert_same(5, $dto->typedInt, "numeric string '5' coerced to int 5");
matrixExpectValidationException(
    fn () => $validate->invoke(null, MatrixFixtureDto::class, ['typedInt' => 'abc'], $otherRules),
    'non-numeric string for integer field throws'
);

// enum (strict in_array)
$dto = $validate->invoke(null, MatrixFixtureDto::class, ['enumField' => 'A'], $otherRules);
assert_same('A', $dto->enumField, 'enum accepts a listed value');
matrixExpectValidationException(
    fn () => $validate->invoke(null, MatrixFixtureDto::class, ['enumField' => 'C'], $otherRules),
    'enum rejects an unlisted value'
);
$dto = $validate->invoke(null, MatrixFixtureDto::class, [], $otherRules);
assert_same(null, $dto->enumField, 'optional enum field with missing value resolves to null');

// format uuid
$dto = $validate->invoke(null, MatrixFixtureDto::class, ['uuidField' => '550e8400-e29b-41d4-a716-446655440000'], $otherRules);
assert_same('550e8400-e29b-41d4-a716-446655440000', $dto->uuidField, 'uuid format accepts a valid uuid');
matrixExpectValidationException(
    fn () => $validate->invoke(null, MatrixFixtureDto::class, ['uuidField' => 'not-a-uuid'], $otherRules),
    'uuid format rejects an invalid uuid'
);

// ============================================================================
// Consumer-side contracts (Шаг 0, item 3): default application, nested/collection
// DTO consumers, enum-ref (single + collection). All via the SAME private path
// (validateDtoWithCachedRules) that consumes route cache `dtos[*].rules`.
// ============================================================================

final class MatrixConsumerDto
{
    public mixed $def;
    public mixed $inner;
    public mixed $items;
    public mixed $mode;
    public mixed $tags;
}

final class MatrixNestedDto
{
    public mixed $label;
}

enum MatrixFixtureEnum: string
{
    case Foo = 'foo';
    case Bar = 'bar';
}

// default application: missing value + rules['default'] => default applied (Validator.php:79)
$dto = $validate->invoke(null, MatrixConsumerDto::class, [], ['def' => ['real_name' => 'def', 'default' => 'fallback']]);
assert_same('fallback', $dto->def, 'missing value WITH rules default => default applied via setPropertyValue');

// missing value + no default key => null ($rules['default'] ?? null)
$dto = $validate->invoke(null, MatrixConsumerDto::class, [], ['def' => ['real_name' => 'def']]);
assert_same(null, $dto->def, 'missing value WITHOUT default key => null');

// nested DTO consumer (ref + nested_rules, non-array) — Validator.php:106-113
$nestedRules = ['inner' => ['real_name' => 'inner', 'ref' => MatrixNestedDto::class, 'nested_rules' => ['label' => ['real_name' => 'label', 'type' => 'string']]]];
$dto = $validate->invoke(null, MatrixConsumerDto::class, ['inner' => ['label' => 'x']], $nestedRules);
assert_true($dto->inner instanceof MatrixNestedDto, 'nested ref resolves to a nested DTO instance');
assert_same('x', $dto->inner->label, 'nested DTO instance is recursively validated/populated');

// collection DTO consumer (ref + type:array + nested_rules) — Validator.php:88-105
$collectionRules = ['items' => ['real_name' => 'items', 'type' => 'array', 'ref' => MatrixNestedDto::class, 'nested_rules' => ['label' => ['real_name' => 'label', 'type' => 'string']]]];
$dto = $validate->invoke(null, MatrixConsumerDto::class, ['items' => [['label' => 'a'], ['label' => 'b']]], $collectionRules);
assert_true(is_array($dto->items) && count($dto->items) === 2, 'collection ref resolves to an array of nested DTO instances');
assert_true($dto->items[0] instanceof MatrixNestedDto && $dto->items[1] instanceof MatrixNestedDto, 'each collection element is a nested DTO instance');
assert_same(['a', 'b'], [$dto->items[0]->label, $dto->items[1]->label], 'collection elements recursively validated, order preserved');
matrixExpectValidationException(
    fn () => $validate->invoke(null, MatrixConsumerDto::class, ['items' => 'not-array'], $collectionRules),
    'non-array value for a collection ref throws before iteration'
);

// enum-ref collection (ref to a backed enum + type:array) — Validator.php:94 + 66-71
$enumRules = ['tags' => ['real_name' => 'tags', 'type' => 'array', 'ref' => MatrixFixtureEnum::class, 'nested_rules' => []]];
$dto = $validate->invoke(null, MatrixConsumerDto::class, ['tags' => ['foo', 'bar']], $enumRules);
assert_same([MatrixFixtureEnum::Foo, MatrixFixtureEnum::Bar], $dto->tags, 'enum-ref collection maps each scalar via Enum::from()');

// enum-ref single (ref to a backed enum, non-array)
$enumSingleRules = ['mode' => ['real_name' => 'mode', 'ref' => MatrixFixtureEnum::class, 'nested_rules' => []]];
$dto = $validate->invoke(null, MatrixConsumerDto::class, ['mode' => 'foo'], $enumSingleRules);
assert_same(MatrixFixtureEnum::Foo, $dto->mode, 'enum-ref single maps scalar via Enum::from()');
$dto = $validate->invoke(null, MatrixConsumerDto::class, [], $enumSingleRules);
assert_same(null, $dto->mode, 'enum-ref single with missing value => null (no Enum::from on null)');

echo "Validation matrix characterization passed\n";
