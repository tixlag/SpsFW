<?php

declare(strict_types=1);

use SpsFW\Core\Compile\Metadata\RouteRuntimeMetadata;
use SpsFW\Core\Compile\Metadata\ValidationRuleGraph;
use SpsFW\Core\Validation\Enum\ParamsIn;

require_once dirname(__DIR__, 2) . '/bootstrap.php';

/**
 * Шаг 1 (M1): pins RouteRuntimeMetadata — the byte-for-byte reproduction of the route cache IR that
 * RouteCacheEmitter (Step 3) will serialize. Asserts the route-key shape, the NO_AUTH_ACCESS marker, and
 * that DTO bindings carry the ParamsIn enum + a ValidationRuleGraph object.
 */
$route = new RouteRuntimeMetadata(
    controller: 'App\\UserController',
    httpMethod: 'GET',
    method: 'view',
    rawPath: '/api/users/{id}',
    pattern: '#^/api/users/([^/]+)$#',
    params: ['id'],
    middlewares: [['class' => 'App\\AuthMiddleware', 'params' => []]],
    accessRules: ['NO_AUTH_ACCESS'],
    dtos: [
        ['in' => ParamsIn::Json, 'dto' => 'App\\UserDto', 'rules' => ValidationRuleGraph::empty()],
    ],
    phpIniSettings: ['max_execution_time' => '120'],
);

assert_same('GET:/api/users/{id}', $route->routeKey(), 'routeKey is METHOD:rawPath (the Router dedup key)');
assert_true($route->isAnonymous(), 'NO_AUTH_ACCESS marks the route anonymous');
assert_same(['id'], $route->params, 'path params preserve order');
assert_same(ParamsIn::Json, $route->dtos[0]['in'], 'dto binding keeps the ParamsIn location');
assert_true($route->dtos[0]['rules'] instanceof ValidationRuleGraph, 'dto binding carries a ValidationRuleGraph');

// empty access rules => not anonymous; routeKey stable for paramless routes
$restricted = new RouteRuntimeMetadata('C', 'POST', 'create', '/x', '#^/x$#', accessRules: []);
assert_true(!$restricted->isAnonymous(), 'empty access rules is not anonymous');
assert_same('POST:/x', $restricted->routeKey(), 'routeKey for a paramless route');

// rule-graph emptiness helper
assert_true(ValidationRuleGraph::empty()->isEmpty(), 'empty rule graph reports empty');
$withRules = new ValidationRuleGraph(['id' => ['required' => [true], 'type' => 'integer']]);
assert_true(!$withRules->isEmpty(), 'non-empty rule graph reports non-empty');

echo "RouteRuntimeMetadata passed\n";
