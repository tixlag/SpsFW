<?php

declare(strict_types=1);

use SpsFW\Core\Attributes\OpenApi\Operation;
use SpsFW\Core\Attributes\OpenApi\Response as ApiResponse;
use SpsFW\Core\Attributes\Route;
use SpsFW\Core\Compile\CompileDiagnostics;
use SpsFW\Core\Compile\Route\RouteMetadataCompiler;
use SpsFW\Core\Http\HttpMethod;
use SpsFW\Core\Http\Response;

require_once dirname(__DIR__, 2) . '/bootstrap.php';

/**
 * M8b: Route-first / inference-first operation contract at the COMPILER level (RouteMetadataCompiler).
 * Pins: the 2-arg Route BC, the `returns` shapes (single/list/scalar/object + their lists), the forbidden
 * `returns`/`errors` shapes (compile ERRORs), Route→OperationMetadata propagation, documented:false exclusion,
 * the #[Operation] BC fallback + Route↔Operation conflict diagnostic, native DTO inference, the Dto|Response
 * non-definite union, AST inference, Response::error ignored as success, error projection (Route::errors list/map
 * + AST literal + dynamic), and the inferred+explicit merge with same-status conflict.
 */

// --- shared fixtures (short names end in Dto ⇒ DTO-eligible; RcaEntity is deliberately NOT eligible) ----------
class RcaUserDto
{
    public string $id = '';
}
class RcaItemDto
{
    public string $sku = '';
}
class RcaEntity
{
    public string $name = '';
}

function rcaCompile(string $class, CompileDiagnostics $diag): array
{
    return (new RouteMetadataCompiler($diag))->compileOperationClasses([$class]);
}

// ============================================================================
// 1. `returns` shapes resolve to the right success ResponseMetadata.
// ============================================================================
final class RcaReturnsController
{
    #[Route('/rca/single', [HttpMethod::GET], returns: RcaUserDto::class)]
    public function single() {}

    #[Route('/rca/list', [HttpMethod::GET], returns: [RcaUserDto::class])]
    public function list() {}

    #[Route('/rca/scalar', [HttpMethod::GET], returns: 'string')]
    public function scalar() {}

    #[Route('/rca/scalarlist', [HttpMethod::GET], returns: ['integer'])]
    public function scalarList() {}

    #[Route('/rca/object', [HttpMethod::GET], returns: 'object')]
    public function objectBody() {}

    #[Route('/rca/status', [HttpMethod::POST], returns: RcaUserDto::class, successStatus: 201)]
    public function withStatus() {}
}
$d = new CompileDiagnostics();
$ops = rcaCompile(RcaReturnsController::class, $d);
assert_true(!$d->hasErrors() && !$d->hasWarnings(), 'returns shapes: no diagnostics');
$byPath = [];
foreach ($ops as $op) {
    $byPath[$op->path] = $op;
}
assert_same(RcaUserDto::class, $byPath['/rca/single']->responses[0]->schema->className, 'returns: Dto::class ⇒ single object ref');
assert_same(null, $byPath['/rca/list']->responses[0]->schema, 'returns: [Dto::class] ⇒ array body (schema null)');
assert_same(RcaUserDto::class, $byPath['/rca/list']->responses[0]->arrayItem->className, 'returns: [Dto::class] ⇒ arrayItem is the DTO');
assert_same('string', $byPath['/rca/scalar']->responses[0]->schema->type, "returns: 'string' ⇒ inline string");
assert_same('integer', $byPath['/rca/scalarlist']->responses[0]->arrayItem->type, "returns: ['integer'] ⇒ inline integer list");
assert_same(null, $byPath['/rca/scalarlist']->responses[0]->schema, "returns: ['integer'] ⇒ scalar list has null schema");
assert_same('object', $byPath['/rca/object']->responses[0]->schema->type, "returns: 'object' ⇒ free-form object");
assert_same(201, $byPath['/rca/status']->responses[0]->status, 'successStatus: 201 rides on the success response');

// ============================================================================
// 2. Forbidden `returns` shapes are compile ERRORs.
// ============================================================================
final class RcaBadReturnsController
{
    #[Route('/rca/empty', [HttpMethod::GET], returns: [])]
    public function emptyRet() {}

    #[Route('/rca/multi', [HttpMethod::GET], returns: [RcaUserDto::class, RcaItemDto::class])]
    public function multi() {}

    #[Route('/rca/nested', [HttpMethod::GET], returns: [[RcaUserDto::class]])]
    public function nested() {}

    #[Route('/rca/badscalar', [HttpMethod::GET], returns: 'float')]
    public function badScalar() {}

    #[Route('/rca/noclass', [HttpMethod::GET], returns: 'No\Such\Class')]
    public function noClass() {}

    #[Route('/rca/unfit', [HttpMethod::GET], returns: RcaEntity::class)]
    public function unfit() {}

    #[Route('/rca/nobody204', [HttpMethod::GET], returns: RcaUserDto::class, successStatus: 204)]
    public function nobody204() {}
}
$bad = new CompileDiagnostics();
rcaCompile(RcaBadReturnsController::class, $bad);
assert_same(7, $bad->errorCount(), 'seven forbidden returns shapes each surface a compile ERROR');

// ============================================================================
// 3. Route → OperationMetadata propagation.
// ============================================================================
final class RcaPropController
{
    #[Route('/rca/prop', [HttpMethod::GET], summary: 'Summary', description: 'Desc', operationId: 'customId', tags: ['t1', 't2'], deprecated: true)]
    public function prop() {}
}
$pd = new CompileDiagnostics();
$pop = rcaCompile(RcaPropController::class, $pd)[0];
assert_same('Summary', $pop->summary, 'Route summary propagates');
assert_same('Desc', $pop->description, 'Route description propagates');
assert_same('customId', $pop->operationId, 'Route operationId propagates');
assert_same(['t1', 't2'], $pop->tags, 'Route tags propagate');
assert_true($pop->deprecated, 'Route deprecated propagates');

// ============================================================================
// 4. documented:false excludes the operation (legacy #[Operation(exclude:true)] still works too).
// ============================================================================
final class RcaHiddenController
{
    #[Route('/rca/hidden', [HttpMethod::GET], documented: false)]
    public function hidden() {}

    #[Route('/rca/legacy-exclude', [HttpMethod::GET])]
    #[Operation(exclude: true)]
    public function legacyExclude() {}

    #[Route('/rca/visible', [HttpMethod::GET])]
    public function visible() {}
}
$hd = new CompileDiagnostics();
$hops = rcaCompile(RcaHiddenController::class, $hd);
assert_same(1, count($hops), 'only the documented operation is projected');
assert_same('/rca/visible', $hops[0]->path, 'documented:false and Operation(exclude:true) are both hidden');

// ============================================================================
// 5. #[Operation] BC fallback + Route↔Operation conflict.
// ============================================================================
final class RcaOperationController
{
    #[Route('/rca/opfallback', [HttpMethod::GET])]
    #[Operation(summary: 'FromOp')]
    public function fallback() {}

    #[Route('/rca/opconflict', [HttpMethod::GET], summary: 'FromRoute')]
    #[Operation(summary: 'FromOp')]
    public function conflict() {}
}
$od = new CompileDiagnostics();
$o = rcaCompile(RcaOperationController::class, $od);
$byPath = [];
foreach ($o as $op) {
    $byPath[$op->path] = $op;
}
assert_same('FromOp', $byPath['/rca/opfallback']->summary, 'Operation summary used when Route has none (BC fallback)');
assert_same('FromRoute', $byPath['/rca/opconflict']->summary, 'Route summary wins on conflict');
assert_true($od->hasWarnings(), 'a Route↔Operation summary conflict surfaces a warning');

// ============================================================================
// 6. Native DTO inference (no returns, no ApiResponse).
// ============================================================================
final class RcaNativeController
{
    #[Route('/rca/native', [HttpMethod::GET])]
    public function native(): RcaUserDto
    {
        return new RcaUserDto();
    }
}
$nd = new CompileDiagnostics();
$nop = rcaCompile(RcaNativeController::class, $nd)[0];
assert_same(RcaUserDto::class, $nop->responses[0]->schema->className, 'native DTO return type ⇒ inferred object schema');
assert_true(!$nd->hasWarnings(), 'a definite native DTO inference emits no diagnostic');

// ============================================================================
// 7. Dto|Response union resolves to the DTO (D2): Response is a transport branch, excluded; the remaining DTO
//    is the definite success body. Multiple different DTOs in the union stay ambiguous (non-definite).
// ============================================================================
final class RcaUnionController
{
    #[Route('/rca/union', [HttpMethod::GET])]
    public function union(): RcaUserDto|Response
    {
        return new RcaUserDto();
    }

    #[Route('/rca/unionnull', [HttpMethod::GET])]
    public function unionNull(): RcaUserDto|Response|null
    {
        return new RcaUserDto();
    }

    #[Route('/rca/unionambiguous', [HttpMethod::GET])]
    public function unionAmbiguous(): RcaUserDto|RcaItemDto|null
    {
        return new RcaUserDto();
    }
}
$ud = new CompileDiagnostics();
$ubyPath = [];
foreach (rcaCompile(RcaUnionController::class, $ud) as $op) {
    $ubyPath[$op->path] = $op;
}
assert_true(!$ud->hasErrors(), 'Dto|Response(|null) unions are derivable (no errors; the ambiguous multi-DTO union warns separately)');
assert_same(RcaUserDto::class, $ubyPath['/rca/union']->responses[0]->schema->className, 'Dto|Response ⇒ the DTO after excluding the Response transport');
assert_same(RcaUserDto::class, $ubyPath['/rca/unionnull']->responses[0]->schema->className, 'Dto|Response|null ⇒ the DTO after excluding Response and null');
assert_same(null, $ubyPath['/rca/unionambiguous']->responses[0]->schema, 'two different DTOs in the union ⇒ ambiguous, empty body');
assert_true($ud->hasWarnings(), 'the ambiguous multi-DTO union surfaces a diagnostic suggesting returns');

// ============================================================================
// 8. AST inference: `: Response` + `Response::json(new Dto())` ⇒ AST-derived schema.
// ============================================================================
final class RcaAstController
{
    #[Route('/rca/ast', [HttpMethod::GET])]
    public function ast(): Response
    {
        return Response::json(new RcaUserDto());
    }

    #[Route('/rca/astcoll', [HttpMethod::GET])]
    public function astCollection(): Response
    {
        return Response::json([new RcaItemDto(), new RcaItemDto()]);
    }

    #[Route('/rca/ast201', [HttpMethod::GET])]
    public function ast201(): Response
    {
        return Response::json(new RcaUserDto(), 201);
    }
}
$ad = new CompileDiagnostics();
$a = rcaCompile(RcaAstController::class, $ad);
$byPath = [];
foreach ($a as $op) {
    $byPath[$op->path] = $op;
}
assert_same(RcaUserDto::class, $byPath['/rca/ast']->responses[0]->schema->className, 'AST infers the DTO from Response::json(new Dto())');
assert_same(RcaItemDto::class, $byPath['/rca/astcoll']->responses[0]->arrayItem->className, 'AST infers a homogeneous collection');
assert_same(201, $byPath['/rca/ast201']->responses[0]->status, 'AST literal 201 status rides on the success response');
assert_true(!$ad->hasWarnings(), 'definite AST inference emits no diagnostic');

// ============================================================================
// 9. Response::error is ignored as a success body; it is NO LONGER projected as an error status (the fix-pass
//    removed AST error inference — endpoint-specific codes come only from Route::errors / explicit #[ApiResponse]).
// ============================================================================
final class RcaErrorController
{
    #[Route('/rca/ei', [HttpMethod::GET], returns: RcaUserDto::class)]
    public function errorIgnored(): Response
    {
        return Response::error(null, statusCode: 404);
    }
}
$ed = new CompileDiagnostics();
$eop = rcaCompile(RcaErrorController::class, $ed)[0];
assert_same(RcaUserDto::class, $eop->responses[0]->schema->className, 'returns defines the success; Response::error does not replace it');
assert_same([], $eop->routeErrors, 'a literal Response::error status is NOT projected into routeErrors (no AST error inference)');
assert_true(!$ed->hasErrors(), 'no contradiction: returns + a Response::error body');

// ============================================================================
// 10. Route::errors: list/map forms, mixed & out-of-range rejected, standard-code override.
// ============================================================================
final class RcaErrorsController
{
    #[Route('/rca/errlist', [HttpMethod::GET], returns: RcaUserDto::class, errors: [404, 409])]
    public function errList() {}

    #[Route('/rca/errmap', [HttpMethod::GET], returns: RcaUserDto::class, errors: [404 => 'Not Found'])]
    public function errMap() {}

    #[Route('/rca/errmixed', [HttpMethod::GET], returns: RcaUserDto::class, errors: [404, 409 => 'x'])]
    public function errMixed() {}

    #[Route('/rca/errrange', [HttpMethod::GET], returns: RcaUserDto::class, errors: [700])]
    public function errRange() {}

    #[Route('/rca/erroverride', [HttpMethod::GET], returns: RcaUserDto::class, errors: [500 => 'Boom'])]
    public function errOverride() {}
}
$erd = new CompileDiagnostics();
$e = rcaCompile(RcaErrorsController::class, $erd);
$byPath = [];
foreach ($e as $op) {
    $byPath[$op->path] = $op;
}
assert_same([404, 409], array_map(static fn(array $x): int => $x[0], $byPath['/rca/errlist']->routeErrors), 'errors list form ⇒ [404,409]');
assert_same([404], array_map(static fn(array $x): int => $x[0], $byPath['/rca/errmap']->routeErrors), 'errors map form ⇒ [404]');
assert_same('Not Found', $byPath['/rca/errmap']->routeErrors[0][1], 'errors map form carries the description');
assert_same([500], array_map(static fn(array $x): int => $x[0], $byPath['/rca/erroverride']->routeErrors), 'a standard code may be repeated to override its description');
assert_same(2, $erd->errorCount(), 'mixed form and out-of-range code are each compile ERRORs');

// ============================================================================
// 11. Explicit #[Route] fields are canonical (D1): an explicitly passed bool/int — even one equal to the
//     default — wins over #[Operation], and is NOT treated as a fallback. The getArguments distinction is what
//     lets `deprecated:false` / `documented:true` / `successStatus:201` take effect.
// ============================================================================
final class RcaExplicitController
{
    #[Route('/rca/explicitdep', [HttpMethod::GET], deprecated: false)]
    #[Operation(deprecated: true)]
    public function explicitDeprecated() {}

    #[Route('/rca/explicitdoc', [HttpMethod::GET], documented: true)]
    #[Operation(exclude: true)]
    public function explicitDocumented() {}

    #[Route('/rca/explicitstatus', [HttpMethod::GET], successStatus: 201)]
    public function explicitStatus(): Response
    {
        return Response::json(new RcaUserDto());
    }
}
$exd = new CompileDiagnostics();
$ex = [];
foreach (rcaCompile(RcaExplicitController::class, $exd) as $op) {
    $ex[$op->path] = $op;
}
assert_true(!$ex['/rca/explicitdep']->deprecated, 'explicit deprecated:false beats Operation(deprecated:true) (Route canonical, no fallback)');
assert_same(1, count(array_filter($exd->warnings(), static fn (array $w): bool => $w['field'] === 'deprecated')), 'the deprecated:false vs Operation(deprecated:true) conflict is surfaced');
assert_true(isset($ex['/rca/explicitdoc']), 'explicit documented:true beats Operation(exclude:true) — the operation stays documented (NOT excluded)');
assert_same(201, $ex['/rca/explicitstatus']->responses[0]->status, 'explicit successStatus:201 beats the AST-inferred default status');

// ============================================================================
// 12. D3 — success branches with the SAME schema but DIFFERENT statuses do not collapse to the last branch.
// ============================================================================
final class RcaStatusConflictController
{
    #[Route('/rca/statusconflict', [HttpMethod::GET])]
    public function conflict(bool $ok): Response
    {
        if ($ok) {
            return Response::json(new RcaUserDto(), 201);
        }
        return Response::json(new RcaUserDto(), 200);
    }
}
$scd = new CompileDiagnostics();
$scop = rcaCompile(RcaStatusConflictController::class, $scd)[0];
assert_same(RcaUserDto::class, $scop->responses[0]->schema->className, 'same-DTO branches ⇒ definite schema regardless of status');
assert_same(200, $scop->responses[0]->status, 'differing branch statuses do NOT collapse to the last branch (201) — fall back to 200');
assert_true($scd->hasWarnings(), 'differing success statuses surface a diagnostic');

// ============================================================================
// 13. D4 — a success body at status 204 is an ERROR regardless of its source (returns / native / AST / ApiResponse).
// ============================================================================
final class RcaNoBody204Controller
{
    #[Route('/rca/204returns', [HttpMethod::GET], returns: RcaUserDto::class, successStatus: 204)]
    public function fromReturns() {}

    #[Route('/rca/204native', [HttpMethod::GET], successStatus: 204)]
    public function fromNative(): RcaUserDto
    {
        return new RcaUserDto();
    }

    #[Route('/rca/204ast', [HttpMethod::GET], successStatus: 204)]
    public function fromAst(): Response
    {
        return Response::json(new RcaUserDto());
    }

    #[Route('/rca/204api', [HttpMethod::GET], successStatus: 204)]
    #[ApiResponse(RcaUserDto::class, 204)]
    public function fromApiResponse() {}
}
$nd = new CompileDiagnostics();
rcaCompile(RcaNoBody204Controller::class, $nd);
assert_same(4, $nd->errorCount(), 'a body at status 204 is an ERROR from every source (returns/native/AST/ApiResponse)');

// ============================================================================
// 14. Inferred + explicit merge; same-status different-schema is an ERROR.
// ============================================================================
final class RcaMergeController
{
    #[Route('/rca/merge', [HttpMethod::GET])]
    #[ApiResponse(RcaUserDto::class, 200)]
    #[ApiResponse(null, 404)]
    public function merge(): Response
    {
        return new RcaUserDto();
    }
}
$md = new CompileDiagnostics();
$mop = rcaCompile(RcaMergeController::class, $md)[0];
assert_same(2, count($mop->responses), 'success ApiResponse + error ApiResponse merge (not replace)');
assert_same(RcaUserDto::class, $mop->responses[0]->schema->className, 'the 2xx ApiResponse is the success');
assert_same(404, $mop->responses[1]->status, 'the 404 ApiResponse supplements the success');

final class RcaConflictRespController
{
    #[Route('/rca/cr', [HttpMethod::GET], returns: RcaUserDto::class)]
    #[ApiResponse(RcaItemDto::class, 200)]
    public function conflict(): Response
    {
        return new RcaUserDto();
    }
}
$cd = new CompileDiagnostics();
rcaCompile(RcaConflictRespController::class, $cd);
assert_true($cd->hasErrors(), 'a declared ApiResponse redeclaring the success status with a different schema is a compile ERROR');

echo "Route contract passed\n";
