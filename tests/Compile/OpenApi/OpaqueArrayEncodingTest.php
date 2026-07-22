<?php

declare(strict_types=1);

use OpenApi\Attributes as OA;
use SpsFW\Core\Attributes\OpenApi\Items;
use SpsFW\Core\Attributes\Route;
use SpsFW\Core\Compile\CompileDiagnostics;
use SpsFW\Core\Compile\Introspection\DtoSchemaBuilder;
use SpsFW\Core\Compile\OpenApi\OpenApiEmitter;
use SpsFW\Core\Compile\Route\RouteMetadataCompiler;
use SpsFW\Core\Http\HttpMethod;

require_once dirname(__DIR__, 2) . '/bootstrap.php';

/**
 * M8a: a PHP `array` is a LIST only when it carries a list signal (#[Items], legacy OA `items:`, or OA
 * `type:'array'`). Every other array-typed property is an opaque OBJECT/map and is encoded honestly as
 * `type: object` (objectMap) — or, when the OA points at a single class, a `{$ref}`. This closes the 26
 * "no derivable item type" warnings without fabricating element types: a genuine itemless LIST (OA
 * `type:'array'` with no items and no #[Items]) still warns, and a broken `#[Items]` (neither class nor
 * type) is still diagnosed (covered in DtoSchemaBuilderTest).
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
    // OA declares an object map ⇒ free-form object.
    #[OA\Property(property: 'object_via_oa', type: 'object')]
    public array $objectViaOa;

    // No OA at all, nullable ⇒ an untyped map ⇒ free-form object (nullable ⇒ type:[object,"null"]).
    public ?array $mapNoOa;

    // OA points at a single object via $ref (resolvable) ⇒ a {$ref}, NOT an array.
    #[OA\Property(property: 'ref', ref: OaeRefDto::class)]
    public array $ref;

    // Real lists with explicit items.
    #[Items(type: 'object')]
    public array $itemsObject;

    #[Items(class: OaeItemDto::class)]
    public array $itemsClass;

    #[Items(type: 'string')]
    public array $itemsScalar;

    // A declared-but-itemless list (type:'array', no items, no #[Items]) ⇒ the ONE genuine gap that still warns.
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

// --- DtoSchemaBuilder: the objectMap flag + the surviving itemless-list warning ---
$builder = new DtoSchemaBuilder();
$schema = $builder->build(OaeDto::class);
$prop = static function (string $name) use ($schema): \SpsFW\Core\Compile\Metadata\PropertyMetadata {
    return $schema->property($name);
};

assert_true($prop('objectViaOa')->objectMap, 'OA type:object array ⇒ objectMap (free-form object)');
assert_true($prop('mapNoOa')->objectMap, 'no-signal untyped array ⇒ objectMap (an opaque map)');
assert_true(!$prop('ref')->objectMap && $prop('ref')->refClass === OaeRefDto::class, 'OA $ref on array ⇒ a single-object ref (not objectMap, not a list)');
assert_true(!$prop('itemsObject')->objectMap && $prop('itemsObject')->itemType === 'object', '#[Items(type:object)] ⇒ a list of free-form objects');
assert_true(!$prop('itemsClass')->objectMap && $prop('itemsClass')->itemType === OaeItemDto::class, '#[Items(class)] ⇒ a typed list');
assert_true(!$prop('itemsScalar')->objectMap && $prop('itemsScalar')->itemType === 'string', '#[Items(type:string)] ⇒ a scalar list');
assert_true(!$prop('declaredListNoItems')->objectMap && $prop('declaredListNoItems')->itemType === null, 'OA type:array with no items ⇒ NOT folded into an object (stays a genuine list gap)');

// --- the emitted OpenAPI shapes ---
$diag = new CompileDiagnostics();
$operations = (new RouteMetadataCompiler($diag))->compileOperationClasses([OaeController::class]);
assert_true(!$diag->hasErrors(), 'opaque-array fixture: no fatal errors');

$emitter = new OpenApiEmitter(new CompileDiagnostics());
$doc = $emitter->emit($operations);
$props = $doc['components']['schemas']['OaeDto']['properties'];

assert_same(['type' => 'object'], $props['objectViaOa'], 'free-form object ⇒ {type: object} (no items, no guessed element)');
assert_same(['type' => ['object', 'null']], $props['mapNoOa'], 'nullable untyped map ⇒ type:[object,"null"]');
assert_same(['$ref' => '#/components/schemas/OaeRefDto'], $props['ref'], 'OA $ref on an array ⇒ a single-object {$ref}');
assert_same(['type' => 'array', 'items' => ['type' => 'object']], $props['itemsObject'], '#[Items(type:object)] ⇒ array of free-form objects');
assert_same(['type' => 'array', 'items' => ['$ref' => '#/components/schemas/OaeItemDto']], $props['itemsClass'], '#[Items(class)] ⇒ array of {$ref}');
assert_same(['type' => 'array', 'items' => ['type' => 'string']], $props['itemsScalar'], '#[Items(type:string)] ⇒ array of strings');
assert_same(['type' => 'array'], $props['declaredListNoItems'], 'itemless declared list ⇒ type:array with no items (the one remaining gap)');

// --- warning inventory: ONLY the genuine itemless list surfaces a warning ---
$warnings = $diag->warnings();
assert_same(1, count($warnings), 'only the declared-but-itemless list warns; every opaque map/ref/items array is silent');
assert_true(str_contains($warnings[0]['cause'], 'no derivable item type'), 'the surviving warning is the itemless-list gap');
assert_same('declaredListNoItems', $warnings[0]['field'], 'the surviving warning is attributed to the itemless list');

echo "Opaque-array encoding passed\n";
