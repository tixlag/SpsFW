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
    #[OA\Property(property: 'q', type: 'string', required: [true])]
    public string $q;
    #[OA\Property(property: 'limit', type: 'integer', required: [true])]
    public int $limit;
    #[OA\Property(property: 'cursor', type: 'string', nullable: true)]
    public ?string $cursor = null;
    // PHP non-nullable, no default, but OA required:[false] — the Oa/PhpType divergence point.
    #[OA\Property(property: 'opt', type: 'string', required: [false])]
    public string $opt;
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

/** a *Dto that customizes its JSON via JsonSerializable — eligible by suffix, but its shape is not derivable */
final class OpJsonDto implements \JsonSerializable
{
    public string $name;

    public function jsonSerialize(): array
    {
        return ['custom' => $this->name];
    }
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

    #[Route('/api/op/scalar', [HttpMethod::GET])]
    public function scalar(): int // scalar return ⇒ inline {integer} schema
    {
        return 0;
    }

    #[Route('/api/op/timestamp', [HttpMethod::GET])]
    public function timestamp(): \DateTimeImmutable // DateTimeInterface ⇒ inline {string, date-time}
    {
        return new \DateTimeImmutable();
    }

    #[Route('/api/op/collection', [HttpMethod::GET])]
    #[ApiResponse(schema: OpListResultDto::class, collection: true, description: 'a page of results')]
    public function collection(): Response // opaque Response + collection flag ⇒ array-of-schema
    {
        return new Response();
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

// --- query params projected from the #[QueryParams] DTO; required resolved through the OA source (parity) ---
$qs = $byMethod['list']->queryParams;
assert_same(4, count($qs), 'one query param per DTO property (incl. opt)');
$qBy = [];
foreach ($qs as $q) {
    $qBy[$q->name] = $q;
}
assert_same('string', $qBy['q']->type, 'string query param type');
assert_true($qBy['q']->required, 'Oa source: OA required:[true] ⇒ required query param');
assert_true($qBy['q']->isQuery(), 'query param classified as query');
assert_same('integer', $qBy['limit']->type, 'integer query param type');
assert_true(!$qBy['cursor']->required, 'cursor (nullable, no OA required) is optional');
assert_true(!$qBy['opt']->required, 'Oa source honours required:[false] even though PHP is non-nullable');
assert_same(null, $byMethod['list']->requestBody, 'QueryParams does not produce a requestBody');

// --- required source-mode divergence (plan §7): PhpType ignores OA and derives from non-nullability ---
$phpDiag = new CompileDiagnostics();
$phpCompiler = new RouteMetadataCompiler($phpDiag, requiredSource: \SpsFW\Core\Compile\Introspection\RequiredSource::PhpType);
$phpOps = $phpCompiler->compileOperationClasses([OpProjController::class]);
$phpBy = [];
foreach ($phpOps as $op) {
    $phpBy[$op->method] = $op;
}
$phpQ = [];
foreach ($phpBy['list']->queryParams as $q) {
    $phpQ[$q->name] = $q;
}
assert_true($phpQ['q']->required, 'PhpType source: non-nullable, no default ⇒ required');
assert_true($phpQ['opt']->required, 'PhpType source diverges from Oa: opt is PHP non-nullable ⇒ required here (was optional in Oa mode)');

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

// --- scalar / DateTime returns ⇒ inline schema fragments (type + format preserved) ---
$scalarSchema = $byMethod['scalar']->responses[0]->schema;
assert_same('integer', $scalarSchema->type, 'scalar int return ⇒ inline {integer} schema');
assert_true(!$scalarSchema->isEnum && $scalarSchema->className === null, 'scalar return is an inline fragment, not an object/enum');

$tsSchema = $byMethod['timestamp']->responses[0]->schema;
assert_same('string', $tsSchema->type, 'DateTimeImmutable return ⇒ inline {string} schema');
assert_same('date-time', $tsSchema->format, 'DateTimeImmutable format is preserved (date-time)');

// --- #[Response(collection: true)] ⇒ array-of-schema, item shape preserved in arrayItem ---
$collectionResp = $byMethod['collection']->responses[0];
assert_same(null, $collectionResp->schema, 'collection response: schema is null (the body is an array, not a single object)');
assert_same(OpListResultDto::class, $collectionResp->arrayItem?->className, 'collection response: the item shape is preserved in arrayItem');
assert_same('a page of results', $collectionResp->description, 'collection response: declared description preserved');

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

// ============================================================================
// Response-projection diagnostics (Step 3 fix-pass): missing return type, mixed, and a
// JsonSerializable DTO each surface a distinct compile error (plan §6/§7).
// ============================================================================
final class OpRespDiagController
{
    #[Route('/api/op/noreturn', [HttpMethod::GET])]
    public function noReturn() // no return type ⇒ cannot infer the schema
    {
    }

    #[Route('/api/op/mixed', [HttpMethod::GET])]
    public function mixedReturn(): mixed // mixed ⇒ opaque, not derivable
    {
        return null;
    }

    #[Route('/api/op/jsonser', [HttpMethod::GET])]
    public function jsonSer(): OpJsonDto // *Dto but JsonSerializable ⇒ shape not derivable
    {
        return new OpJsonDto();
    }
}

$respDiag = new CompileDiagnostics();
(new RouteMetadataCompiler($respDiag))->compileOperationClasses([OpRespDiagController::class]);
assert_true($respDiag->hasErrors(), 'response-projection controller surfaces errors');
assert_same(3, $respDiag->count(), 'exactly three response diagnostics: missing return type, mixed, JsonSerializable');

$respCauses = implode("\n", array_map(static fn(array $e): string => $e['cause'], $respDiag->errors()));
assert_true(str_contains($respCauses, 'declares no return type'), 'missing-return-type diagnostic present');
assert_true(str_contains($respCauses, 'mixed return type is not auto-derivable'), 'mixed-return diagnostic present');
assert_true(str_contains($respCauses, 'implements JsonSerializable'), 'JsonSerializable diagnostic present');

// ============================================================================
// Tri-state operationId lockfile (plan §19, Step 3 fix-pass): a preserved id, a deferred (null) op, and a
// brand-new (absent) op resolve exactly as the materialized inventory demands. On the real `next` inventory
// this is the 39 preserved + 306 null + convention-for-new split; an empty map (the earlier bug) would have
// wrongly assigned the convention to all 306 deferred ops.
// ============================================================================
final class OpLockController
{
    #[Route('/api/op/lock/preserved')]
    public function preserved(): void
    {
    }

    #[Route('/api/op/lock/deferred')]
    public function deferred(): void
    {
    }

    #[Route('/api/op/lock/brandnew')]
    public function brandNew(): void
    {
    }
}
$lockMap = [
    OpLockController::class . '::preserved' => 'legacyPreservedId',
    OpLockController::class . '::deferred' => null, // stays id-less (a deferred legacy op)
    // brandNew is ABSENT ⇒ the convention applies (a genuinely new operation)
];
$lockDiag = new CompileDiagnostics();
$lockCompiler = new RouteMetadataCompiler($lockDiag, operationIdMap: $lockMap);
$lockOps = $lockCompiler->compileOperationClasses([OpLockController::class]);
$lockBy = [];
foreach ($lockOps as $op) {
    $lockBy[$op->method] = $op;
}
assert_same('legacyPreservedId', $lockBy['preserved']->operationId, 'tri-state: a lockfile string id is preserved verbatim');
assert_same(null, $lockBy['deferred']->operationId, 'tri-state: a lockfile null keeps the op id-less (does NOT fall through to convention)');
assert_same('OpLockBrandNew', $lockBy['brandNew']->operationId, 'tri-state: an absent key gets the controller-qualified convention id');
assert_true(!$lockDiag->hasErrors(), 'tri-state lockfile with unique non-null ids produces no diagnostics');

echo "OperationProjection passed\n";
