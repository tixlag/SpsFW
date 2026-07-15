<?php

declare(strict_types=1);

use SpsFW\Core\Router\Router;

require_once dirname(__DIR__) . '/bootstrap.php';

/**
 * Characterization (Шаг 0, §2.1): pins Router::compileRoutePattern() (protected,
 * Router.php:508) — the `{name}` -> `([^/]+)` path compilation + param ordering.
 *
 * The future RouteMetadataCompiler must reproduce this exact regex/params contract
 * so the runtime dispatcher keeps matching identically.
 */
$router = (new ReflectionClass(Router::class))->newInstanceWithoutConstructor();
$compile = new ReflectionMethod(Router::class, 'compileRoutePattern');

// static path -> no params -> empty pattern sentinel (NOT an anchored empty regex)
assert_same(['pattern' => '', 'params' => []], $compile->invoke($router, '/users'), 'static path returns empty pattern + no params');

// single param
$result = $compile->invoke($router, '/users/{id}');
assert_same('#^/users/([^/]+)$#', $result['pattern'], 'single {param} compiles to anchored capture group');
assert_same(['id' => null], $result['params'], 'single param name captured with null placeholder');

// multiple params preserve order of appearance
$result = $compile->invoke($router, '/users/{userId}/posts/{slug}');
assert_same('#^/users/([^/]+)/posts/([^/]+)$#', $result['pattern'], 'multiple params compile in order');
assert_same(['userId' => null, 'slug' => null], $result['params'], 'multiple param names captured in order');

// placeholders accept dashes/underscores/digits in the name
$result = $compile->invoke($router, '/a/{b-c}_{d1}');
assert_same(['b-c' => null, 'd1' => null], $result['params'], 'param names may contain dash/underscore/digit');

echo "Route pattern characterization passed\n";
