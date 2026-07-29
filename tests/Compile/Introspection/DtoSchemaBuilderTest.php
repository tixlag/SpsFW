<?php

declare(strict_types=1);

use OpenApi\Attributes as OA;
use SpsFW\Core\Attributes\OpenApi\Field;
use SpsFW\Core\Attributes\OpenApi\Items;
use SpsFW\Core\Compile\CompileDiagnostics;
use SpsFW\Core\Compile\Introspection\DtoSchemaBuilder;
use SpsFW\Core\Compile\Introspection\RequiredSource;
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

// --- #[Field]/#[Items] schema projection (Step 3 fix-pass): the schema describes the real JSON shape,
//     sourced from EVERY public property and enriched by the new attributes — independent of OA. ---
final class DsbFieldItemsDto
{
    #[Field(format: 'email', min: 1, max: 9, name: 'email_address', readOnly: true)]
    public string $email;

    #[Items(class: DsbNestedDto::class)]
    public array $phones;

    #[Items(type: 'integer')]
    public array $ids;

    public string $noOaNoField; // serializes as its PHP name; appears in the schema even without OA
}

// --- #[Items] must declare exactly one of class|type (diagnostic on both / neither) ---
final class DsbItemsBadDto
{
    #[Items(class: DsbNestedDto::class, type: 'integer')]
    public array $both;

    #[Items]
    public array $neither;
}

// --- cyclic validation graphs (must surface a compile diagnostic, never recurse forever) ---
final class DsbCycleA
{
    #[OA\Property(ref: DsbCycleB::class)]
    public DsbCycleB $b;
}
final class DsbCycleB
{
    #[OA\Property(ref: DsbCycleA::class)]
    public DsbCycleA $a;
}
final class DsbCycleSelf
{
    #[OA\Property(ref: DsbCycleSelf::class)]
    public DsbCycleSelf $next;
}

// --- wire-name precedence + OA description carry (Step 9 pass-3): serialName precedence is
//     #[Field(name)] > OA\Property::property > PHP name; the legacy OA description is carried transitively.
//     `name` is always the PHP property name; only the serial key and the description change. ---
final class DsbWireNameDto
{
    #[OA\Property(property: 'oa_name', description: 'OA-described field', type: 'string')]
    public string $oaOnly;

    #[OA\Property(property: 'oa_loses', type: 'string')]
    #[Field(name: 'field_wins')]
    public string $fieldOverridesOa;

    #[OA\Property(type: 'string')]
    public string $noWireName;
}

$builder = new DtoSchemaBuilder();

/**
 * The core parity oracle: the builder's rule graph must be strictly identical to the legacy producer's.
 * Router::extractValidationRules is now a public static callable (Step 7 — the M5 legacy/parity source).
 */
$assertParity = static function (string $dtoClass, string $label) use ($builder): void {
    $expected = Router::extractValidationRules($dtoClass);
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
// serialName precedence (Step 9 pass-3): #[Field(name)] → legacy OA\Property::property (the runtime input
// hydrator's wire key, also the DB/1C array output key that legacy OpenAPI published to clients) → the PHP
// name ONLY when no wire name is declared anywhere. `name` stays the PHP property name; only the serial key
// changes. Emitting the bare PHP name where the hydrator reads a different key is a hidden breaking change.
// ============================================================================
$renamed = $fixture->property('renamed');
assert_same('renamed', $renamed->name, 'PropertyMetadata.name is the PHP property name');
assert_same('aliased', $renamed->serialName(), 'serialName() honors the OA `property` arg (aliased) — the hydrator wire name — over the PHP name');
assert_same('string', $renamed->phpType, 'phpType reflects the PHP type');
assert_true($renamed->hasDefault, 'hasDefault set when an OA `default` arg is present');
assert_same('def', $renamed->defaultValue, 'defaultValue carries the resolved default');

$child = $fixture->property('child');
assert_same(DsbNestedDto::class, $child->refClass, 'refClass is the reflection-derived nested class');
assert_same(DsbNestedDto::class, $child->ref, 'ref follows refClass (reflection wins over OA ref)');

$plain = $fixture->property('plainNoConstraints');
assert_same('plain', $plain->serialName(), 'serialName() honors the OA `property` arg (plain) over the PHP name when no #[Field(name)] override exists');

// The OA `property` arg is ALSO the rule-graph (hydration) key — serial name and hydration key now BOTH
// honor it (previously the schema serial name diverged to the PHP name, the pass-3-corrected defect).
assert_true(array_key_exists('aliased', $graph), 'the rule graph keys on the OA `property` arg (aliased), not the PHP name');
assert_true(!array_key_exists('renamed', $graph), 'the rule graph never keys on the PHP name when an OA property arg is set');

// ============================================================================
// required source-mode (plan §7): Oa (parity, reads required:[true]) vs PhpType (non-nullable, no default).
// The two sources are never blended. $explicitlyNotRequired is PHP non-nullable with no default but
// OA required:[false] — the divergence point.
// ============================================================================
$reqTrue = $fixture->property('explicitlyRequired');
assert_true($reqTrue->isRequired(RequiredSource::Oa), 'Oa source: OA required:[true] ⇒ required');
assert_true($reqTrue->isRequired(RequiredSource::PhpType), 'PhpType source: non-nullable, no default ⇒ required (agrees with OA here)');
$reqFalse = $fixture->property('explicitlyNotRequired');
assert_true(!$reqFalse->isRequired(RequiredSource::Oa), 'Oa source honours required:[false] even though PHP is non-nullable');
assert_true($reqFalse->isRequired(RequiredSource::PhpType), 'PhpType source ignores OA and derives required from non-nullability');

// ============================================================================
// #[Field]/#[Items] schema projection: constraints + serial-name + item type, on a DTO with NO OA at all.
// ============================================================================
$fi = $builder->build(DsbFieldItemsDto::class);

$email = $fi->property('email');
assert_same('email_address', $email->serialName(), 'Field(name) overrides the JSON serial name');
assert_same('email', $email->format, 'Field(format) populates the schema format');
assert_same(1, $email->minimum, 'Field(min) → PropertyMetadata.minimum');
assert_same(9, $email->maximum, 'Field(max) → PropertyMetadata.maximum');
assert_true($email->readOnly, 'Field(readOnly) projected');

$phones = $fi->property('phones');
assert_same(DsbNestedDto::class, $phones->itemType, 'Items(class) → itemType (the element schema ref)');

$ids = $fi->property('ids');
assert_same('integer', $ids->itemType, 'Items(type) → itemType (the scalar element type)');

$noOa = $fi->property('noOaNoField');
assert_true($noOa !== null, 'a public property without OA/Field still appears in the schema projection');
assert_same('noOaNoField', $noOa->serialName(), 'no Field ⇒ the serial name is the PHP name');
assert_same([], $builder->ruleGraph($builder->build(DsbFieldItemsDto::class))->rules, 'a DTO with no OA yields an empty RULE GRAPH even though its schema is non-empty (the two sets diverge)');

// ============================================================================
// #[Items] exactly-one-of class|type: both / neither each surface a FATAL (structural) diagnostic. The
// `neither` property is also an itemless array ⇒ an additional MIGRATION warning (severity split, Step 4).
// ============================================================================
$itemsDiag = new CompileDiagnostics();
(new DtoSchemaBuilder($itemsDiag))->build(DsbItemsBadDto::class);
assert_same(2, $itemsDiag->errorCount(), 'Items with both / neither class+type each surface a FATAL error');
assert_same('both', $itemsDiag->errors()[0]['field'], 'the both-diagnostic is attributed to its property');
assert_same(1, $itemsDiag->warningCount(), 'the `neither` array is also itemless ⇒ one migration warning');
assert_true(str_contains($itemsDiag->warnings()[0]['cause'], 'no derivable item type'), 'itemless-array warning text');

// ============================================================================
// Memoization: build() returns the SAME instance for a repeated FQCN (plan §17).
// ============================================================================
assert_same(
    $fixture,
    $builder->build(DsbFixtureDto::class),
    'build() is memoized — repeated FQCN returns the same SchemaMetadata instance',
);

// ============================================================================
// Cycle guard (plan §17): a cyclic validation graph is reported as a compile
// diagnostic and halts via throwOnErrors() — it is NEVER silently collapsed to
// empty nested_rules (Router::extractValidationRules would recurse forever).
// ============================================================================

// mutual cycle A↔B
$cycleDiag = new CompileDiagnostics();
$cycleBuilder = new DtoSchemaBuilder($cycleDiag);
$cycleBuilder->ruleGraph($cycleBuilder->build(DsbCycleA::class));
assert_true($cycleDiag->hasErrors(), 'cyclic validation graph (A↔B) is reported as a compile diagnostic');
assert_same(DsbCycleA::class, $cycleDiag->errors()[0]['dto'], 'cycle diagnostic names the cyclic DTO');
assert_true(str_contains($cycleDiag->errors()[0]['cause'], 'cyclic'), 'cycle diagnostic describes the cycle');

// self-cycle
$selfDiag = new CompileDiagnostics();
$selfBuilder = new DtoSchemaBuilder($selfDiag);
$selfBuilder->ruleGraph($selfBuilder->build(DsbCycleSelf::class));
assert_true($selfDiag->hasErrors(), 'a self-referential validation graph is reported as a cycle');

// a DAG (shared leaf, no back-edge) must NOT trip the guard
$dagDiag = new CompileDiagnostics();
$dagBuilder = new DtoSchemaBuilder($dagDiag);
$dagBuilder->ruleGraph($dagBuilder->build(DsbFixtureDto::class));
assert_true(!$dagDiag->hasErrors(), 'a DAG (shared leaf, no back-edge) does not trip the cycle guard');

// ============================================================================
// Step 9 pass-3: wire-name precedence + OA description carry + hydration-key invariance.
//   1. PHP prop + OA\Property(property)  ⇒ serial name = OA arg (the hydrator wire key).
//   2. #[Field(name)] + OA\Property(property) ⇒ #[Field(name)] wins (Step-10 canonical source).
//   3. no Field::name, no OA property   ⇒ serial name = PHP property name.
//   4. OA description is carried onto PropertyMetadata (rendered by withConstraints()).
//   6. the rule-graph (hydration) key is STILL the OA arg — the fix touched the schema projection only.
// ============================================================================
$wn = $builder->build(DsbWireNameDto::class);

$oaOnly = $wn->property('oaOnly');
assert_same('oaOnly', $oaOnly->name, 'wire-name: PropertyMetadata.name stays the PHP property name');
assert_same('oa_name', $oaOnly->serialName(), 'wire-name: OA\Property(property) is the serial name when no #[Field(name)] override exists');
assert_same('OA-described field', $oaOnly->description, 'wire-name: OA\Property(description) is carried onto PropertyMetadata.description');

$fieldOverridesOa = $wn->property('fieldOverridesOa');
assert_same('field_wins', $fieldOverridesOa->serialName(), 'wire-name: #[Field(name)] overrides the legacy OA\Property(property) wire name');

$noWireName = $wn->property('noWireName');
assert_same('noWireName', $noWireName->serialName(), 'wire-name: serial name falls back to the PHP property name when no wire name is declared anywhere');

// The rule-graph (hydration) key is unchanged — serial-name fix touched only the schema projection.
$wnGraph = $builder->ruleGraph($wn)->rules;
assert_true(array_key_exists('oa_name', $wnGraph), 'wire-name: rule-graph (hydration) key is the OA arg (oa_name)');
assert_true(array_key_exists('oa_loses', $wnGraph), 'wire-name: rule-graph key is the OA arg (oa_loses), NOT the #[Field(name)] override');
assert_true(!array_key_exists('field_wins', $wnGraph), 'wire-name: #[Field(name)] never leaks into the rule-graph hydration key');
assert_same(
    $builder->ruleGraph($builder->build(DsbWireNameDto::class))->rules,
    Router::extractValidationRules(DsbWireNameDto::class),
    'wire-name: DsbWireNameDto rule-graph byte-identical to extractValidationRules (hydration key unchanged by the schema fix)',
);

echo "DtoSchemaBuilder parity + projection passed\n";
