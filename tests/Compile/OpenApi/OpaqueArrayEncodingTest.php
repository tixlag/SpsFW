<?php

declare(strict_types=1);

use OpenApi\Attributes as OA;
use SpsFW\Core\Attributes\OpenApi\Field;
use SpsFW\Core\Attributes\OpenApi\Items;
use SpsFW\Core\Attributes\Route;
use SpsFW\Core\Compile\CompileDiagnostics;
use SpsFW\Core\Compile\Introspection\DtoSchemaBuilder;
use SpsFW\Core\Compile\OpenApi\OpenApiEmitter;
use SpsFW\Core\Compile\Route\RouteMetadataCompiler;
use SpsFW\Core\Http\HttpMethod;

require_once dirname(__DIR__, 2) . '/bootstrap.php';

/**
 * objectMap is EXPLICIT-ONLY (fix-pass over M8a): a PHP `array` is ambiguous (list OR map), so the compiler
 * NEVER infers an object/map from the absence of a list signal. objectMap is set ONLY by an explicit
 * `#[Field(objectMap: true)]` flag or a legacy OA object declaration (`type:object` / `additionalProperties` /
 * inline `properties`) carried as a transitional parity signal. A bare array, or an array + bare `$ref`
 * without explicit cardinality, is NOT silently folded into an object or a single-object ref — it surfaces a
 * warning until the developer classifies it. Lists stay honest via `#[Items]`.
 */

final class OaeItemDto
{
    #[OA\Property(property: 'id', type: 'integer')]
    public int $id;
}

final class OaeRefDto
{
    #[OA\Property(property: 'label', type: 'string')]
    public string $label;
}

final class OaeDto
{
    // (transitional parity) OA declares an object map ⇒ objectMap ⇒ free-form object. No warning.
    #[OA\Property(property: 'object_via_oa', type: 'object')]
    public array $objectViaOa;

    // (explicit) #[Field(objectMap: true)] ⇒ objectMap ⇒ free-form object. No warning.
    #[Field(objectMap: true)]
    public array $fieldObjectMap;

    // (explicit + typed) objectMap confirmed by the flag, type supplied by the OA ref ⇒ typed {$ref}. No warning.
    #[Field(objectMap: true)]
    #[OA\Property(property: 'ref_confirmed', ref: OaeRefDto::class)]
    public array $refConfirmed;

    // Real lists with explicit items. No warning.
    #[Items(type: 'object')]
    public array $itemsObject;

    #[Items(class: OaeItemDto::class)]
    public array $itemsClass;

    #[Items(type: 'string')]
    public array $itemsScalar;

    // (ambiguous) no signal at all ⇒ NOT an object ⇒ warning. Emitted as a bare type:array.
    public array $noSignal;

    // (ambiguous) bare OA $ref with NO explicit cardinality ⇒ NOT a silent single object ⇒ warning.
    #[OA\Property(property: 'bare_ref', ref: OaeRefDto::class)]
    public array $bareRef;

    // (genuine list gap) declared type:'array' with no items ⇒ warning.
    #[OA\Property(property: 'declared_list_no_items', type: 'array')]
    public array $declaredListNoItems;
}

final class OaeController
{
    #[Route('/api/oae', [HttpMethod::GET])]
    public function me(): OaeDto
    {
        return new OaeDto();
    }
}

// --- DtoSchemaBuilder: objectMap is explicit-only; ambiguous arrays stay unresolved ---
$builder = new DtoSchemaBuilder();
$schema = $builder->build(OaeDto::class);
$prop = static function (string $name) use ($schema): \SpsFW\Core\Compile\Metadata\PropertyMetadata {
    return $schema->property($name);
};

assert_true($prop('objectViaOa')->objectMap, 'OA type:object array ⇒ objectMap (transitional parity signal)');
assert_true($prop('fieldObjectMap')->objectMap, '#[Field(objectMap:true)] ⇒ objectMap (explicit)');
assert_true($prop('refConfirmed')->objectMap && $prop('refConfirmed')->refClass === OaeRefDto::class, 'objectMap + resolvable OA ref ⇒ typed single-object ref');
assert_true(!$prop('itemsObject')->objectMap && $prop('itemsObject')->itemType === 'object', '#[Items(type:object)] ⇒ a list of free-form objects');
assert_true(!$prop('itemsClass')->objectMap && $prop('itemsClass')->itemType === OaeItemDto::class, '#[Items(class)] ⇒ a typed list');
assert_true(!$prop('itemsScalar')->objectMap && $prop('itemsScalar')->itemType === 'string', '#[Items(type:string)] ⇒ a scalar list');
assert_true(!$prop('noSignal')->objectMap && $prop('noSignal')->itemType === null && $prop('noSignal')->refClass === null, 'bare array ⇒ NOT objectMap, NOT a ref ⇒ unresolved (ambiguous)');
assert_true(!$prop('bareRef')->objectMap && $prop('bareRef')->refClass === null, 'array + bare $ref (no cardinality) ⇒ NOT a silent single-object ref');
assert_true(!$prop('declaredListNoItems')->objectMap && $prop('declaredListNoItems')->itemType === null, 'OA type:array with no items ⇒ NOT folded into an object (stays a genuine list gap)');

// --- the emitted OpenAPI shapes ---
$diag = new CompileDiagnostics();
$operations = (new RouteMetadataCompiler($diag))->compileOperationClasses([OaeController::class]);
assert_true(!$diag->hasErrors(), 'opaque-array fixture: no fatal errors');

$emitter = new OpenApiEmitter(new CompileDiagnostics());
$doc = $emitter->emit($operations);
$props = $doc['components']['schemas']['OaeDto']['properties'];

// Emitted schema keys are the serial name: OA\Property::property arg when given (the wire key the runtime
// hydrator reads), else the PHP name — see DtoSchemaBuilder serial-name precedence (pass-3 schema-parity fix).
assert_same(['type' => 'object'], $props['object_via_oa'], 'OA type:object ⇒ {type: object} (keyed by the OA property arg object_via_oa)');
assert_same(['type' => 'object'], $props['fieldObjectMap'], '#[Field(objectMap:true)] ⇒ {type: object} (no OA property arg ⇒ PHP name key)');
assert_same(['$ref' => '#/components/schemas/OaeRefDto'], $props['ref_confirmed'], 'objectMap + OA ref ⇒ typed single-object {$ref} (keyed by the OA property arg ref_confirmed)');
assert_same(['type' => 'array', 'items' => ['type' => 'object']], $props['itemsObject'], '#[Items(type:object)] ⇒ array of free-form objects');
assert_same(['type' => 'array', 'items' => ['$ref' => '#/components/schemas/OaeItemDto']], $props['itemsClass'], '#[Items(class)] ⇒ array of {$ref}');
assert_same(['type' => 'array', 'items' => ['type' => 'string']], $props['itemsScalar'], '#[Items(type:string)] ⇒ array of strings');
assert_same(['type' => 'array'], $props['noSignal'], 'bare array ⇒ emitted as a bare type:array (shape unresolved; warned)');
assert_same(['type' => 'array'], $props['bare_ref'], 'array + bare $ref ⇒ NOT a {$ref} (cardinality unconfirmed; warned; keyed by OA property arg bare_ref)');
assert_same(['type' => 'array'], $props['declared_list_no_items'], 'itemless declared list ⇒ type:array with no items (warned; keyed by OA property arg declared_list_no_items)');

// --- warning inventory: the three ambiguous/gap arrays warn; every explicit object/list is silent ---
$warnings = $diag->warnings();
$warnedFields = array_map(static fn(array $w): string => $w['field'], $warnings);
sort($warnedFields);
assert_same(['bareRef', 'declaredListNoItems', 'noSignal'], $warnedFields, 'only the ambiguous bare array, the bare-$ref array, and the itemless declared list warn');
foreach ($warnings as $w) {
    assert_true(str_contains($w['cause'], 'no derivable item type or object declaration'), "warning cause explains the gap: {$w['field']}");
}

echo "Opaque-array encoding passed\n";
