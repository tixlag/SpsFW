<?php

declare(strict_types=1);

use OpenApi\Attributes as OA;
use SpsFW\Core\Attributes\AccessRulesAll;
use SpsFW\Core\Attributes\AccessRulesAny;
use SpsFW\Core\Attributes\NoAuthAccess;
use SpsFW\Core\Attributes\OpenApi\Operation;
use SpsFW\Core\Attributes\OpenApi\Response as ApiResponse;
use SpsFW\Core\Attributes\Route;
use SpsFW\Core\Attributes\Validation\JsonBody;
use SpsFW\Core\Attributes\Validation\QueryParams;
use SpsFW\Core\Compile\CompileDiagnostics;
use SpsFW\Core\Compile\Metadata\ParameterMetadata;
use SpsFW\Core\Compile\Route\RouteMetadataCompiler;
use SpsFW\Core\Http\HttpMethod;
use SpsFW\Core\Http\Response;

require_once dirname(__DIR__, 2) . '/bootstrap.php';

/**
 * Шаг 3 (M3): the OpenAPI documentation projection — RouteMetadataCompiler::compileOperations() produces
 * OperationMetadata from the same reflection as the route IR: operationId, path/query params, requestBody,
 * responses (return-type inference + #[Response]), security (access rules), tags. Runtime IR is untouched
 * (covered by RouteMetadataCompilerTest); this pins the doc projection and the three doc diagnostics
 * (path-param mismatch, non-eligible return-type without #[Response], array/union return without #[Response]).
 */

// --- response/DTO fixtures ---
enum OpMode: string
{
    case Active = 'active';
    case Disabled = 'disabled';
}

final class OpMeResultDto
{
    #[OA\Property(type: 'string')]
    public string $name;
}
final class OpExamDto
{
    #[OA\Property(type: 'string')]
    public string $title;
}
final class OpListDto
{
    #[OA\Property(property: 'q', type: 'string')]
    public string $q;
    #[OA\Property(property: 'limit', type: 'integer')]
    public int $limit;
    #[OA\Property(property: 'cursor', type: 'string', nullable: true)]
    public ?string $cursor = null;
}
final class OpListResultDto
{
    #[OA\Property(type: 'integer')]
    public int $total;
}
final class OpCreateDto
{
    #[OA\Property(property: 'email', type: 'string', format: 'email')]
    public string $email;
}
final class OpCreateResultDto
{
    #[OA\Property(type: 'integer')]
    public int $id;
}
final class OpSecuredDto
{
    #[OA\Property(type: 'string')]
    public string $token;
}
final class OpTaggedDto
{
    #[OA\Property(type: 'string')]
    public string $label;
}
/** a domain ENTITY (not *Dto) — not auto-derivable as a schema without an explicit #[Response] */
final class OpEntity
{
    public string $name;
}

final class OpProjController
{
    #[Route('/api/op/me')] // default GET; *Dto return ⇒ inferred 200 schema
    public function me(): OpMeResultDto
    {
        return new OpMeResultDto();
    }

    #[Route('/api/op/exam/{examId}', [HttpMethod::GET])]
    public function getExam(int $examId): OpExamDto
    {
        return new OpExamDto();
    }

    #[Route('/api/op/list', [HttpMethod::GET])]
    public function list(#[QueryParams] OpListDto $dto): OpListResultDto
    {
        return new OpListResultDto();
    }

    #[Route('/api/op/create', [HttpMethod::POST])]
    public function create(#[JsonBody] OpCreateDto $dto): OpCreateResultDto
    {
        return new OpCreateResultDto();
    }

    #[Route('/api/op/mode', [HttpMethod::GET])]
    public function mode(): OpMode // backed enum ⇒ inferred 200 enum schema
    {
        return OpMode::Active;
    }

    #[Route('/api/op/health', [HttpMethod::GET])]
    #[NoAuthAccess]
    public function health(): void // void return + anonymous security
    {
    }

    #[Route('/api/op/explicit', [HttpMethod::POST])]
    #[ApiResponse(schema: OpCreateResultDto::class, status: 201, description: 'created')]
    public function explicit(#[JsonBody] OpCreateDto $dto): Response // opaque return OK — #[Response] declares it
    {
        return new Response();
    }

    #[Route('/api/op/secured', [HttpMethod::POST])]
    #[AccessRulesAny(['admin'])]
    #[AccessRulesAll(['verified'])]
    public function secured(): OpSecuredDto
    {
        return new OpSecuredDto();
    }

    #[Route('/api/op/tagged', [HttpMethod::GET])]
    #[Operation(id: 'customOperationId', tags: ['custom'], summary: 'sum', deprecated: true)]
    public function tagged(): OpTaggedDto
    {
        return new OpTaggedDto();
    }

    #[Route('/api/op/excluded', [HttpMethod::GET])]
    #[Operation(exclude: true)]
    public function hidden(): OpTaggedDto // excluded — must NOT appear in the spec
    {
        return new OpTaggedDto();
    }
}

$projDiag = new CompileDiagnostics();
$projCompiler = new RouteMetadataCompiler($projDiag);
$ops = $projCompiler->compileOperationClasses([OpProjController::class]);
assert_true(!$projDiag->hasErrors(), 'an all-eligible controller projects with no diagnostics');

$byMethod = [];
foreach ($ops as $op) {
    $byMethod[$op->method] = $op;
}

// --- operationId (convention) + tags default + inferred 200 schema ---
assert_same('OpProjMe', $byMethod['me']->operationId, 'convention operationId = <ControllerShort><Method>');
assert_same(['OpProj'], $byMethod['me']->tags, 'tags default to [controllerShort]');
assert_same('GET', $byMethod['me']->httpMethod, 'default http method GET');
assert_same(OpMeResultDto::class, $byMethod['me']->responses[0]->schema->className, '*Dto return inferred as 200 schema ref');
assert_same(200, $byMethod['me']->responses[0]->status, 'inferred success is 200');
assert_same('bearerAuth', $byMethod['me']->security->scheme, 'authenticated op carries bearerAuth');
assert_true(!$byMethod['me']->security->hasRules(), 'no access rules ⇒ empty required-rules');

// --- path param: PHP int type wins over the historical OA string ---
$examParam = $byMethod['getExam']->pathParams[0];
assert_same('examId', $examParam->name, 'path param name is the raw placeholder');
assert_true($examParam->isPath(), 'path param classified as path');
assert_true($examParam->required, 'path params are always required');
assert_same('integer', $examParam->type, 'int path param ⇒ integer (not the legacy OA string)');

// --- query params projected from the #[QueryParams] DTO, optionality from PHP type ---
$qs = $byMethod['list']->queryParams;
assert_same(3, count($qs), 'one query param per DTO property');
$qBy = [];
foreach ($qs as $q) {
    $qBy[$q->name] = $q;
}
assert_same('string', $qBy['q']->type, 'string query param type');
assert_true($qBy['q']->required, 'non-nullable query param is required');
assert_true($qBy['q']->isQuery(), 'query param classified as query');
assert_same('integer', $qBy['limit']->type, 'integer query param type');
assert_true(!$qBy['cursor']->required, 'nullable query param (?string = null) is optional');
assert_same(null, $byMethod['list']->requestBody, 'QueryParams does not produce a requestBody');

// --- requestBody from #[JsonBody] ---
$body = $byMethod['create']->requestBody;
assert_same('application/json', $body->contentType, 'JsonBody ⇒ application/json');
assert_true($body->required, 'JsonBody without a default is required');
assert_same(OpCreateDto::class, $body->schema->className, 'request body schema is the bound DTO');

// --- enum return ⇒ enum schema fragment ---
$modeSchema = $byMethod['mode']->responses[0]->schema;
assert_true($modeSchema->isEnum, 'backed-enum return ⇒ enum schema');
assert_same('string', $modeSchema->enumType, 'string-backed enum type');
assert_same(['active', 'disabled'], $modeSchema->enumCases, 'enum cases are the backed values');

// --- void return + anonymous security ---
assert_same(null, $byMethod['health']->security->scheme, 'NoAuthAccess ⇒ anonymous (no security)');
assert_true($byMethod['health']->security->isAnonymous(), 'anonymous projection helper');
assert_same(null, $byMethod['health']->responses[0]->schema, 'void return ⇒ no schema');
assert_true(!$byMethod['health']->security->hasRules(), 'anonymous op has no required-rules');

// --- explicit #[Response] overrides inference (opaque Response return is then fine) ---
$explicit = $byMethod['explicit']->responses;
assert_same(1, count($explicit), 'declared #[Response] is the only response (no auto-200 added)');
assert_same(201, $explicit[0]->status, 'declared status preserved');
assert_same('created', $explicit[0]->description, 'declared description preserved');
assert_same(OpCreateResultDto::class, $explicit[0]->schema->className, 'declared schema projected');

// --- security: any/all kept independent (unlike runtime access_rules which collapses All-only to []) ---
$secured = $byMethod['secured']->security;
assert_same(['admin'], $secured->requiredRules['any'], 'AccessRulesAny ⇒ requiredRules.any');
assert_same(['verified'], $secured->requiredRules['all'], 'AccessRulesAll ⇒ requiredRules.all (projection keeps it, runtime would not)');
assert_true($secured->hasRules(), 'security has rules when any/all present');

// --- #[Operation] override ---
assert_same('customOperationId', $byMethod['tagged']->operationId, 'explicit Operation.id wins over convention');
assert_same(['custom'], $byMethod['tagged']->tags, 'explicit Operation.tags override the default');
assert_same('sum', $byMethod['tagged']->summary, 'Operation.summary preserved');
assert_true($byMethod['tagged']->deprecated, 'Operation.deprecated preserved');

// --- #[Operation(exclude: true)] drops the operation entirely ---
assert_true(!array_key_exists('hidden', $byMethod), 'Operation(exclude: true) omits the operation from the spec');

// ============================================================================
// Diagnostics: the three doc-level halts (plan §7).
// ============================================================================
final class OpDiagController
{
    #[Route('/api/op/entity', [HttpMethod::GET])]
    public function entity(): OpEntity // entity (not *Dto) ⇒ non-eligible return without #[Response]
    {
        return new OpEntity();
    }

    #[Route('/api/op/array', [HttpMethod::GET])]
    public function arr(): array // array return, no item type, no #[Response]
    {
        return [];
    }

    #[Route('/api/op/union', [HttpMethod::GET])]
    public function uni(): int|string // genuine union ⇒ unsupported
    {
        return 0;
    }

    #[Route('/api/op/badpath/{missing}', [HttpMethod::GET])]
    public function badPath(): void // path placeholder has no matching method param
    {
    }
}

$diag = new CompileDiagnostics();
(new RouteMetadataCompiler($diag))->compileOperationClasses([OpDiagController::class]);
assert_true($diag->hasErrors(), 'diagnostic controller surfaces errors');
assert_same(4, $diag->count(), 'exactly four doc diagnostics: non-eligible entity, itemless array, union, path mismatch');

// field distribution: three return diagnostics + one path-mismatch diagnostic
$fieldCounts = [];
foreach ($diag->errors() as $err) {
    $fieldCounts[$err['field']] = ($fieldCounts[$err['field']] ?? 0) + 1;
}
assert_same(3, $fieldCounts['return'], 'three return-field diagnostics (entity / array / union)');
assert_same(1, $fieldCounts['missing'], 'one path-param mismatch diagnostic');

// each distinct cause is present (entity/array/union share field=return, so assert over the joined causes)
$causes = implode("\n", array_map(static fn(array $e): string => $e['cause'], $diag->errors()));
assert_true(str_contains($causes, 'not a DTO-eligible class'), 'non-eligible class diagnostic present');
assert_true(str_contains($causes, 'array return type has no derivable item type'), 'itemless array diagnostic present');
assert_true(str_contains($causes, 'is not auto-derivable: union'), 'union return diagnostic present (member order is PHP-normalized)');
assert_true(str_contains($causes, 'path parameter {missing} has no matching method parameter'), 'path-param mismatch diagnostic present');

// the entity diagnostic carries the entity FQCN in its `dto` slot
$entityErr = null;
foreach ($diag->errors() as $err) {
    if ($err['dto'] === OpEntity::class) {
        $entityErr = $err;
        break;
    }
}
assert_true($entityErr !== null, 'non-eligible diagnostic carries the entity FQCN in dto');
assert_same('return', $entityErr['field'], 'entity diagnostic tagged on the return field');

echo "OperationProjection passed\n";
