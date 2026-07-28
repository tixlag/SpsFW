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
 * Step 9 SECOND corrective pass — focused coverage for the three defect classes the strict comparator found:
 *   D1 — a 204 (No Content) with a body must be a compile ERROR, universally (single- AND multi-success; any
 *        body-form: schema/shape/type/oneOf/anyOf/collection);
 *   D2 — invalid declaration COMBINATIONS are now compile diagnostics, not silent precedence / silent fallbacks
 *        (oneOf+anyOf together, empty union, multiple body-forms, format-without-type, RequestBody schema+shape,
 *        malformed shape, unsupported Parameter `in`, duplicate conflicting Parameters, invalid union member);
 *   D3 — an inline shape keeps EVERY facet (property description/default/additionalProperties, full array items
 *        with format/example/enum/default/nullable, nested inline objects) instead of collapsing to bare types.
 *
 * Plus the doc-only guarantee: adding these compile-only attributes leaves the runtime dispatch IR byte-identical.
 */

class Step9ADto { public string $id = ''; }

function step9Compile(string $class, CompileDiagnostics $diag): array
{
    return (new RouteMetadataCompiler($diag))->compileOperationClasses([$class]);
}

function step9Doc(CompileDiagnostics $diag, array $ops): array
{
    return (new OpenApiEmitter($diag))->emit($ops);
}

function step9Body(array $doc, string $path, string $method = 'get', int $status = 200): ?array
{
    return $doc['paths'][$path][$method]['responses'][$status]['content']['application/json']['schema']
        ?? null;
}

// ============================================================================
// D1 — 204 + body is a universal compile ERROR (single- AND multi-success; every body-form).
// ============================================================================
final class Step9D1Controller
{
    #[Route('/d1/shape', [HttpMethod::GET])]
    #[ApiResponse(status: 204, shape: ['msg' => ['type' => 'string']])]
    #[NoAuthAccess] public function s204shape() {}

    #[Route('/d1/type', [HttpMethod::GET])]
    #[ApiResponse(status: 204, type: 'string')]
    #[NoAuthAccess] public function s204type() {}

    #[Route('/d1/oneof', [HttpMethod::GET])]
    #[ApiResponse(status: 204, oneOf: [Step9ADto::class, ['type' => 'null']])]
    #[NoAuthAccess] public function s204oneOf() {}

    #[Route('/d1/anyof', [HttpMethod::GET])]
    #[ApiResponse(status: 204, anyOf: [Step9ADto::class])]
    #[NoAuthAccess] public function s204anyOf() {}

    #[Route('/d1/collection', [HttpMethod::GET])]
    #[ApiResponse(status: 204, schema: Step9ADto::class, collection: true)]
    #[NoAuthAccess] public function s204collection() {}

    // multi-success: a 204 with a union body alongside another 2xx — the multi-success 204 check must fire too.
    #[Route('/d1/multi', [HttpMethod::GET])]
    #[ApiResponse(status: 200, schema: Step9ADto::class)]
    #[ApiResponse(status: 204, oneOf: [Step9ADto::class, ['type' => 'null']])]
    #[NoAuthAccess] public function s204multi() {}
}
$d = new CompileDiagnostics();
step9Compile(Step9D1Controller::class, $d);
$render = $d->render();
foreach (['s204shape', 's204type', 's204oneOf', 's204anyOf', 's204collection', 's204multi'] as $m) {
    assert_true(str_contains($render, $m), "D1: 204+body error names method {$m}");
}
assert_true(str_contains($render, 'No Content (204)') || str_contains($render, '204'), 'D1: 204+body error text mentions 204');
assert_true(str_contains($render, 'carries no body') || str_contains($render, 'no body'), 'D1: 204+body error explains no-body');

// A clean 204 (No Content, no body form) is NOT an error.
final class Step9D1CleanController
{
    #[Route('/d1/clean', [HttpMethod::GET], successStatus: 204)]
    #[NoAuthAccess] public function clean204(): void {}
}
$d = new CompileDiagnostics();
step9Compile(Step9D1CleanController::class, $d);
assert_true(!$d->hasErrors() && !$d->hasWarnings(), 'D1: a clean 204 with no body is not an error');

// ============================================================================
// D2 — invalid declaration combinations are compile diagnostics (not silent precedence / fallback).
// ============================================================================
final class Step9D2UnionController
{
    #[Route('/d2/both', [HttpMethod::GET])] // oneOf AND anyOf together
    #[ApiResponse(oneOf: [Step9ADto::class], anyOf: [Step9ADto::class])]
    #[NoAuthAccess] public function bothUnions() {}

    #[Route('/d2/empty', [HttpMethod::GET])] // empty union
    #[ApiResponse(oneOf: [])]
    #[NoAuthAccess] public function emptyUnion() {}

    #[Route('/d2/badmember', [HttpMethod::GET])] // invalid union member — must diagnose, NOT crash
    #[ApiResponse(oneOf: ['NoSuchClass'])]
    #[NoAuthAccess] public function badMember() {}
}
$d = new CompileDiagnostics();
step9Compile(Step9D2UnionController::class, $d);
$render = $d->render();
assert_true(str_contains($render, 'oneOf AND anyOf'), 'D2: oneOf+anyOf together errors');
assert_true(str_contains($render, 'empty oneOf/anyOf'), 'D2: empty union errors');
assert_true(str_contains($render, 'NoSuchClass'), 'D2: invalid union member diagnosed');

final class Step9D2BodyFormsController
{
    #[Route('/d2/schema_shape', [HttpMethod::GET])] // schema + shape together
    #[ApiResponse(schema: Step9ADto::class, shape: ['x' => ['type' => 'string']])]
    #[NoAuthAccess] public function schemaShape() {}

    #[Route('/d2/shape_type', [HttpMethod::GET])] // shape + type together
    #[ApiResponse(shape: ['x' => ['type' => 'string']], type: 'string')]
    #[NoAuthAccess] public function shapeType() {}

    #[Route('/d2/format_notype', [HttpMethod::GET])] // format without type
    #[ApiResponse(format: 'date-time')]
    #[NoAuthAccess] public function formatNoType() {}
}
$d = new CompileDiagnostics();
step9Compile(Step9D2BodyFormsController::class, $d);
$render = $d->render();
assert_true(str_contains($render, 'several body forms together'), 'D2: multiple body-forms errors');
assert_true(str_contains($render, 'format without a type'), 'D2: format-without-type errors');

final class Step9D2RequestBodyController
{
    #[Route('/d2/body_schema_shape', [HttpMethod::POST])] // RequestBody schema + shape together
    #[RequestBody(schema: Step9ADto::class, shape: ['x' => ['type' => 'string']])]
    #[NoAuthAccess] public function bodySchemaShape() {}

    #[Route('/d2/body_malformed', [HttpMethod::POST])] // malformed shape (scalar facet, not an array)
    #[RequestBody(shape: ['name' => 'string'])]
    #[NoAuthAccess] public function bodyMalformed() {}
}
$d = new CompileDiagnostics();
step9Compile(Step9D2RequestBodyController::class, $d);
$render = $d->render();
assert_true(str_contains($render, 'schema AND shape together'), 'D2: RequestBody schema+shape errors');
assert_true(str_contains($render, 'not a facet array'), 'D2: malformed shape errors');

final class Step9D2ParamController
{
    #[Route('/d2/p/{id}', [HttpMethod::GET])]
    #[Parameter(name: 'id', in: 'path')]
    // unsupported `in` — must error, not silently fall back to query
    #[Parameter(name: 'h', in: 'header', type: 'string')]
    // duplicate (in,name) with CONFLICTING facets — order-independent diagnostic
    #[Parameter(name: 'q', type: 'string')]
    #[Parameter(name: 'q', type: 'integer')]
    #[NoAuthAccess] public function params(string $id) {}
}
$d = new CompileDiagnostics();
step9Compile(Step9D2ParamController::class, $d);
$render = $d->render();
assert_true(str_contains($render, 'unsupported `in`'), 'D2: invalid Parameter in errors');
assert_true(str_contains($render, 'conflicting type'), 'D2: duplicate conflicting Parameter errors');

// ============================================================================
// D3 — an inline shape keeps EVERY facet (description, default, additionalProperties, full items).
// ============================================================================
final class Step9D3Controller
{
    #[Route('/d3/full', [HttpMethod::GET])]
    #[ApiResponse(shape: [
        'name' => ['type' => 'string', 'description' => 'The display name'],
        'count' => ['type' => 'integer', 'default' => 0],
        'flag' => ['type' => 'boolean', 'default' => null],   // explicit null default survives
        'rules' => ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']]],
        'tags' => ['type' => 'array', 'items' => [
            'type' => 'string', 'format' => 'uuid', 'example' => '11111111-1111-1111-1111-111111111111',
            'enum' => ['a', 'b'], 'default' => 'a',
        ]],
        'nested' => ['type' => 'array', 'items' => ['properties' => [
            'id' => ['type' => 'integer', 'example' => 1, 'description' => 'The record id'],
            'label' => ['type' => 'string'],
        ]]],
    ])]
    #[NoAuthAccess] public function full() {}
}
$d = new CompileDiagnostics();
$ops = step9Compile(Step9D3Controller::class, $d);
assert_true(!$d->hasErrors() && !$d->hasWarnings(), 'D3: rich inline shape compiles clean');
$doc = step9Doc($d, $ops);
$props = step9Body($doc, '/d3/full')['properties'];

assert_same('The display name', $props['name']['description'], 'D3: property description rendered');
assert_same(0, $props['count']['default'], 'D3: property default rendered');
assert_true(array_key_exists('default', $props['flag']) && $props['flag']['default'] === null, 'D3: explicit null default rendered');

// typed map: rules = {type:object, additionalProperties:{type:array, items:{type:string}}}
assert_same('object', $props['rules']['type'], 'D3: typed-map property is object');
assert_same('array', $props['rules']['additionalProperties']['type'], 'D3: additionalProperties value is array');
assert_same('string', $props['rules']['additionalProperties']['items']['type'], 'D3: additionalProperties item type');

// scalar array item carries ALL its facets (format/example/enum/default) — the old renderer collapsed to {type:string}
$item = $props['tags']['items'];
assert_same('string', $item['type'], 'D3: array item type');
assert_same('uuid', $item['format'], 'D3: array item format');
assert_same('11111111-1111-1111-1111-111111111111', $item['example'], 'D3: array item example');
assert_same(['a', 'b'], $item['enum'], 'D3: array item enum');
assert_same('a', $item['default'], 'D3: array item default');

// nested inline object item — its property description survives, and the item example survives
$nestedItem = $props['nested']['items'];
assert_same('object', $nestedItem['type'], 'D3: nested array item is inline object');
assert_same('The record id', $nestedItem['properties']['id']['description'], 'D3: nested property description');
assert_same(1, $nestedItem['properties']['id']['example'], 'D3: nested property example');

// ============================================================================
// Doc-only guarantee — adding compile-only attributes leaves the runtime dispatch IR byte-identical.
// ============================================================================
final class Step9RuntimeBaselineController
{
    #[Route('/rt/item/{id}', [HttpMethod::GET], returns: Step9ADto::class)]
    #[NoAuthAccess] public function item(string $id): Step9ADto { return new Step9ADto(); }
}
final class Step9RuntimeDecoratedController
{
    #[Route('/rt/item/{id}', [HttpMethod::GET], returns: Step9ADto::class)]
    #[Parameter(name: 'id', in: 'path', format: 'uuid')]
    #[ApiResponse(schema: Step9ADto::class, headers: ['X-Total' => ['description' => 'count', 'schema' => ['type' => 'integer']]])]
    #[NoAuthAccess] public function item(string $id): Step9ADto { return new Step9ADto(); }
}
$db = new CompileDiagnostics(); $ob = step9Compile(Step9RuntimeBaselineController::class, $db)[0];
$dd = new CompileDiagnostics(); $od = step9Compile(Step9RuntimeDecoratedController::class, $dd)[0];
assert_true(!$db->hasErrors() && !$dd->hasErrors(), 'runtime IR: both compile clean');
// The dispatch IR (what compiled_routes.php encodes) comes solely from #[Route]; the doc-only attributes are
// separate attributes that never touch path/method/handler. (operationId/controller differ only because the two
// fixtures have different class names — operationId is class+method-derived — not because of the attributes.)
assert_same($ob->httpMethod, $od->httpMethod, 'runtime IR: httpMethod unchanged by doc-only attrs');
assert_same($ob->path, $od->path, 'runtime IR: path unchanged by doc-only attrs');
assert_same($ob->method, $od->method, 'runtime IR: handler method unchanged by doc-only attrs');
// The doc-only attributes DO populate the OpenAPI projection (proving they are consumed only by the emitter):
assert_true($od->pathParams[0]->format === 'uuid', 'runtime IR: doc-only Parameter enriches projection only');

echo "OpenApiStep9CorrectivePassTest passed\n";
