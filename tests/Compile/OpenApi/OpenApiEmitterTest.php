<?php

declare(strict_types=1);

use OpenApi\Attributes as OA;
use SpsFW\Core\Attributes\AccessRulesAll;
use SpsFW\Core\Attributes\AccessRulesAny;
use SpsFW\Core\Attributes\NoAuthAccess;
use SpsFW\Core\Attributes\OpenApi\Field;
use SpsFW\Core\Attributes\OpenApi\Response as ApiResponse;
use SpsFW\Core\Attributes\RateLimit;
use SpsFW\Core\Attributes\Route;
use SpsFW\Core\Attributes\Validation\JsonBody;
use SpsFW\Core\Compile\CompileDiagnostics;
use SpsFW\Core\Compile\OpenApi\OpenApiEmitter;
use SpsFW\Core\Compile\OpenApi\OpenApiEscapeHatch;
use SpsFW\Core\Compile\OpenApi\OpenApiEscapeHatchMerger;
use SpsFW\Core\Compile\OpenApi\OpenApiValidator;
use SpsFW\Core\Compile\OpenApi\SchemaNameResolver;
use SpsFW\Core\Compile\Route\RouteMetadataCompiler;
use SpsFW\Core\Http\HttpMethod;
use SpsFW\Core\Http\Response;
use Symfony\Component\Yaml\Yaml;

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once __DIR__ . '/collision_fixture.php';

/**
 * Шаг 4 (M3 secondary): OpenApiEmitter builds a deterministic OpenAPI 3.1.0 array (array-first) from
 * OperationMetadata[], normalizes duplicate METHOD:path (last-wins + fatal diagnostic), collects object DTOs
 * into components.schemas via SchemaNameResolver (FQCN⇒name registry INTERNAL — never published as x-fqcn),
 * merges StandardErrorPolicy responses (400/401/403/429/500, no 422 — 403 by the EFFECTIVE runtime access
 * pipeline), and serializes via dump()/writeFile() of the ALREADY-BUILT array. Nullability is OpenAPI 3.1 /
 * JSON Schema 2020-12 (type union / anyOf) — `nullable: true` is never emitted.
 */

enum EmMode: string
{
    case Active = 'active';
    case Disabled = 'disabled';
}

final class EmMeDto
{
    #[OA\Property(property: 'name', type: 'string')]
    public string $name;

    #[OA\Property(property: 'friend', ref: EmFriendDto::class)]
    public EmFriendDto $friend;

    #[OA\Property(property: 'tags', type: 'array', items: new OA\Items(type: 'string'))]
    public array $tags;

    #[OA\Property(property: 'friends', type: 'array', items: new OA\Items(ref: EmFriendDto::class))]
    public array $friends;

    #[OA\Property(property: 'nickname', type: 'string')]
    public ?string $nickname;

    #[OA\Property(property: 'guardian', ref: EmFriendDto::class)]
    public ?EmFriendDto $guardian;
}

final class EmFriendDto
{
    #[OA\Property(property: 'label', type: 'string')]
    public string $label;
}

final class EmCreateDto
{
    #[OA\Property(property: 'email', type: 'string', format: 'email')]
    public string $email;
}

final class EmItemDto
{
    #[OA\Property(property: 'id', type: 'integer')]
    public int $id;
}

final class EmApiController
{
    #[Route('/api/em/me')] // GET → object ref response
    public function me(): EmMeDto
    {
        return new EmMeDto();
    }

    #[Route('/api/em/scalar', [HttpMethod::GET])]
    public function scalar(): int // inline integer
    {
        return 0;
    }

    #[Route('/api/em/when', [HttpMethod::GET])]
    public function when(): \DateTimeImmutable // inline date-time
    {
        return new \DateTimeImmutable();
    }

    #[Route('/api/em/mode', [HttpMethod::GET])]
    public function mode(): EmMode // inline enum
    {
        return EmMode::Active;
    }

    #[Route('/api/em/health', [HttpMethod::GET])]
    #[NoAuthAccess]
    public function health(): void // anonymous, void body
    {
    }

    #[Route('/api/em/create', [HttpMethod::POST])]
    public function create(#[JsonBody] EmCreateDto $dto): EmItemDto // request body + object ref response
    {
        return new EmItemDto();
    }

    #[Route('/api/em/list', [HttpMethod::GET])]
    #[AccessRulesAny(['admin'])]
    #[RateLimit(requests: ['network' => 10], window: 60)]
    #[ApiResponse(schema: EmItemDto::class, collection: true, description: 'a page')]
    public function list(): Response // collection response + 403 + 429
    {
        return new Response();
    }

    #[Route('/api/em/allonly', [HttpMethod::GET])]
    #[AccessRulesAll(['some-cap'])] // All-only quirk: NO AccessRulesAny ⇒ enforces nothing at runtime ⇒ NO 403
    public function allOnly(): EmItemDto
    {
        return new EmItemDto();
    }
}

$diag = new CompileDiagnostics();
$compiler = new RouteMetadataCompiler($diag);
$operations = $compiler->compileOperationClasses([EmApiController::class]);
assert_true(!$diag->hasErrors(), 'emitter fixture: no fatal structural errors');
assert_true(!$diag->hasWarnings(), 'emitter fixture: no migration warnings');

$emitterDiag = new CompileDiagnostics();
$emitter = new OpenApiEmitter($emitterDiag);
$doc = $emitter->emit($operations, title: 'Test API', version: '1.2.3');
$diagCountAfterEmit = $emitterDiag->count();

// --- top-level shape (array-first) ---
assert_same('3.1.0', $doc['openapi'], 'openapi version 3.1.0');
assert_same('Test API', $doc['info']['title'], 'info.title honored');
assert_same('1.2.3', $doc['info']['version'], 'info.version honored');
assert_same(['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT'], $doc['components']['securitySchemes']['bearerAuth'], 'bearerAuth security scheme');

// the canonical Error envelope is always present and uses 3.1 nullability (no `nullable` keyword)
assert_true(isset($doc['components']['schemas']['Error']), 'the Error component is always emitted');
assert_same('object', $doc['components']['schemas']['Error']['type'], 'Error is an object envelope');
assert_same(['string', 'null'], $doc['components']['schemas']['Error']['properties']['error']['properties']['exception']['type'], 'Error.exception nullability is a 3.1 type union');
assert_same(['object', 'null'], $doc['components']['schemas']['Error']['properties']['error']['properties']['previous']['type'], 'Error.previous nullability is a 3.1 type union');

// --- object DTOs collected into components.schemas by short name; the FQCN⇒name registry is INTERNAL ---
$schemas = $doc['components']['schemas'];
assert_true(isset($schemas['EmMeDto']), 'response DTO collected as a component');
assert_true(isset($schemas['EmFriendDto']), 'nested DTO ref (EmMeDto::$friend) transitively collected');
// NO x-fqcn anywhere — the registry is internal to the emitter, never published into the spec.
$hasXfqcn = false;
array_walk_recursive($doc, static function (mixed $v, mixed $k) use (&$hasXfqcn): void {
    if ($k === 'x-fqcn') {
        $hasXfqcn = true;
    }
});
assert_true(!$hasXfqcn, 'no x-fqcn vendor key is published — the FQCN registry stays internal');
// componentRegistry() exposes the internal FQCN⇒name map for tooling/probes (not the spec).
$registry = $emitter->componentRegistry();
assert_same('EmMeDto', $registry[EmMeDto::class] ?? null, 'componentRegistry maps the response DTO FQCN⇒short name');
assert_same('EmFriendDto', $registry[EmFriendDto::class] ?? null, 'componentRegistry maps the nested DTO FQCN⇒short name');

// the nested ref is a $ref; the array-of-strings is inline items
assert_same(['$ref' => '#/components/schemas/EmFriendDto'], $schemas['EmMeDto']['properties']['friend'], 'nested object property is a $ref');
assert_same('array', $schemas['EmMeDto']['properties']['tags']['type'], 'array property projects type:array');
assert_same('string', $schemas['EmMeDto']['properties']['tags']['items']['type'], 'array items type from legacy OA items (parity fallback)');
// Regression (N probe): when legacy #[OA\Items(ref: X)] is used, items->type is swagger-php's UNDEFINED
// sentinel, NOT null. The element type must resolve from items->ref and the sentinel must never reach
// reflection — otherwise the emitter throws "Class @OA\Generator::UNDEFINED🙈 does not exist".
assert_same(['$ref' => '#/components/schemas/EmFriendDto'], $schemas['EmMeDto']['properties']['friends']['items'], 'array items ref resolved from legacy OA Items(ref) despite the UNDEFINED sentinel type');

// --- OpenAPI 3.1 nullability (JSON Schema 2020-12): NO `nullable` keyword ever ---
assert_same(['type' => ['string', 'null']], $schemas['EmMeDto']['properties']['nickname'], 'nullable scalar ⇒ type:[<type>,"null"] union');
assert_true(!array_key_exists('nullable', $schemas['EmMeDto']['properties']['nickname']), 'nullable scalar does NOT carry a nullable key');
assert_same(['anyOf' => [['$ref' => '#/components/schemas/EmFriendDto'], ['type' => 'null']]], $schemas['EmMeDto']['properties']['guardian'], 'nullable $ref ⇒ anyOf:[{$ref},{type:"null"}]');
assert_true(!array_key_exists('nullable', $schemas['EmMeDto']['properties']['guardian']), 'nullable $ref does NOT carry a nullable key');

// --- operation projection ---
$paths = $doc['paths'];
assert_true(isset($paths['/api/em/me']['get']), 'GET operation placed under its path');
assert_same('EmApiMe', $paths['/api/em/me']['get']['operationId'], 'convention operationId');
assert_same(['EmApi'], $paths['/api/em/me']['get']['tags'], 'default tag = controller short name');
assert_same(['$ref' => '#/components/schemas/EmMeDto'], $paths['/api/em/me']['get']['responses']['200']['content']['application/json']['schema'], 'object response ⇒ $ref');

// inline scalar / date-time / enum responses (no component)
assert_same('integer', $paths['/api/em/scalar']['get']['responses']['200']['content']['application/json']['schema']['type'], 'scalar int response inline');
assert_same('string', $paths['/api/em/when']['get']['responses']['200']['content']['application/json']['schema']['type'], 'DateTime response inline string');
assert_same('date-time', $paths['/api/em/when']['get']['responses']['200']['content']['application/json']['schema']['format'], 'DateTime format preserved');
$modeSchema = $paths['/api/em/mode']['get']['responses']['200']['content']['application/json']['schema'];
assert_same(['active', 'disabled'], $modeSchema['enum'], 'enum response inline cases');

// --- request body ---
$body = $paths['/api/em/create']['post']['requestBody'];
assert_true($body['required'], 'JsonBody requestBody required');
assert_same('application/json', array_key_first($body['content']), 'request body content type application/json');
assert_same(['$ref' => '#/components/schemas/EmCreateDto'], $body['content']['application/json']['schema'], 'request body DTO ⇒ $ref');

// --- collection response (arrayItem) ---
$listResp = $paths['/api/em/list']['get']['responses']['200']['content']['application/json']['schema'];
assert_same('array', $listResp['type'], 'collection response ⇒ type:array');
assert_same(['$ref' => '#/components/schemas/EmItemDto'], $listResp['items'], 'collection items ⇒ the item DTO $ref');

// --- StandardErrorPolicy: 400 (body) / 401 / 403 (rules) / 429 (rate) / 500, NO 422 ---
$createResponses = $paths['/api/em/create']['post']['responses'];
assert_true(array_key_exists('400', $createResponses), 'POST with body ⇒ 400 validation error');
assert_true(array_key_exists('401', $createResponses), 'authenticated ⇒ 401');
assert_true(array_key_exists('500', $createResponses), 'every op ⇒ 500');
assert_true(!array_key_exists('403', $createResponses), 'no access rules on create ⇒ no 403');
assert_true(!array_key_exists('429', $createResponses), 'no rate limit on create ⇒ no 429');
assert_true(!array_key_exists('422', $createResponses), '422 is NEVER emitted (no runtime 422 path)');

$listResponses = $paths['/api/em/list']['get']['responses'];
assert_true(array_key_exists('403', $listResponses), 'AccessRulesAny ⇒ 403');
assert_true(array_key_exists('429', $listResponses), '#[RateLimit] ⇒ 429');
// 401 + 403 + 429 + 500 (no 400 — no body/query, no 422)
foreach (['401', '403', '429', '500'] as $code) {
    assert_same(['$ref' => '#/components/schemas/Error'], $listResponses[$code]['content']['application/json']['schema'], "standard error $code ⇒ Error \$ref");
}
assert_true(!array_key_exists('400', $listResponses), 'no validated input on list ⇒ no 400');

// All-only quirk: #[AccessRulesAll] WITHOUT #[AccessRulesAny] enforces nothing at runtime ⇒ NO 403.
$allOnlyResponses = $paths['/api/em/allonly']['get']['responses'];
assert_true(!array_key_exists('403', $allOnlyResponses), 'AccessRulesAll-only ⇒ NO 403 (effective runtime pipeline; All-only enforces nothing)');
assert_true(array_key_exists('401', $allOnlyResponses), 'allOnly is authenticated (not NoAuthAccess) ⇒ 401 still advertised');

// --- security projection ---
assert_same([['bearerAuth' => []]], $paths['/api/em/me']['get']['security'], 'authenticated op ⇒ bearerAuth security');
assert_same(['any' => ['admin'], 'all' => []], $paths['/api/em/list']['get']['x-required-rules'], 'access rules ⇒ x-required-rules');
assert_same([], $paths['/api/em/health']['get']['security'], 'NoAuthAccess ⇒ empty security (anonymous)');
assert_true(!array_key_exists('x-required-rules', $paths['/api/em/health']['get']), 'anonymous op carries no x-required-rules');

// --- array-first serialization: dump()/writeFile() serialize the BUILT array (no re-emit, no new diagnostics) ---
$yaml = $emitter->dump($doc);
assert_same($diagCountAfterEmit, $emitterDiag->count(), 'dump() of the built array adds no diagnostics (no re-emit)');
$tmp = tempnam(sys_get_temp_dir(), 'oa_emit_');
unlink($tmp);
$tmp .= '.yml';
$emitter->writeFile($doc, $tmp);
assert_same($yaml, file_get_contents($tmp), 'writeFile() serializes the already-built array identically to dump()');
assert_same($diagCountAfterEmit, $emitterDiag->count(), 'writeFile() adds no diagnostics (no re-emit)');
@unlink($tmp);

// --- structural validation of the built document (a YAML round-trip alone is insufficient) ---
$validDiag = new CompileDiagnostics();
(new OpenApiValidator($validDiag))->validate($doc);
assert_true(!$validDiag->hasErrors(), 'fixture document passes structural validation (refs resolve, every op has responses)');

// --- YAML round-trip: dump ⇒ parse ⇒ identical array; toYaml() convenience == dump(emit) ---
$reparsed = Yaml::parse($yaml);
assert_same($doc, $reparsed, 'YAML round-trip is lossless — dump→parse reproduces the array exactly');
assert_same($yaml, $emitter->toYaml($operations, title: 'Test API', version: '1.2.3'), 'toYaml() convenience path == dump(emit())');

// --- enum example portability: dump() must NEVER emit the Symfony `!php/enum` tag — js-yaml (Orval) and
//     other OpenAPI tooling reject it as an unknown tag. A #[OA\Property(example: [Site::LK, Site::PRO])]
//     puts real enum instances into the document; dump() normalizes them (backed ⇒ backing value) so the
//     serialized YAML is portable. (Single observed producer: N's PublicationSettingsNewsDto.publishOnSites.)
$enumDoc = [
    'openapi' => '3.1.0',
    'info' => ['title' => 't', 'version' => '1'],
    'paths' => [],
    'components' => ['schemas' => [
        'EnumExample' => ['type' => 'object', 'properties' => [
            'mode' => ['type' => 'string', 'example' => EmMode::Active],
            'modes' => ['type' => 'array', 'items' => ['type' => 'string'], 'example' => [EmMode::Active, EmMode::Disabled]],
        ]],
    ]],
];
$enumYaml = $emitter->dump($enumDoc);
assert_true(!str_contains($enumYaml, '!php/'), 'dump() never emits a Symfony !php/ tag (portable to js-yaml/Orval)');
assert_true(str_contains($enumYaml, 'active') && str_contains($enumYaml, 'disabled'), 'backed-enum examples serialize to their backing values');
$enumReparsed = Yaml::parse($enumYaml);
assert_same('active', $enumReparsed['components']['schemas']['EnumExample']['properties']['mode']['example'], 'single backed-enum example round-trips as its backing scalar');
assert_same(['active', 'disabled'], $enumReparsed['components']['schemas']['EnumExample']['properties']['modes']['example'], 'array-of-backed-enum example round-trips as backing scalars');

// --- empty-array portability: an empty array must serialize as `[]`, never the empty map `{}`.
//     The metadata graph emits empty arrays for security requirement scopes (`bearerAuth: []`), anonymous
//     ops (`security: []`), and access-rule lists (`x-required-rules.all: []`). Symfony YAML renders an
//     empty array as `{}` by default — invalid OpenAPI (Ajv/Orval reject `bearerAuth must be array`).
//     DUMP_EMPTY_ARRAY_AS_SEQUENCE keeps them as sequences. (Legacy swagger-php already renders `[]`.)
$emptyDoc = [
    'openapi' => '3.1.0',
    'info' => ['title' => 't', 'version' => '1'],
    'paths' => [
        '/auth' => ['get' => ['security' => [['bearerAuth' => []]], 'responses' => ['200' => ['description' => 'ok']]]],
        '/anon' => ['get' => ['security' => [], 'responses' => ['200' => ['description' => 'ok']]]],
    ],
];
$emptyYaml = $emitter->dump($emptyDoc);
assert_true(!str_contains($emptyYaml, 'bearerAuth: {}'), 'empty OAuth-scopes list serializes as [] not {} (valid OpenAPI)');
assert_true(str_contains($emptyYaml, 'bearerAuth: []'), 'empty OAuth-scopes list serializes as []');
assert_true(!preg_match('/security:\s*\{\}/', $emptyYaml), 'anonymous security serializes as [] not {}');
$emptyReparsed = Yaml::parse($emptyYaml);
assert_same([['bearerAuth' => []]], $emptyReparsed['paths']['/auth']['get']['security'], 'empty scopes list round-trips as an empty array');
assert_same([], $emptyReparsed['paths']['/anon']['get']['security'], 'anonymous security round-trips as an empty array');

// --- Step 8 / M6 emit-once array-first contract: merge() + a second validate() on the ALREADY-BUILT graph
//     document add NO new diagnostics. The Coordinator relies on this under Metadata: it builds the graph ONCE
//     (emit()), then runs OpenApiEscapeHatchMerger::merge() + OpenApiValidator::validate() on the result WITHOUT
//     re-emitting. CompileDiagnostics DEDUPS by signature, so a diagnostic COUNT cannot prove single-emission —
//     this pins the behavioral contract instead (the single emit() call site is verified in Coordinator by review).
$onceDiag = new CompileDiagnostics();
$onceEmitter = new OpenApiEmitter($onceDiag);
$onceDoc = $onceEmitter->emit($operations, title: 'Test API', version: '1.2.3');
$onceCountAfterEmit = $onceDiag->count();
$onceMerged = (new OpenApiEscapeHatchMerger($onceDiag, dirname(__DIR__, 2)))->merge($onceDoc, OpenApiEscapeHatch::empty());
assert_same($onceDoc, $onceMerged, 'an empty hatch merge is a no-op (returns the built doc unchanged)');
assert_same($onceCountAfterEmit, $onceDiag->count(), 'merge() on the built graph doc adds no diagnostics (array-first, no re-emit)');
(new OpenApiValidator($onceDiag))->validate($onceMerged);
assert_same($onceCountAfterEmit, $onceDiag->count(), 'a second validate() on the built graph doc adds no diagnostics');

// ============================================================================
// Operation normalization (prereq 1): two operations on the same METHOD:path collapse last-wins, and each
// surfaces a FATAL duplicate diagnostic (OpenAPI cannot publish both).
// ============================================================================
final class EmDupController
{
    #[Route('/api/em/dup', [HttpMethod::GET])]
    public function first(): void
    {
    }

    #[Route('/api/em/dup', [HttpMethod::GET])]
    public function second(): void
    {
    }
}
$dupDiag = new CompileDiagnostics();
$dupOps = (new RouteMetadataCompiler($dupDiag))->compileOperationClasses([EmDupController::class]);
assert_same(2, count($dupOps), '2 raw operations emitted (pre-normalization)');
$emDup = new OpenApiEmitter($emitDiag = new CompileDiagnostics());
$dupDoc = $emDup->emit($dupOps);
assert_same(1, count($dupDoc['paths']), 'duplicate METHOD:path collapses to 1 effective path');
assert_true($emitDiag->hasErrors(), 'duplicate METHOD:path surfaces a FATAL structural error');
assert_same(0, $emitDiag->warningCount(), 'duplicate-key is structural, not a migration warning');
// last-wins: the surviving operation is the second one
assert_same('EmDupSecond', $dupDoc['paths']['/api/em/dup']['get']['operationId'], 'last-wins survives (mirrors Router $routes[$key])');

// ============================================================================
// SchemaNameResolver (prereq/correctness): FQCN⇒name with collision detection + #[Field(schema:)] override.
// Two different FQCNs collapsing to the same short name ⇒ ambiguous $ref ⇒ FATAL; first registrant keeps it.
// A class-level #[Field(schema:)] disambiguates (the override wins, no collision).
// ============================================================================
$resolverDiag = new CompileDiagnostics();
$resolver = new SchemaNameResolver($resolverDiag);
assert_same('CollideDto', $resolver->resolve(\SpsOaTest\DupA\CollideDto::class), 'first CollideDto resolves to its short name');
assert_same('CollideDto', $resolver->resolve(\SpsOaTest\DupB\CollideDto::class), 'loser still resolves to the same name (its refs point at the winner)');
assert_true($resolverDiag->hasErrors(), 'two FQCNs collapsing to one component name ⇒ FATAL collision diagnostic');
assert_same(\SpsOaTest\DupA\CollideDto::class, $resolver->ownerOf('CollideDto'), 'first registrant owns the slot');
assert_same([\SpsOaTest\DupA\CollideDto::class => 'CollideDto'], $resolver->componentRegistry(), 'only the owner is registered; the loser is not re-mapped');

// Field(schema:) override disambiguates ⇒ no collision.
$resolverDiag2 = new CompileDiagnostics();
$resolver2 = new SchemaNameResolver($resolverDiag2);
assert_same('CollideDto', $resolver2->resolve(\SpsOaTest\DupA\CollideDto::class), 'first CollideDto keeps its short name');
assert_same('RenamedDto', $resolver2->resolve(\SpsOaTest\Renamed\CollideDto::class), 'class-level #[Field(schema:)] overrides the component name');
assert_true(!$resolverDiag2->hasErrors(), 'a #[Field(schema:)] override resolves the collision ⇒ no fatal');
assert_same(\SpsOaTest\Renamed\CollideDto::class, $resolver2->ownerOf('RenamedDto'), 'the renamed class owns its own slot');

echo "OpenApiEmitter passed\n";
