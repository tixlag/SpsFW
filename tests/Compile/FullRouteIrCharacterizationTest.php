<?php

declare(strict_types=1);

use OpenApi\Attributes as OA;
use SpsFW\Core\Attributes\AccessRulesAny;
use SpsFW\Core\Attributes\Middleware;
use SpsFW\Core\Attributes\PhpIni;
use SpsFW\Core\Attributes\Route;
use SpsFW\Core\Attributes\Validation\JsonBody;
use SpsFW\Core\Http\HttpMethod;
use SpsFW\Core\Router\Router;
use SpsFW\Core\Validation\Enum\ParamsIn;

require_once dirname(__DIR__) . '/bootstrap.php';

/**
 * Characterization (Шаг 0, item 3 — "class-level access и полная route IR-проекция"):
 * pins the FULL route-cache IR that Router::registerControllerRoutes() (protected,
 * Router.php:224) produces for a representative controller.
 *
 * The future RouteMetadataCompiler + RouteCacheEmitter must reproduce this IR byte-for-byte.
 * Notably this pins the middleware/access ASYMMETRY within the IR:
 *  - #[Middleware] on the CLASS is collected and merged with method-level middlewares;
 *  - #[AccessRulesAny] on the CLASS is NOT collected (collectAccessRules is method-only),
 *    so a class-level access rule is a silent no-op in the IR.
 */

// Fixture middleware classes — only their class-string is stored in the IR at compile time;
// they are NOT instantiated by registerControllerRoutes.
class IrMiddlewareA
{
}
class IrMiddlewareB
{
}

final class FullIrFixtureDto
{
    #[OA\Property(property: 'name', type: 'string', required: [true])]
    public string $name;

    #[OA\Property(property: 'count', type: 'integer', minimum: 0)]
    public int $count;
}

#[AccessRulesAny(['admin'])] // class-level access — MUST be ignored by the compiler
#[Middleware([IrMiddlewareA::class])] // class-level middleware — MUST be collected & merged
final class FullIrFixtureController
{
    #[Route('/fix/{id}', [HttpMethod::GET])]
    #[Middleware([['class' => IrMiddlewareB::class, 'params' => ['k' => 'v']]])]
    #[PhpIni(['max_execution_time' => '60'])]
    public function item(int $id, #[JsonBody] FullIrFixtureDto $dto): void
    {
    }
}

$router = (new ReflectionClass(Router::class))->newInstanceWithoutConstructor();
$register = new ReflectionMethod(Router::class, 'registerControllerRoutes');

$ir = $register->invoke($router, new ReflectionClass(FullIrFixtureController::class));

assert_true(array_key_exists('GET:/fix/{id}', $ir), 'IR is keyed by METHOD:path');
$route = $ir['GET:/fix/{id}'];

assert_same(FullIrFixtureController::class, $route['controller'], 'controller = FQCN');
assert_same('GET', $route['httpMethod'], 'httpMethod = string value of HttpMethod enum');
assert_same('item', $route['method'], 'method = PHP method name');
assert_same('/fix/{id}', $route['rawPath'], 'rawPath = original #[Route] path');
assert_same('#^/fix/([^/]+)$#', $route['pattern'], 'pattern = anchored compiled regex');
assert_same(['id' => null], $route['params'], 'params = path-param names (in order) with null placeholders');

// Middlewares: class-level A merged BEFORE method-level B (combineMiddlewares: classOthers ++ methodOthers).
assert_same(
    [
        ['class' => IrMiddlewareA::class, 'params' => []],
        ['class' => IrMiddlewareB::class, 'params' => ['k' => 'v']],
    ],
    $route['middlewares'],
    'middlewares = class-level ++ method-level (merged); RateLimit is special-cased elsewhere'
);

// Access: class-level #[AccessRulesAny(['admin'])] is IGNORED; method has no access attr => [].
assert_same([], $route['access_rules'], 'access_rules = [] : class-level access is NOT collected (method-only quirk)');

// DTOs: only the #[JsonBody]-marked typed param becomes a dto entry; the `int $id` param is skipped.
assert_same(1, count($route['dtos']), 'exactly one dto entry (the #[JsonBody]-marked param; int $id is skipped)');
assert_same(ParamsIn::Json, $route['dtos'][0]['in'], 'dto.in derived from #[JsonBody] => ParamsIn::Json');
assert_same(FullIrFixtureDto::class, $route['dtos'][0]['dto'], 'dto.dto = the typed param class');
assert_true(array_key_exists('name', $route['dtos'][0]['rules']), 'dto.rules = extracted rule graph');
assert_same([true], $route['dtos'][0]['rules']['name']['required'], 'dto.rules carries required:[true] verbatim');

// PhpIni settings passed through verbatim.
assert_same(['max_execution_time' => '60'], $route['php_ini_settings'], 'php_ini_settings = #[PhpIni] settings verbatim');

echo "Full route IR characterization passed\n";
