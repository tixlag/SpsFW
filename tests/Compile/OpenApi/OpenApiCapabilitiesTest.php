<?php

declare(strict_types=1);

use SpsFW\Core\Attributes\NoAuthAccess;
use SpsFW\Core\Attributes\OpenApi\Parameter;
use SpsFW\Core\Attributes\OpenApi\RequestBody;
use SpsFW\Core\Attributes\OpenApi\Response as ApiResponse;
use SpsFW\Core\Attributes\Route;
use SpsFW\Core\Compile\CompileDiagnostics;
use SpsFW\Core\Compile\OpenApi\OpenApiEmitter;
use SpsFW\Core\Compile\Route\RouteMetadataCompiler;
use SpsFW\Core\Http\HttpMethod;

require_once dirname(__DIR__, 2) . '/bootstrap.php';

/**
 * Step 9 corrective-pass: the three compile-only capabilities that carry contract facets the Route-first /
 * inference-first graph cannot express on its own — declared on the controller METHOD, doc-only, runtime-untouched:
 *   1. #[OpenApi\Parameter]  — path/query facets (format/enum/example/default/min/max) + manual query params;
 *   2. #[OpenApi\RequestBody] — raw/multipart bodies, schema-ref or inline shape, required/content-type;
 *   3. #[Response] shape/type/oneOf/anyOf — inline object/array/scalar response bodies + genuine unions.
 *
 * Pins both the compiler PROJECTION (OperationMetadata) and the emitter OUTPUT, plus the diagnostics for bad
 * declarations. NoAuthAccess keeps the standard error policy to a single 500 for clean response isolation.
 */

class OcapADto
{
    public string $id = '';
}
class OcapBDto
{
    public string $code = '';
}

function ocapCompile(string $class, CompileDiagnostics $diag): array
{
    return (new RouteMetadataCompiler($diag))->compileOperationClasses([$class]);
}

// ============================================================================
// 1. #[OpenApi\Parameter] — path facet enrichment + manual query param + emitter rendering.
// ============================================================================
final class OcapParamController
{
    #[Route('/cap/del/{uuid}', [HttpMethod::DELETE], returns: 'string')]
    #[Parameter(name: 'uuid', in: 'path', format: 'uuid', example: '11111111-1111-1111-1111-111111111111')]
    #[Parameter(name: 'reason', description: 'Why the resource is removed', enum: ['stale', 'dup'], default: 'stale')]
    #[NoAuthAccess]
    public function del(string $uuid) {}
}
$d = new CompileDiagnostics();
$ops = ocapCompile(OcapParamController::class, $d);
assert_true(!$d->hasErrors() && !$d->hasWarnings(), 'param caps: no diagnostics');
$op = $ops[0];
// projection: the inferred path param {uuid} is ENRICHED (still required, single entry), and a manual query
// param `reason` is ADDED.
$uuidParam = null;
$reasonParam = null;
foreach (array_merge($op->pathParams, $op->queryParams) as $p) {
    if ($p->name === 'uuid') { $uuidParam = $p; }
    if ($p->name === 'reason') { $reasonParam = $p; }
}
assert_same('path', $uuidParam->in, 'param: path facet enrichment keeps in=path');
assert_true($uuidParam->required, 'param: path param stays required');
assert_same('uuid', $uuidParam->format, 'param: path facet format carried');
assert_same('query', $reasonParam->in, 'param: manual query param added as in=query');
assert_same(['stale', 'dup'], $reasonParam->enum, 'param: manual query enum carried');
assert_same('stale', $reasonParam->default, 'param: manual query default carried');

// emitter output: facets render under the parameter schema.
$doc = (new OpenApiEmitter($d))->emit($ops);
$byName = [];
foreach ($doc['paths']['/cap/del/{uuid}']['delete']['parameters'] as $p) {
    $byName[$p['name']] = $p;
}
assert_same('uuid', $byName['uuid']['schema']['format'], 'emit: path param format rendered');
assert_same('11111111-1111-1111-1111-111111111111', $byName['uuid']['schema']['example'], 'emit: path param example rendered');
assert_same(['stale', 'dup'], $byName['reason']['schema']['enum'], 'emit: query enum rendered');
assert_same('stale', $byName['reason']['schema']['default'], 'emit: query default rendered');
assert_same('Why the resource is removed', $byName['reason']['description'], 'emit: query description rendered at parameter level');

// Array query param element type (Step 9 pass 2): #[Parameter(items:…)] restores the baseline
// {type:array, items:{type:…}} contract that a bare `array` DTO property cannot express.
final class OcapArrayParamController
{
    #[Route('/cap/array-query', [HttpMethod::GET], returns: 'string')]
    #[Parameter(name: 'ids[]', in: 'query', required: true, type: 'array', items: ['type' => 'integer', 'example' => 1135119], example: [1135119, 1135120], description: 'Массив идентификаторов')]
    #[NoAuthAccess]
    public function list() {}
}
$d = new CompileDiagnostics();
$ops = ocapCompile(OcapArrayParamController::class, $d);
assert_true(!$d->hasErrors() && !$d->hasWarnings(), 'array-param: no diagnostics');
$arrParam = null;
foreach ($ops[0]->queryParams as $p) { if ($p->name === 'ids[]') $arrParam = $p; }
assert_same('ids[]', $arrParam->name, 'array-param: bracket name carried');
assert_true($arrParam->required, 'array-param: required carried');
assert_same('array', $arrParam->type, 'array-param: array type carried');
assert_same('integer', $arrParam->items->type, 'array-param: item element type projected');
$doc = (new OpenApiEmitter($d))->emit($ops);
$ap = null;
foreach ($doc['paths']['/cap/array-query']['get']['parameters'] as $p) { if ($p['name'] === 'ids[]') $ap = $p; }
assert_same('array', $ap['schema']['type'], 'emit: array query type rendered');
assert_same('integer', $ap['schema']['items']['type'], 'emit: array query item type rendered');
assert_same(1135119, $ap['schema']['items']['example'], 'emit: array query item example rendered');
assert_true($ap['required'], 'emit: array query required rendered');

// ============================================================================
// 2. #[OpenApi\RequestBody] — inline raw JSON body (shape), multipart upload, override + diagnostics.
// ============================================================================
final class OcapBodyController
{
    #[Route('/cap/raw', [HttpMethod::POST], returns: 'string')]
    #[RequestBody(required: true, shape: ['name' => ['type' => 'string'], 'age' => ['type' => 'integer']])]
    #[NoAuthAccess]
    public function raw() {}

    #[Route('/cap/upload', [HttpMethod::POST], returns: 'string')]
    #[RequestBody(contentType: 'multipart/form-data', shape: ['file' => ['type' => 'string', 'format' => 'binary']])]
    #[NoAuthAccess]
    public function upload() {}
}
$d = new CompileDiagnostics();
$ops = ocapCompile(OcapBodyController::class, $d);
assert_true(!$d->hasErrors() && !$d->hasWarnings(), 'body caps: no diagnostics');
$byPath = [];
foreach ($ops as $o) { $byPath[$o->path] = $o; }
// projection: a RequestBodyMetadata is present where none would be inferred (no DTO marker).
assert_true($byPath['/cap/raw']->requestBody !== null, 'body: raw shape creates a requestBody');
assert_true($byPath['/cap/raw']->requestBody->required, 'body: required flag carried');
$rawProps = [];
foreach ($byPath['/cap/raw']->requestBody->schema->properties as $prop) { $rawProps[$prop->name] = $prop; }
assert_same('string', $rawProps['name']->phpType, 'body: shape property name projected');
assert_same('int', $rawProps['age']->phpType, 'body: shape property age projected (OpenAPI→PHP scalar)');
assert_true($byPath['/cap/upload']->requestBody->contentType === 'multipart/form-data', 'body: multipart content type carried');
// emitter output: inline object body under application/json; binary under multipart.
$doc = (new OpenApiEmitter($d))->emit($ops);
$rawSchema = $doc['paths']['/cap/raw']['post']['requestBody']['content']['application/json']['schema'];
assert_same('object', $rawSchema['type'], 'emit: raw body is an inline object');
assert_same('string', $rawSchema['properties']['name']['type'], 'emit: raw body name property rendered');
$upSchema = $doc['paths']['/cap/upload']['post']['requestBody']['content']['multipart/form-data']['schema'];
assert_same('binary', $upSchema['properties']['file']['format'], 'emit: multipart binary file rendered');

// ============================================================================
// 3. #[Response] shape/type/oneOf/anyOf — inline object, inline array, scalar/binary, unions, nested inline.
// ============================================================================
final class OcapShapeController
{
    #[Route('/cap/msg', [HttpMethod::DELETE])]
    #[ApiResponse(shape: ['msg' => ['type' => 'string', 'example' => 'ok'], 'status' => ['type' => 'string', 'example' => 'ok']])]
    #[NoAuthAccess]
    public function msg() {}

    #[Route('/cap/list', [HttpMethod::GET])]
    #[ApiResponse(shape: ['id' => ['type' => 'integer'], 'name' => ['type' => 'string']], collection: true)]
    #[NoAuthAccess]
    public function list() {}

    #[Route('/cap/bool', [HttpMethod::GET])]
    #[ApiResponse(type: 'boolean')]
    #[NoAuthAccess]
    public function bool() {}

    #[Route('/cap/pdf', [HttpMethod::GET])]
    #[ApiResponse(type: 'string', format: 'binary', contentType: 'application/pdf')]
    #[NoAuthAccess]
    public function pdf() {}

    #[Route('/cap/union', [HttpMethod::GET])]
    #[ApiResponse(anyOf: [OcapADto::class, OcapBDto::class])]
    #[NoAuthAccess]
    public function union() {}

    #[Route('/cap/nullable', [HttpMethod::GET])]
    #[ApiResponse(oneOf: [OcapADto::class, ['type' => 'null']])]
    #[NoAuthAccess]
    public function nullable() {}

    #[Route('/cap/nested', [HttpMethod::GET])]
    #[ApiResponse(shape: [
        'location_code_1c' => ['type' => 'string'],
        'properties' => ['type' => 'array', 'items' => ['properties' => [
            'id' => ['type' => 'integer', 'example' => 1],
            'name' => ['type' => 'string'],
        ]]],
    ])]
    #[NoAuthAccess]
    public function nested() {}
}
$d = new CompileDiagnostics();
$ops = ocapCompile(OcapShapeController::class, $d);
assert_true(!$d->hasErrors() && !$d->hasWarnings(), 'shape caps: no diagnostics');
$doc = (new OpenApiEmitter($d))->emit($ops);
$schema = static function (string $path) use ($doc): ?array {
    return $doc['paths'][$path]['get']['responses']['200']['content']['application/json']['schema']
        ?? $doc['paths'][$path]['delete']['responses']['200']['content']['application/json']['schema']
        ?? null;
};
// inline object body
$msg = $schema('/cap/msg');
assert_same('object', $msg['type'], 'shape: inline object body');
assert_same('ok', $msg['properties']['msg']['example'], 'shape: inline object property example');
// inline array body (collection of inline object)
$list = $schema('/cap/list');
assert_same('array', $list['type'], 'shape: inline array body type');
assert_same('object', $list['items']['type'], 'shape: inline array item is the inline object');
assert_same('integer', $list['items']['properties']['id']['type'], 'shape: inline array item property rendered');
// scalar body
assert_same('boolean', $schema('/cap/bool')['type'], 'shape: scalar body type');
// binary body under application/pdf
$pdf = $doc['paths']['/cap/pdf']['get']['responses']['200']['content']['application/pdf']['schema'];
assert_same('binary', $pdf['format'], 'shape: binary format under application/pdf');
// union anyOf — both DTOs referenced
$union = $schema('/cap/union');
assert_same(2, count($union['anyOf']), 'shape: anyOf has both members');
$refs = array_column($union['anyOf'], '$ref');
assert_true(in_array('#/components/schemas/OcapADto', $refs, true) && in_array('#/components/schemas/OcapBDto', $refs, true), 'shape: anyOf references both DTOs');
// nullable oneOf — DTO ref + null
$nl = $schema('/cap/nullable');
assert_same(2, count($nl['oneOf']), 'shape: oneOf has DTO + null');
assert_true(in_array(['type' => 'null'], $nl['oneOf'], true), 'shape: oneOf includes the null member');
// nested inline object: an array property whose items are an inline object
$nested = $schema('/cap/nested');
assert_same('array', $nested['properties']['properties']['type'], 'shape: nested array property type');
assert_same('integer', $nested['properties']['properties']['items']['properties']['id']['type'], 'shape: nested inline object item property');
assert_same(1, $nested['properties']['properties']['items']['properties']['id']['example'], 'shape: nested inline object item example');

// ============================================================================
// 4. Diagnostics: a path Parameter matching no placeholder; a union declared with a schema; a bad body class.
// ============================================================================
final class OcapBadController
{
    #[Route('/cap/badparam/{id}', [HttpMethod::GET])]
    #[Parameter(name: 'missing', in: 'path', format: 'uuid')]
    #[NoAuthAccess]
    public function badParam(string $id) {}

    #[Route('/cap/badunion', [HttpMethod::GET])]
    #[ApiResponse(oneOf: [OcapADto::class], schema: OcapADto::class)]
    #[NoAuthAccess]
    public function badUnion() {}

    #[Route('/cap/badbody', [HttpMethod::POST])]
    #[RequestBody(schema: 'NoSuchClass')]
    #[NoAuthAccess]
    public function badBody() {}
}
$d = new CompileDiagnostics();
ocapCompile(OcapBadController::class, $d);
assert_true($d->hasWarnings(), 'diag: path param with no placeholder warns');
assert_true($d->hasErrors(), 'diag: union+schema and bad body class error');
$render = $d->render();
assert_true(str_contains($render, 'matches no {placeholder}'), 'diag: path param warning text');
assert_true(str_contains($render, 'union body is exclusive'), 'diag: union+schema error text');
assert_true(str_contains($render, 'NoSuchClass'), 'diag: bad body class error text');

echo "OpenApiCapabilitiesTest passed\n";
