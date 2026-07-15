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

echo "Validation matrix characterization passed\n";
