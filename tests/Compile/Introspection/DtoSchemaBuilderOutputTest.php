<?php

declare(strict_types=1);

use SpsFW\Core\Compile\CompileDiagnostics;
use SpsFW\Core\Compile\Introspection\DtoSchemaBuilder;
use SpsFW\Core\Compile\Metadata\SchemaMetadata;
use SpsFW\Core\Compile\Introspection\SchemaDirection;
use SpsFW\Tests\Compile\Introspection\Fixtures\JsChildDto;
use SpsFW\Tests\Compile\Introspection\Fixtures\JsCredentialDto;
use SpsFW\Tests\Compile\Introspection\Fixtures\JsDynamicDto;
use SpsFW\Tests\Compile\Introspection\Fixtures\JsLitDto;

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once __DIR__ . '/fixtures/JsonSerializeFixture.php';

/**
 * Step 9.5 §1/§3: DtoSchemaBuilder direction-aware OUTPUT projection. A JsonSerializable class's OUTPUT schema is
 * its proven jsonSerialize() subset (aliases and literal scalars honored), NOT the exhaustive public-property set;
 * an unprovable serializer yields a diagnostic and an EMPTY (non-leaky) schema — never an exhaustive fallback.
 */
$diag = new CompileDiagnostics();
$builder = new DtoSchemaBuilder($diag);

$wireNames = static function (SchemaMetadata $s): array {
    return array_map(static fn ($p): string => $p->serialName(), $s->properties);
};

// INPUT = every public property (the hydration contract).
$input = $builder->build(JsLitDto::class, SchemaDirection::Input);
assert_same(['id', 'name', 'createdAt', 'flag'], $wireNames($input), 'INPUT: every public property by PHP name');

// OUTPUT = the jsonSerialize() subset: aliases (created/maybe), literal scalars (kind/count/ratio/active/nothing).
$output = $builder->build(JsLitDto::class, SchemaDirection::Output);
assert_same(
    ['id', 'name', 'created', 'maybe', 'kind', 'count', 'ratio', 'active', 'nothing'],
    $wireNames($output),
    'OUTPUT: the proven jsonSerialize wire keys (aliases + literal scalars), not the public-property set',
);

// An alias key carries the underlying property's type: `created` ⇒ $createdAt (DateTime).
$created = null;
foreach ($output->properties as $p) {
    if ($p->serialName() === 'created') {
        $created = $p;
    }
}
assert_same('DateTime', $created->refClass, 'OUTPUT alias `created` carries the $createdAt property type');

// A literal-scalar key has no backing property: `count` ⇒ phpType int.
$count = null;
foreach ($output->properties as $p) {
    if ($p->serialName() === 'count') {
        $count = $p;
    }
}
assert_same('int', $count->phpType, 'OUTPUT literal-scalar `count` ⇒ phpType int (no property)');

// LITERAL_PLUS_PARENT: OUTPUT = parent keys + child additions.
$child = $builder->build(JsChildDto::class, SchemaDirection::Output);
assert_same(['base', 'extra'], $wireNames($child), 'OUTPUT LITERAL_PLUS_PARENT: parent keys then child additions');

// Unprovable serializer ⇒ EMPTY schema + a generation-gap WARNING (no exhaustive fallback). This is a WARNING,
// not an ERROR: the schema is still emitted (empty/non-leaky), so per the CompileDiagnostics contract it is a
// generation gap. The credential-LEAK guard (§8) is the one that stays a hard ERROR.
$dynamic = $builder->build(JsDynamicDto::class, SchemaDirection::Output);
assert_same([], $wireNames($dynamic), 'OUTPUT unprovable (get_object_vars) ⇒ EMPTY schema, no leak');
assert_true($diag->hasWarnings(), 'OUTPUT unprovable ⇒ a generation-gap WARNING (empty schema emitted, no error)');
assert_true(!$diag->hasErrors(), 'OUTPUT unprovable is a WARNING, not a structural ERROR');

// §8 sensitive-field guard: a provable serializer that (wrongly) returns credential fields. The OUTPUT projection
// DROPS password/hashedPassword (kept: login) and surfaces a compile ERROR — credentials never reach a response.
$guardDiag = new CompileDiagnostics();
$guardBuilder = new DtoSchemaBuilder($guardDiag);
$cred = $guardBuilder->build(JsCredentialDto::class, SchemaDirection::Output);
assert_same(['login'], $wireNames($cred), '§8 guard: password/hashedPassword dropped from OUTPUT, login kept');
assert_true($guardDiag->hasErrors(), '§8 guard: a credential field in OUTPUT surfaces a compile ERROR');

// password stays a legitimate INPUT field (login/hydration contract) — the guard is OUTPUT-only.
$credInput = $builder->build(JsCredentialDto::class, SchemaDirection::Input);
assert_same(['login', 'password', 'hashedPassword'], $wireNames($credInput), '§8 guard is OUTPUT-only: password is a valid INPUT field');

echo "DtoSchemaBuilder OUTPUT projection passed\n";
