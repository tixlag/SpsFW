<?php

declare(strict_types=1);

use OpenApi\Attributes as OA;
use SpsFW\Core\Compile\Introspection\DtoSchemaBuilder;
use SpsFW\Core\Router\Router;

require_once dirname(__DIR__, 2) . '/bootstrap.php';

/**
 * Step 2 (M2) parity proof: DtoSchemaBuilder::ruleGraph() must reproduce
 * Router::extractValidationRules() BYTE-FOR-BYTE (plan §14/§15, Step 2 criterion).
 *
 * The single parity assertion per DTO —
 *     $builder->ruleGraph($builder->build($dto))->rules === extractValidationRules($dto)
 * — is strict (===), so it pins key ORDER and value types, exactly what var_export byte-parity
 * in the route cache requires. Direct shape assertions are added only to document intent.
 *
 * Fixture coverage mirrors the §14 matrix + RuleGraphExtractionCharacterizationTest:
 *  - aliased/nullable/default scalar, min/max constraints, required:[true]/[false]/absent
 *  - nested ref (class-typed), array+ref collection, items: Items(ref) collection
 *  - promoted ctor param (registered once), non-promoted ctor param, default precedence
 *  - union/nullable builtin, no-default, plain property without #[OA\Property]
 */

// --- nested DTO shared by ref/collection/items cases ---
final class DsbNestedDto
{
    #[OA\Property(type: 'string')]
    public string $label;

    #[OA\Property(type: 'integer', minimum: 0)]
    public int $count;
}

// --- the comprehensive scalar+ref+collection fixture ---
final class DsbFixtureDto
{
    #[OA\Property(property: 'aliased', type: 'string', nullable: true, default: 'def')]
    public string $renamed;

    #[OA\Property(type: 'integer', minimum: 1, maximum: 100)]
    public int $age;

    #[OA\Property(property: 'req_true', required: [true])]
    public string $explicitlyRequired;

    #[OA\Property(property: 'req_false', required: [false])]
    public string $explicitlyNotRequired;

    #[OA\Property(ref: DsbNestedDto::class)]
    public DsbNestedDto $child;

    #[OA\Property(property: 'children', ref: DsbNestedDto::class, type: 'array')]
    public array $children;

    #[OA\Property(property: 'list', items: new OA\Items(ref: DsbNestedDto::class))]
    public array $list;

    /** union builtin type — reflectionClassName yields null ⇒ treated as a scalar, no ref */
    #[OA\Property(property: 'union', type: 'string')]
    public int|string $union;

    #[OA\Property(property: 'plain', type: 'string')]
    public string $plainNoConstraints;

    public string $noAttribute; // must NOT appear in the graph
}

// --- promoted ctor param: registered exactly once, ctor default beats OA default ---
final class DsbPromotedDto
{
    public function __construct(
        #[OA\Property(property: 'promoted', type: 'string', default: 'oa')] public string $promoted = 'prom',
    ) {}
}

// --- non-promoted ctor param: dedicated branch, param default beats OA default ---
final class DsbNonPromotedCtorDto
{
    public string $unused;

    public function __construct(
        #[OA\Property(property: 'np', type: 'string', default: 'oa')] string $np = 'npdef',
    ) {
        $this->unused = $np;
    }
}

// --- default precedence: property literal default > OA default; none ⇒ no `default` key ---
final class DsbPropDefaultDto
{
    #[OA\Property(property: 'a', type: 'string', default: 'oa')]
    public string $a = 'prop';
}

final class DsbNoDefaultDto
{
    #[OA\Property(property: 'b', type: 'string')]
    public string $b;
}

// --- DTO with NO #[OA\Property] at all ⇒ empty graph ---
final class DsbEmptyDto
{
    public string $nothing;
}

$router = (new ReflectionClass(Router::class))->newInstanceWithoutConstructor();
$extract = new ReflectionMethod(Router::class, 'extractValidationRules');
$builder = new DtoSchemaBuilder();

/**
 * The core parity oracle: the builder's rule graph must be strictly identical to the legacy producer's.
 */
$assertParity = static function (string $dtoClass, string $label) use ($router, $extract, $builder): void {
    $expected = $extract->invoke($router, $dtoClass);
    $actual = $builder->ruleGraph($builder->build($dtoClass))->rules;
    assert_same($expected, $actual, $label . ' — ruleGraph() byte-identical to extractValidationRules()');
};

// ============================================================================
// Parity: every representative DTO reproduces the legacy producer byte-for-byte.
// ============================================================================
$assertParity(DsbFixtureDto::class, 'DsbFixtureDto (scalar/ref/collection/items/union)');
$assertParity(DsbPromotedDto::class, 'DsbPromotedDto (promoted ctor param)');
$assertParity(DsbNonPromotedCtorDto::class, 'DsbNonPromotedCtorDto (non-promoted ctor param)');
$assertParity(DsbPropDefaultDto::class, 'DsbPropDefaultDto (property-default precedence)');
$assertParity(DsbNoDefaultDto::class, 'DsbNoDefaultDto (no default ⇒ no default key)');
$assertParity(DsbEmptyDto::class, 'DsbEmptyDto (no #[OA\Property] ⇒ empty graph)');

// ============================================================================
// Targeted shape assertions (documentation + regression tripwires).
// ============================================================================
$fixture = $builder->build(DsbFixtureDto::class);

// nested recursion reproduces the nested DTO's own rules, not just a ref marker
$graph = $builder->ruleGraph($fixture)->rules;
assert_same(
    'string',
    $graph['child']['nested_rules']['label']['type'],
    'nested ref recurses into the referenced DTO (nested_rules populated)',
);
assert_same(
    'array',
    $graph['children']['type'],
    'array+ref collection is typed as array',
);
assert_same(
    'array',
    $graph['list']['type'],
    'items: Items(ref) collection is typed as array (items branch)',
);
assert_true(
    !array_key_exists('noAttribute', $graph),
    'a plain property without #[OA\Property] is absent from the rule graph',
);
assert_same(
    [],
    $builder->ruleGraph($builder->build(DsbEmptyDto::class))->rules,
    'DTO with no OA properties yields an empty rule graph',
);

// ============================================================================
// Schema projection (Step 2 captures it; Step 4 consumes it) — basic shape locks.
// ============================================================================
$renamed = $fixture->property('renamed');
assert_same('renamed', $renamed->name, 'PropertyMetadata.name is the PHP property name');
assert_same('aliased', $renamed->serialName(), 'serialName() is the OA `property` arg');
assert_same('string', $renamed->phpType, 'phpType reflects the PHP type');
assert_true($renamed->hasDefault, 'hasDefault set when an OA `default` arg is present');
assert_same('def', $renamed->defaultValue, 'defaultValue carries the resolved default');

$child = $fixture->property('child');
assert_same(DsbNestedDto::class, $child->refClass, 'refClass is the reflection-derived nested class');
assert_same(DsbNestedDto::class, $child->ref, 'ref follows refClass (reflection wins over OA ref)');

$plain = $fixture->property('plainNoConstraints');
assert_same('plain', $plain->serialName(), 'serialName falls back to the PHP name when no OA `property` arg');

// ============================================================================
// Memoization: build() returns the SAME instance for a repeated FQCN (plan §17).
// ============================================================================
assert_same(
    $fixture,
    $builder->build(DsbFixtureDto::class),
    'build() is memoized — repeated FQCN returns the same SchemaMetadata instance',
);

echo "DtoSchemaBuilder parity + projection passed\n";
