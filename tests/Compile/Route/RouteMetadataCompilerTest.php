<?php

declare(strict_types=1);

use OpenApi\Attributes as OA;
use SpsFW\Core\Attributes\AccessRulesAny;
use SpsFW\Core\Attributes\Middleware;
use SpsFW\Core\Attributes\NoAuthAccess;
use SpsFW\Core\Attributes\PhpIni;
use SpsFW\Core\Attributes\RateLimit;
use SpsFW\Core\Attributes\Route;
use SpsFW\Core\Attributes\Validation\JsonBody;
use SpsFW\Core\Attributes\Validation\QueryParams;
use SpsFW\Core\Compile\CompileDiagnostics;
use SpsFW\Core\Compile\Route\RouteCacheEmitter;
use SpsFW\Core\Compile\Route\RouteMetadataCompiler;
use SpsFW\Core\Http\HttpMethod;
use SpsFW\Core\Router\Router;

require_once dirname(__DIR__, 2) . '/bootstrap.php';

/**
 * Step 3 (M3) parity: RouteMetadataCompiler + RouteCacheEmitter must reproduce Router's route-cache IR
 * byte-for-byte. The oracle is Router::registerControllerRoutes (private), invoked via reflection on a
 * constructor-less Router instance — so the DTO rule graphs come from the REAL Router::extractValidationRules,
 * and the comparison is the true production IR.
 *
 * Fixture coverage (plan §4): default-GET, explicit method, path param, QueryParams DTO, JsonBody DTO,
 * class+method middleware merge + RateLimit sugar, AccessRulesAny, NoAuthAccess, PhpIni, nested ref DTO.
 */

// --- DTOs (carry #[OA\Property] so rule graphs are non-trivial) ---
final class RmcNestedDto
{
    #[OA\Property(type: 'string')]
    public string $tag;
}
final class RmcQueryDto
{
    #[OA\Property(property: 'q', type: 'string')]
    public string $query;
    #[OA\Property(property: 'limit', type: 'integer', minimum: 1)]
    public int $limit;
}
final class RmcBodyDto
{
    #[OA\Property(property: 'email', type: 'string', format: 'email', required: [true])]
    public string $email;
    #[OA\Property(ref: RmcNestedDto::class)]
    public RmcNestedDto $meta;
}

/** dummy middleware class string for the class-level #[Middleware] merge test */
final class RmcDummyMiddleware {}

// class-level middleware is merged with method-level (unlike access rules)
#[Middleware([RmcDummyMiddleware::class])]
final class RmcFixtureController
{
    #[Route('/api/rmc/me')] // default http method = GET
    public function me(): array { return []; }

    #[Route('/api/rmc/exam/{examId}', [HttpMethod::GET])]
    public function getExam(int $examId): array { return []; }

    #[Route('/api/rmc/list', [HttpMethod::GET])]
    public function list(#[QueryParams] RmcQueryDto $dto): array { return []; }

    #[Route('/api/rmc/create', [HttpMethod::POST])]
    public function create(#[JsonBody] RmcBodyDto $dto): array { return []; }

    #[Route('/api/rmc/secured', [HttpMethod::POST])]
    #[AccessRulesAny(['admin'])]
    #[RateLimit(requests: ['user' => 10], window: 60)]
    public function secured(#[JsonBody] RmcBodyDto $dto): array { return []; }

    #[Route('/api/rmc/noauth', [HttpMethod::GET])]
    #[NoAuthAccess]
    public function publicEndpoint(): array { return []; }

    #[Route('/api/rmc/ini', [HttpMethod::POST])]
    #[PhpIni(['memory_limit' => '512M'])]
    public function withIni(): array { return []; }
}

// --- oracle: Router::registerControllerRoutes via reflection (no constructor side effects) ---
$router = (new ReflectionClass(Router::class))->newInstanceWithoutConstructor();
$register = new ReflectionMethod(Router::class, 'registerControllerRoutes');

$compiler = new RouteMetadataCompiler(new CompileDiagnostics());
$emitter = new RouteCacheEmitter();

$fixtureReflection = new ReflectionClass(RmcFixtureController::class);
$expected = $register->invoke($router, $fixtureReflection);
$actual = $emitter->emit($compiler->compileController($fixtureReflection));

// ============================================================================
// CORE PARITY: the emitted IR is strictly identical to Router's route-cache IR
// (keys, order, every field, dtos[].rules arrays, dtos[].in enums).
// ============================================================================
assert_same($expected, $actual, 'RouteMetadataCompiler IR === Router::registerControllerRoutes IR (byte-for-byte)');

// ============================================================================
// Targeted shape assertions (regression tripwires on the intricate bits).
// ============================================================================
assert_same('GET', $actual['GET:/api/rmc/me']['httpMethod'], 'route without explicit httpMethods defaults to GET');
assert_same('#^/api/rmc/exam/([^/]+)$#', $actual['GET:/api/rmc/exam/{examId}']['pattern'], 'path param compiled to a regex pattern');
assert_same(['examId' => null], $actual['GET:/api/rmc/exam/{examId}']['params'], 'params captured as [name => null]');
assert_same('', $actual['GET:/api/rmc/me']['pattern'], 'no path params => empty pattern');
assert_same(['NO_AUTH_ACCESS'], $actual['GET:/api/rmc/noauth']['access_rules'], 'NoAuthAccess => NO_AUTH_ACCESS');
assert_same(['any' => ['rules' => ['admin']]], $actual['POST:/api/rmc/secured']['access_rules'], 'AccessRulesAny => any=>rules');

// class + method middleware merge, RateLimit sugar appended last with renamed keys
$securedMiddlewares = $actual['POST:/api/rmc/secured']['middlewares'];
assert_same(RmcDummyMiddleware::class, $securedMiddlewares[0]['class'], 'class-level middleware present (merged)');
assert_same(\SpsFW\Core\Middleware\RateLimitMiddleware::class, $securedMiddlewares[1]['class'], 'RateLimit appended after the class middleware');
// RateLimit params: window→windowSeconds renamed, nulls filtered at collect time. NOTE mergeRateLimitParams
// always materializes whitelistRequests (array_merge of absent keys ⇒ []), so it survives as [] even when
// unset — blockDuration/whitelistIps are unset instead. This surprising shape is replicated byte-for-byte.
assert_same(
    ['requests' => ['user' => 10], 'windowSeconds' => 60, 'whitelistRequests' => []],
    $securedMiddlewares[1]['params'],
    'RateLimit params match Router exactly (incl. always-present whitelistRequests [])',
);

// DTO binding: ParamsIn enum + rule graph from DtoSchemaBuilder (== extractValidationRules)
$createDtos = $actual['POST:/api/rmc/create']['dtos'];
assert_same(1, count($createDtos), 'one DTO binding for the JsonBody param');
assert_same(\SpsFW\Core\Validation\Enum\ParamsIn::Json, $createDtos[0]['in'], 'JsonBody => ParamsIn::Json (enum instance)');
assert_same(RmcBodyDto::class, $createDtos[0]['dto'], 'dto FQCN captured');
assert_true(isset($createDtos[0]['rules']['email']['format']), 'rules carry OA-derived constraints (email format)');
assert_same('string', $createDtos[0]['rules']['meta']['nested_rules']['tag']['type'], 'nested DTO rules recurse');

assert_same(['memory_limit' => '512M'], $actual['POST:/api/rmc/ini']['php_ini_settings'], 'PhpIni settings captured');

// ============================================================================
// emitSource(): the cache file text equals what Router::createRoutesCache would write
// (`<?php\n\nreturn <var_export>;\n` over the same keyed array).
// ============================================================================
$routerSource = "<?php\n\nreturn " . var_export($expected, true) . ";\n";
assert_same($routerSource, $emitter->emitSource($compiler->compileController($fixtureReflection)), 'emitSource() === Router createRoutesCache output');

// ============================================================================
// Diagnostics: duplicate METHOD:path key (Router would silently overwrite; managed mode halts).
// Two controllers registering the same key → diagnostic on both.
// ============================================================================
final class RmcDupAController
{
    #[Route('/api/rmc/dup', [HttpMethod::GET])]
    public function a(): array { return []; }
}
final class RmcDupBController
{
    #[Route('/api/rmc/dup', [HttpMethod::GET])]
    public function b(): array { return []; }
}
$dupDiag = new CompileDiagnostics();
$dupCompiler = new RouteMetadataCompiler($dupDiag);
$dupCompiler->compileClasses([RmcDupAController::class, RmcDupBController::class]);
assert_true($dupDiag->hasErrors(), 'duplicate METHOD:path key is reported as a diagnostic');
assert_same(2, $dupDiag->count(), 'duplicate reported on BOTH colliding operations');
assert_same('route', $dupDiag->errors()[0]['field'], 'duplicate-key error tagged on the route field');
assert_true(str_contains($dupDiag->errors()[0]['cause'], 'GET:/api/rmc/dup'), 'duplicate cause names the shared key');

// a unique set produces no duplicate-key diagnostics
$uniqueDiag = new CompileDiagnostics();
$uniqueCompiler = new RouteMetadataCompiler($uniqueDiag);
$uniqueCompiler->compileClasses([RmcFixtureController::class]);
assert_true(!$uniqueDiag->hasErrors(), 'a controller set with unique route keys produces no diagnostics');

// ============================================================================
// Characterization (plan §20 Шаг 3): inherited #[Route] publication.
// getMethods(IS_PUBLIC) INCLUDES inherited methods, so a #[Route] on a base-class
// method is published by a child that adds none of its own — mirrors Router exactly.
// ============================================================================
class RmcBaseController
{
    #[Route('/api/rmc/inherited', [HttpMethod::GET])]
    public function baseRoute(): array { return []; }
}
final class RmcChildController extends RmcBaseController {}
$childExpected = $register->invoke($router, new ReflectionClass(RmcChildController::class));
$childActual = $emitter->emit($compiler->compileController(new ReflectionClass(RmcChildController::class)));
assert_same($childExpected, $childActual, 'inherited #[Route] parity with Router (IS_PUBLIC includes inherited)');
assert_true(isset($childActual['GET:/api/rmc/inherited']), 'child controller exposes the inherited base route');

// ============================================================================
// Characterization (plan §20 Шаг 3): filesystem candidacy is filename-based.
// Discovery = RecursiveDirectoryIterator + preg_match('/Controller\.php$/'), NOT FQCN.
// A controller in a non-matching filename is invisible; a matching filename is discovered.
// ============================================================================
$candDir = sys_get_temp_dir() . '/rmc_cand_' . getmypid();
@mkdir($candDir) || true;
// *Controller.php filename, matching class => discovered & registered
file_put_contents($candDir . '/DiscoveredController.php', <<<'PHP'
<?php
namespace RmcCand;
use SpsFW\Core\Attributes\Route;
use SpsFW\Core\Http\HttpMethod;
class DiscoveredController {
    #[Route('/api/rmc/cand-discovered', [HttpMethod::GET])]
    public function f(): array { return []; }
}
PHP);
// non-*Controller.php filename, real route => NOT discovered (filename filter excludes it)
file_put_contents($candDir . '/Skipped.php', <<<'PHP'
<?php
namespace RmcCand;
use SpsFW\Core\Attributes\Route;
use SpsFW\Core\Http\HttpMethod;
class Skipped {
    #[Route('/api/rmc/cand-skipped', [HttpMethod::GET])]
    public function g(): array { return []; }
}
PHP);

$candRoutes = $emitter->emit((new RouteMetadataCompiler(new CompileDiagnostics()))->compile([$candDir]));
assert_true(isset($candRoutes['GET:/api/rmc/cand-discovered']), '*Controller.php filename => discovered');
assert_true(!isset($candRoutes['GET:/api/rmc/cand-skipped']), 'non-*Controller.php filename => NOT discovered (candidacy is filename-based, not FQCN)');

unlink($candDir . '/DiscoveredController.php');
unlink($candDir . '/Skipped.php');
rmdir($candDir);

echo "RouteMetadataCompiler IR parity passed\n";
