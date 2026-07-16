<?php

declare(strict_types=1);

use OpenApi\Attributes as OA;
use SpsFW\Core\Attributes\AccessRulesAny;
use SpsFW\Core\Attributes\NoAuthAccess;
use SpsFW\Core\Attributes\OpenApi\Response as ApiResponse;
use SpsFW\Core\Attributes\RateLimit;
use SpsFW\Core\Attributes\Route;
use SpsFW\Core\Attributes\Validation\JsonBody;
use SpsFW\Core\Attributes\Validation\QueryParams;
use SpsFW\Core\Compile\CompileDiagnostics;
use SpsFW\Core\Compile\OpenApi\OpenApiEmitter;
use SpsFW\Core\Compile\Route\RouteMetadataCompiler;
use SpsFW\Core\Http\HttpMethod;
use SpsFW\Core\Http\Response;
use Symfony\Component\Yaml\Yaml;

require_once dirname(__DIR__, 2) . '/bootstrap.php';

/**
 * Шаг 4 (M3 secondary): OpenApiEmitter builds a deterministic OpenAPI 3.1.0 array (array-first) from
 * OperationMetadata[], normalizes duplicate METHOD:path (last-wins + fatal diagnostic), collects object DTOs
 * into components.schemas, merges StandardErrorPolicy responses (400/401/403/429/500, no 422), and serializes
 * via symfony/yaml. The primary openapi.yml is untouched — this is the secondary generated artifact.
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
}

$diag = new CompileDiagnostics();
$compiler = new RouteMetadataCompiler($diag);
$operations = $compiler->compileOperationClasses([EmApiController::class]);
assert_true(!$diag->hasErrors(), 'emitter fixture: no fatal structural errors');
assert_true(!$diag->hasWarnings(), 'emitter fixture: no migration warnings');

$emitter = new OpenApiEmitter(new CompileDiagnostics());
$doc = $emitter->emit($operations, title: 'Test API', version: '1.2.3');

// --- top-level shape (array-first) ---
assert_same('3.1.0', $doc['openapi'], 'openapi version 3.1.0');
assert_same('Test API', $doc['info']['title'], 'info.title honored');
assert_same('1.2.3', $doc['info']['version'], 'info.version honored');
assert_same(['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT'], $doc['components']['securitySchemes']['bearerAuth'], 'bearerAuth security scheme');

// the canonical Error envelope is always present
assert_true(isset($doc['components']['schemas']['Error']), 'the Error component is always emitted');
assert_same('object', $doc['components']['schemas']['Error']['type'], 'Error is an object envelope');

// --- object DTOs collected into components.schemas by short name, with the x-fqcn vendor tag ---
$schemas = $doc['components']['schemas'];
assert_true(isset($schemas['EmMeDto']), 'response DTO collected as a component');
assert_same(EmMeDto::class, $schemas['EmMeDto']['x-fqcn'], 'component carries its FQCN for ref resolution');
assert_true(isset($schemas['EmFriendDto']), 'nested DTO ref (EmMeDto::$friend) transitively collected');
// the nested ref is rendered as a $ref, the array-of-strings as inline items
assert_same(['$ref' => '#/components/schemas/EmFriendDto'], $schemas['EmMeDto']['properties']['friend'], 'nested object property is a $ref');
assert_same('array', $schemas['EmMeDto']['properties']['tags']['type'], 'array property projects type:array');
assert_same('string', $schemas['EmMeDto']['properties']['tags']['items']['type'], 'array items type from legacy OA items (parity fallback)');
// Regression (N probe): when legacy #[OA\Items(ref: X)] is used, items->type is swagger-php's UNDEFINED
// sentinel, NOT null. The element type must resolve from items->ref and the sentinel must never reach
// reflection — otherwise the emitter throws "Class @OA\Generator::UNDEFINED🙈 does not exist".
assert_same(['$ref' => '#/components/schemas/EmFriendDto'], $schemas['EmMeDto']['properties']['friends']['items'], 'array items ref resolved from legacy OA Items(ref) despite the UNDEFINED sentinel type');

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

// --- security projection ---
assert_same([['bearerAuth' => []]], $paths['/api/em/me']['get']['security'], 'authenticated op ⇒ bearerAuth security');
assert_same(['any' => ['admin'], 'all' => []], $paths['/api/em/list']['get']['x-required-rules'], 'access rules ⇒ x-required-rules');
assert_same([], $paths['/api/em/health']['get']['security'], 'NoAuthAccess ⇒ empty security (anonymous)');
assert_true(!array_key_exists('x-required-rules', $paths['/api/em/health']['get']), 'anonymous op carries no x-required-rules');

// --- YAML round-trip: array-first ⇒ dump ⇒ parse ⇒ identical array (deterministic) ---
$yaml = $emitter->toYaml($operations, title: 'Test API', version: '1.2.3');
$reparsed = Yaml::parse($yaml);
assert_same($doc, $reparsed, 'YAML round-trip is lossless — dump→parse reproduces the array exactly');

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

echo "OpenApiEmitter passed\n";
