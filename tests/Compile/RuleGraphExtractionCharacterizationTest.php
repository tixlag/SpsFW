<?php

declare(strict_types=1);

use OpenApi\Attributes as OA;
use SpsFW\Core\Router\Router;

require_once dirname(__DIR__) . '/bootstrap.php';

/**
 * Characterization (Шаг 0, §3): pins the OUTPUT SHAPE of the current OA-coupled
 * rule-graph producer — Router::extractValidationRules() (private, Router.php:345).
 *
 * The future DtoSchemaBuilder::ruleGraph() (M2 parity) must reproduce this output
 * byte-for-byte before the OA path is removed (M8).
 *
 * Key characterized facts:
 *  - rule KEY = OA `property` arg when present, else PHP property name;
 *    `real_name` always = PHP property name.
 *  - `required` is NOT auto-derived from PHP types (grep confirms no `required` logic
 *    in Router); it is copied VERBATIM only when passed as an OA arg, as the ARRAY [true]/[false]
 *    (see framework's own src/Core/Auth/Dto/AccessRulesDto.php).
 *  - class-typed / ref / items+array properties emit `ref` + `nested_rules` (recursive).
 *  - whitelisted scalar rules (type, nullable, minimum, ...) are passed through verbatim.
 */
final class RuleGraphNestedDto
{
    #[OA\Property(type: 'string')]
    public string $label;
}

final class RuleGraphFixtureDto
{
    #[OA\Property(property: 'aliased', type: 'string', nullable: true, default: 'def')]
    public string $renamed;

    #[OA\Property(type: 'integer', minimum: 1, maximum: 100)]
    public int $age;

    #[OA\Property(property: 'required_field', required: [true])]
    public string $explicitlyRequired;

    #[OA\Property(property: 'not_required_field', required: [false])]
    public string $explicitlyNotRequired;

    #[OA\Property(ref: RuleGraphNestedDto::class)]
    public RuleGraphNestedDto $child;

    #[OA\Property(property: 'children', ref: RuleGraphNestedDto::class, type: 'array')]
    public array $children;
}

$router = (new ReflectionClass(Router::class))->newInstanceWithoutConstructor();
$extract = new ReflectionMethod(Router::class, 'extractValidationRules');
$rules = $extract->invoke($router, RuleGraphFixtureDto::class);

// --- key naming + real_name + scalar passthrough ---
assert_true(array_key_exists('aliased', $rules), 'rule key uses OA `property` arg when given');
assert_same('renamed', $rules['aliased']['real_name'], 'real_name is the PHP property name, not the alias');
assert_same(true, $rules['aliased']['nullable'], 'nullable passed through verbatim');
assert_same('def', $rules['aliased']['default'], 'default captured from OA `default` arg');
assert_same('string', $rules['aliased']['type'], 'type passed through verbatim');

// --- constraints passthrough ---
assert_same(1, $rules['age']['minimum'], 'minimum passed through verbatim');
assert_same(100, $rules['age']['maximum'], 'maximum passed through verbatim');
assert_same('integer', $rules['age']['type'], 'integer type passed through verbatim');

// --- required is opt-in & verbatim, NOT inferred ---
assert_same([true], $rules['required_field']['required'], 'required:[true] copied verbatim from OA arg');
assert_same([false], $rules['not_required_field']['required'], 'required:[false] copied verbatim from OA arg');
assert_true(
    !array_key_exists('required', $rules['age']),
    'a field with no OA `required` arg has NO required key — producer does NOT auto-derive required'
);

// --- nested ref (single object) ---
assert_same(RuleGraphNestedDto::class, $rules['child']['ref'], 'class-typed property emits ref to the nested class');
assert_true(isset($rules['child']['nested_rules']), 'nested ref emits nested_rules');
assert_same('string', $rules['child']['nested_rules']['label']['type'], 'nested_rules recurse into the referenced DTO');

// --- collection (type: array + ref) ---
assert_same('array', $rules['children']['type'], 'array+ref property is typed as array');
assert_same(RuleGraphNestedDto::class, $rules['children']['ref'], 'collection emits ref to the item class');
assert_true(isset($rules['children']['nested_rules']), 'collection emits nested_rules for the item DTO');

// ============================================================================
// Producer-side contracts (Шаг 0, item 3): promoted property, non-promoted
// constructor parameter with #[OA\Property], and the default-value precedence
// chain (property-default > ctor-param-default > OA-default; null => not emitted).
// All via the SAME private producer (Router::extractValidationRules).
// ============================================================================

// --- promoted constructor parameter: registered ONCE (via getProperties()), ctor default captured ---
final class PromotedDefaultDto
{
    public function __construct(
        #[OA\Property(property: 'promoted', type: 'string', default: 'oa')] public string $promoted = 'prom',
    ) {}
}
$rules = $extract->invoke($router, PromotedDefaultDto::class);
assert_true(array_key_exists('promoted', $rules), 'promoted ctor param with #[OA\Property] is registered as a rule (via getProperties())');
assert_same('promoted', $rules['promoted']['real_name'], 'promoted rule real_name = PHP property name');
assert_same('prom', $rules['promoted']['default'], 'promoted default = ctor-param default (promoted has no property default; ctor default beats OA `default`)');
assert_same('string', $rules['promoted']['type'], 'promoted type passed through');
assert_same(1, count($rules), 'promoted param registered exactly ONCE (the non-promoted ctor loop skips promoted)');

// --- non-promoted constructor parameter with #[OA\Property] (dedicated branch, Router.php:421-481) ---
final class NonPromotedCtorDto
{
    public string $unused;

    public function __construct(
        #[OA\Property(property: 'np', type: 'string', default: 'oa')] string $np = 'npdef',
    ) {
        $this->unused = $np;
    }
}
$rules = $extract->invoke($router, NonPromotedCtorDto::class);
assert_true(array_key_exists('np', $rules), 'non-promoted ctor param with #[OA\Property] is registered (dedicated ctor branch)');
assert_same('np', $rules['np']['real_name'], 'non-promoted rule key uses OA `property` arg, real_name = param name');
assert_same('npdef', $rules['np']['default'], 'non-promoted default = ctor-param default (beats OA `default` arg)');
assert_same('string', $rules['np']['type'], 'non-promoted type passed through');
assert_true(!array_key_exists('unused', $rules), 'plain property without #[OA\Property] is NOT registered');

// --- default precedence: property literal default > OA `default` arg; no default anywhere => no `default` key ---
final class PropDefaultDto
{
    #[OA\Property(property: 'a', type: 'string', default: 'oa')]
    public string $a = 'prop';
}
$rules = $extract->invoke($router, PropDefaultDto::class);
assert_same('prop', $rules['a']['default'], 'default precedence: property literal default beats OA `default` arg');

final class NoDefaultDto
{
    #[OA\Property(property: 'b', type: 'string')]
    public string $b;
}
$rules = $extract->invoke($router, NoDefaultDto::class);
assert_true(!array_key_exists('default', $rules['b']), 'no property/ctor/OA default => NO `default` key emitted (isset(null) is false)');

echo "Rule-graph extraction characterization passed\n";
