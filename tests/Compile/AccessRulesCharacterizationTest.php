<?php

declare(strict_types=1);

use SpsFW\Core\Attributes\AccessRulesAll;
use SpsFW\Core\Attributes\AccessRulesAny;
use SpsFW\Core\Attributes\NoAuthAccess;
use SpsFW\Core\Router\Router;

require_once dirname(__DIR__) . '/bootstrap.php';

/**
 * Characterization (Шаг 0, §3 access-quirks): pins Router::collectAccessRules()
 * (protected, Router.php:575) — method-level access-rule extraction.
 *
 * Quirks the future RouteRuntimeMetadata + SecurityMetadata MUST preserve exactly:
 *  - #[NoAuthAccess]                            -> ['NO_AUTH_ACCESS']
 *  - no #[AccessRulesAny] (even with #[AccessRulesAll]) -> []   (All-only is IGNORED)
 *  - #[AccessRulesAny] present                  -> ['any' => ['rules' => [...]]]
 *       + optional ['all' => ['rules' => [...]]] when #[AccessRulesAll] also present
 *  - none of the attributes                     -> []
 *
 * Note: only METHOD-level is collected here (class-level access is NOT collected today,
 * unlike middlewares which merge class+method). The future compiler must NOT silently
 * "fix" this — it is the current contract.
 */
final class AccessFixtureController
{
    #[NoAuthAccess]
    public function noAuth(): void
    {
    }

    #[AccessRulesAny([1, 2])]
    public function anyOnly(): void
    {
    }

    #[AccessRulesAll([3])]
    public function allOnly(): void
    {
    }

    #[AccessRulesAny([1])]
    #[AccessRulesAll([2, 3])]
    public function anyAndAll(): void
    {
    }

    public function none(): void
    {
    }
}

$router = (new ReflectionClass(Router::class))->newInstanceWithoutConstructor();
$collect = new ReflectionMethod(Router::class, 'collectAccessRules');

$rules = fn(string $method) => $collect->invoke($router, new ReflectionMethod(AccessFixtureController::class, $method));

assert_same(['NO_AUTH_ACCESS'], $rules('noAuth'), 'NoAuthAccess -> [NO_AUTH_ACCESS]');
assert_same(['any' => ['rules' => [1, 2]]], $rules('anyOnly'), 'AccessRulesAny -> [any=>[rules]]');
assert_same([], $rules('allOnly'), 'AccessRulesAll WITHOUT AccessRulesAny -> [] (All-only ignored quirk)');
assert_same(
    ['any' => ['rules' => [1]], 'all' => ['rules' => [2, 3]]],
    $rules('anyAndAll'),
    'AccessRulesAny + AccessRulesAll -> [any=>..., all=>...] (any first)'
);
assert_same([], $rules('none'), 'no access attributes -> []');

// --- class-level access attributes are SYNTACTICALLY allowed (TARGET_CLASS) but
//     SILENTLY IGNORED: collectAccessRules is only ever called with a ReflectionMethod
//     (Router::registerControllerRoutes line 241). Contrast with #[Middleware], which
//     IS collected at class level and merged. A developer placing #[AccessRulesAny] on a
//     controller class gets NO enforcement — the future compiler must NOT silently "fix" this
//     without an explicit decision (it is the current contract). ---
#[AccessRulesAny(['admin'])]
#[NoAuthAccess]
final class ClassLevelAccessFixtureController
{
    public function noMethodLevelAccess(): void
    {
    }
}

$plainMethod = new ReflectionMethod(ClassLevelAccessFixtureController::class, 'noMethodLevelAccess');
assert_same(
    [],
    $collect->invoke($router, $plainMethod),
    'class-level #[AccessRulesAny]/#[NoAuthAccess] are IGNORED by collectAccessRules (method-only; class-level access is a no-op today)'
);

echo "Access rules characterization passed\n";
