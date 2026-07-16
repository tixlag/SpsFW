<?php

declare(strict_types=1);

use SpsFW\Core\Compile\CompileDiagnostics;
use SpsFW\Core\Compile\Route\RouteMetadataCompiler;

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once __DIR__ . '/../fixtures/clean/FlowCleanController.php';
require_once __DIR__ . '/../fixtures/clean/FlowCreateDto.php';
require_once __DIR__ . '/../fixtures/override/core/AuthController.php';
require_once __DIR__ . '/../fixtures/override/app/AuthController.php';

/**
 * Step 5 fix-pass: the UNIFIED effective endpoint set (compileEndpointSet) — required tests:
 *   1. a present-NULL operationIdMap entry keeps the operation id-less end-to-end (the 306-deferred contract);
 *   2. a SHADOWED route (declared override) creates NO operationId collision (the loser is excluded from the
 *      uniqueness check and from the projection), whereas the same pair WITHOUT an override is a genuine dup ERROR;
 *   3. the override WINNER is stable under discovery-path REORDERING (map-chosen, not discovery-order-chosen).
 */

$coreClass = \SpsFWTest\CompileFixtures\Override\Core\AuthController::class;
$appClass = \SpsFWTest\CompileFixtures\Override\App\AuthController::class;
$cleanClass = \SpsFWTest\CompileFixtures\Clean\FlowCleanController::class;
$coreDir = __DIR__ . '/../fixtures/override/core';
$appDir = __DIR__ . '/../fixtures/override/app';
$cleanDir = __DIR__ . '/../fixtures/clean';
$key = 'POST:/override/login';
$overrideMap = [$key => $appClass . '::login'];

// ============================================================================
// 1. present-NULL operationId stays NULL end-to-end: a tri-state map entry keeps the op id-less through the whole
//    endpoint-set flow (NOT a fall-through to the convention), so the deferred client is not perturbed.
// ============================================================================
$diag1 = new CompileDiagnostics();
$compiler1 = new RouteMetadataCompiler($diag1, operationIdMap: [$cleanClass . '::health' => null]);
$set1 = $compiler1->compileEndpointSet([$cleanDir]);
$health = null;
foreach ($set1->operations as $op) {
    if ($op->method === 'health') {
        $health = $op;
    }
}
assert_true($health !== null, 'present-null: the health operation is compiled');
assert_same(null, $health->operationId, 'present-null: operationId stays null end-to-end (no convention fall-through)');
assert_true(!$diag1->hasErrors(), 'present-null: a null operationId causes no uniqueness error (nulls are not tracked)');

// ============================================================================
// 2. SHADOWED route (override) creates NO operationId collision. The Core template and the App override both
//    register POST:/override/login and would both resolve to the convention id "AuthLogin" — a collision IF both
//    were effective. The override shadows Core, so only App survives: zero operationId errors, one effective op.
// ============================================================================
$diag2 = new CompileDiagnostics();
$compiler2 = new RouteMetadataCompiler($diag2);
$set2 = $compiler2->compileEndpointSet([$coreDir, $appDir], $overrideMap);

$operationIdErrors = array_filter(
    $diag2->errors(),
    static fn(array $e): bool => $e['field'] === 'operationId',
);
assert_same(0, count($operationIdErrors), 'shadowed: no operationId collision (the shadowed Core op is excluded)');

$effectiveFor = array_values(array_filter(
    $set2->operations,
    static fn($op) => strtoupper($op->httpMethod) . ':' . $op->path === $key,
));
assert_same(1, count($effectiveFor), 'shadowed: exactly ONE effective operation for the overridden key');
assert_same($appClass, $effectiveFor[0]->controller, 'shadowed: the effective operation is the App (winner), not Core');
assert_same(1, count($set2->overrides), 'shadowed: one override applied');
assert_same($appClass . '::login', $set2->overrides[0]['winner'], 'shadowed: winner is App::login');
assert_true(in_array($coreClass . '::login', $set2->overrides[0]['shadowed'], true), 'shadowed: Core::login is in the shadowed list');

// ============================================================================
// 2b. The SAME pair WITHOUT an override is a GENUINE duplicate — a structural ERROR (not a tolerated shadow), and
//     it collapses last-wins in the IR. The override is what distinguishes "intentional shadow" from "real bug".
// ============================================================================
$diag2b = new CompileDiagnostics();
$compiler2b = new RouteMetadataCompiler($diag2b);
$set2b = $compiler2b->compileEndpointSet([$coreDir, $appDir], []);
$routeErrors = array_filter(
    $diag2b->errors(),
    static fn(array $e): bool => $e['field'] === 'route' && str_contains($e['cause'], 'duplicate route key ' . $key),
);
assert_true(count($routeErrors) >= 1, 'no-override: the duplicate key is a genuine structural ERROR');
assert_same(0, count($set2b->overrides), 'no-override: no overrides applied (empty overrides list — the genuine-dup branch shadows nothing)');

// ============================================================================
// 3. Override WINNER is STABLE under discovery-path REORDERING. The winner is map-chosen (by controller::method),
//    so presenting Core-first vs App-first yields the SAME effective operation (App) — never discovery-order-dependent.
// ============================================================================
$winnerOf = static function (array $discoveryPaths) use ($overrideMap, $key): array {
    $diag = new CompileDiagnostics();
    $compiler = new RouteMetadataCompiler($diag);
    $set = $compiler->compileEndpointSet($discoveryPaths, $overrideMap);
    $effective = array_values(array_filter(
        $set->operations,
        static fn($op) => strtoupper($op->httpMethod) . ':' . $op->path === $key,
    ));
    return [$diag->errorCount(), $effective[0]->controller ?? null, $set->overrides[0]['winner'] ?? null];
};
[$errCoresFirst, $winnerCoresFirst, $declaredCoresFirst] = $winnerOf([$coreDir, $appDir]);
[$errAppsFirst, $winnerAppsFirst, $declaredAppsFirst] = $winnerOf([$appDir, $coreDir]);
assert_same(0, $errCoresFirst, 'reorder: no errors with Core discovered first');
assert_same(0, $errAppsFirst, 'reorder: no errors with App discovered first');
assert_same($appClass, $winnerCoresFirst, 'reorder: winner is App even when Core is discovered first');
assert_same($appClass, $winnerAppsFirst, 'reorder: winner is App even when App is discovered first');
assert_same($winnerCoresFirst, $winnerAppsFirst, 'reorder: the effective operation is identical regardless of discovery order');
assert_same($declaredCoresFirst, $declaredAppsFirst, 'reorder: the applied override is identical regardless of discovery order');

// ============================================================================
// 3b. An override naming a WINNER that matches NO discovered route is an ERROR (invalid override) — the winner must
//     point at a real controller::method that registers the key.
// ============================================================================
$diag3b = new CompileDiagnostics();
$compiler3b = new RouteMetadataCompiler($diag3b);
$compiler3b->compileEndpointSet([$coreDir, $appDir], [$key => 'SpsFWTest\\CompileFixtures\\Override\\App\\AuthController::nonexistent']);
$overrideErrors = array_filter(
    $diag3b->errors(),
    static fn(array $e): bool => $e['field'] === 'route_override',
);
assert_true(count($overrideErrors) >= 1, 'invalid winner: an override naming a non-existent controller::method is an ERROR');

echo "EndpointSet passed\n";
