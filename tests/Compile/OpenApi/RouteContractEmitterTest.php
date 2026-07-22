<?php

declare(strict_types=1);

use SpsFW\Core\Attributes\NoAuthAccess;
use SpsFW\Core\Attributes\Route;
use SpsFW\Core\Compile\CompileDiagnostics;
use SpsFW\Core\Compile\OpenApi\OpenApiEmitter;
use SpsFW\Core\Compile\Route\RouteMetadataCompiler;
use SpsFW\Core\Http\HttpMethod;
use SpsFW\Core\Http\Response;

require_once dirname(__DIR__, 2) . '/bootstrap.php';

/**
 * M8b + fix-pass: the OpenApiEmitter response map (buildResponses) — Route::errors description override, a new
 * code added with the Error $ref (no duplication of policy codes), numeric status ordering preserved, and NO
 * `default` synthesized from a computed (dynamic) error status (the fix-pass removed AST error inference). All
 * cases use NoAuthAccess + a returns body so the standard policy contributes ONLY 500 (clean isolation).
 */
class RceDto
{
    public string $id = '';
}

final class RceEmitterController
{
    #[Route('/rce/override', [HttpMethod::GET], returns: RceDto::class, errors: [500 => 'Custom 500'])]
    #[NoAuthAccess]
    public function override() {}

    #[Route('/rce/add', [HttpMethod::GET], returns: RceDto::class, errors: [409])]
    #[NoAuthAccess]
    public function add() {}

    #[Route('/rce/dyn', [HttpMethod::GET], returns: RceDto::class)]
    #[NoAuthAccess]
    public function dyn(): Response
    {
        $code = 404;
        return Response::error(null, statusCode: $code);
    }

    #[Route('/rce/plain', [HttpMethod::GET], returns: RceDto::class)]
    #[NoAuthAccess]
    public function plain() {}
}

$diag = new CompileDiagnostics();
$operations = (new RouteMetadataCompiler($diag))->compileOperationClasses([RceEmitterController::class]);
assert_true(!$diag->hasErrors(), 'RCE controller compiles without errors');
$doc = (new OpenApiEmitter($diag))->emit($operations);

$responses = static function (string $path) use ($doc): array {
    return $doc['paths'][$path]['get']['responses'];
};

// override: the policy 500 description is overridden by Route::errors; still a single 500, Error schema.
$ov = $responses('/rce/override');
assert_same('Custom 500', $ov['500']['description'], 'Route::errors overrides the standard 500 description');
assert_same('#/components/schemas/Error', $ov['500']['content']['application/json']['schema']['$ref'], 'overridden 500 keeps the Error schema');
assert_true(!isset($ov['501']), 'no spurious statuses');

// add: a non-standard code is added with the Error $ref; the policy 500 is still present (no duplication).
$ad = $responses('/rce/add');
assert_same('#/components/schemas/Error', $ad['409']['content']['application/json']['schema']['$ref'], 'Route::errors adds a new code with the Error $ref');
assert_true(isset($ad['500']) && isset($ad['200']) && isset($ad['409']), 'success + standard 500 + added 409 all present');
assert_same(3, count($ad), 'no duplicated status entries');

// dyn: a computed (dynamic) error status NO LONGER produces an OpenAPI `default` (the fix-pass removed AST error
// inference). Only the success body (200) + the standard policy 500 remain.
$dy = $responses('/rce/dyn');
assert_true(!isset($dy['default']), 'a dynamic error status no longer adds a default response');
assert_same([200, 500], array_keys($dy), 'success + standard 500 only; numeric statuses ordered (ksort SORT_STRING)');

// plain: no `default` key either.
assert_true(!isset($responses('/rce/plain')['default']), 'no default response');

echo "Route contract emitter passed\n";
