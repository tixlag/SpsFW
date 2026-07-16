<?php

declare(strict_types=1);

use SpsFW\Core\Attributes\OpenApi\Field;
use SpsFW\Core\Attributes\OpenApi\Items;
use SpsFW\Core\Attributes\OpenApi\Operation;
use SpsFW\Core\Attributes\OpenApi\Response as ApiResponse;

require_once dirname(__DIR__, 2) . '/bootstrap.php';

/**
 * Шаг 3 (M3): pins the four new OpenAPI attributes — their #[Attribute] targets/flags and constructor
 * params/defaults. These are the compile-only declaration surface (plan §8) consumed by RouteMetadataCompiler's
 * operation projection and, from Step 4, the OpenApiEmitter.
 */
function opa_flags(string $class): int
{
    $attrs = (new ReflectionClass($class))->getAttributes(Attribute::class);
    return $attrs === [] ? 0 : $attrs[0]->newInstance()->flags;
}

// --- targets / repeatable (plan §8) ---
assert_same(Attribute::TARGET_METHOD, opa_flags(Operation::class), 'Operation targets the method');
assert_same(Attribute::TARGET_PROPERTY | Attribute::TARGET_CLASS, opa_flags(Field::class), 'Field targets property + class (class-level #[Field(schema:)] overrides the component name)');
assert_true((opa_flags(ApiResponse::class) & Attribute::TARGET_METHOD) !== 0, 'Response targets the method');
assert_true((opa_flags(ApiResponse::class) & Attribute::IS_REPEATABLE) !== 0, 'Response is repeatable (one per status)');
assert_true((opa_flags(Items::class) & Attribute::TARGET_PROPERTY) !== 0 && (opa_flags(Items::class) & Attribute::TARGET_PARAMETER) !== 0, 'Items targets property + parameter');

// --- Operation: id/tags/summary/description/deprecated/exclude, all optional ---
$op = new Operation(id: 'customId', tags: ['auth'], summary: 's', description: 'd', deprecated: true, exclude: false);
assert_same('customId', $op->id, 'Operation.id preserved');
assert_same(['auth'], $op->tags, 'Operation.tags preserved');
assert_same('s', $op->summary, 'Operation.summary preserved');
assert_true($op->deprecated, 'Operation.deprecated preserved');
$opDefault = new Operation();
assert_same(null, $opDefault->id, 'Operation.id defaults null');
assert_same([], $opDefault->tags, 'Operation.tags defaults []');
assert_true(!$opDefault->deprecated && !$opDefault->exclude, 'Operation flags default false');

// --- Response: schema/status/description/contentType/headers ---
$r = new ApiResponse(schema: 'App\\FooDto', status: 201, description: 'created', contentType: 'application/xml', headers: ['X-Trace' => 'str']);
assert_same('App\\FooDto', $r->schema, 'Response.schema preserved');
assert_same(201, $r->status, 'Response.status preserved');
assert_same('created', $r->description, 'Response.description preserved');
assert_same('application/xml', $r->contentType, 'Response.contentType preserved');
assert_same(['X-Trace' => 'str'], $r->headers, 'Response.headers preserved');
$rDefault = new ApiResponse();
assert_same(200, $rDefault->status, 'Response.status defaults 200');
assert_same(null, $rDefault->schema, 'Response.schema defaults null');
assert_same('application/json', $rDefault->contentType, 'Response.contentType defaults application/json');
assert_true(!$rDefault->collection, 'Response.collection defaults false (single object body)');
$rCollection = new ApiResponse(schema: 'App\\ItemDto', collection: true);
assert_true($rCollection->collection, 'Response.collection=true marks an array-of-schema body');

// --- Items: class XOR type ---
assert_same('App\\PhoneDto', (new Items(class: 'App\\PhoneDto'))->class, 'Items.class preserved');
assert_same('integer', (new Items(type: 'integer'))->type, 'Items.type preserved');
assert_same(null, (new Items())->class, 'Items defaults null/null');

// --- Field: short-named constraints + name/schema/readOnly/writeOnly ---
$f = new Field(format: 'email', enum: ['a', 'b'], min: 1, max: 9, minLength: 1, maxLength: 10, example: 'x', name: 'email', schema: 'EmailSchema', readOnly: true, writeOnly: false);
assert_same('email', $f->format, 'Field.format preserved');
assert_same(['a', 'b'], $f->enum, 'Field.enum preserved');
assert_same(1, $f->min, 'Field.min preserved (short name, not minimum)');
assert_same(9, $f->max, 'Field.max preserved (short name, not maximum)');
assert_same(1, $f->minLength, 'Field.minLength preserved');
assert_same(10, $f->maxLength, 'Field.maxLength preserved');
assert_same('x', $f->example, 'Field.example preserved');
assert_same('email', $f->name, 'Field.name (JSON serial-name override) preserved');
assert_same('EmailSchema', $f->schema, 'Field.schema (component name) preserved');
assert_true($f->readOnly && !$f->writeOnly, 'Field direction flags preserved');
$fDefault = new Field();
assert_same(null, $fDefault->format, 'Field.format defaults null');
assert_true(!$fDefault->readOnly && !$fDefault->writeOnly, 'Field direction flags default false');

echo "OpenApiAttributes passed\n";
