<?php

/**
 * Dev-only parity probe (NOT a committed test).
 *
 * SpsFW clean-checkout tests MUST NOT depend on the consumer repo (N). This script is run by hand against
 * the live consumer app to prove, on REAL production DTOs, that:
 *
 *     DtoSchemaBuilder::ruleGraph(build($dto))->rules === Router::extractValidationRules($dto)   (strict ===)
 *
 * for every unique DTO referenced in N's compiled route cache. The committed artifact is the numeric
 * summary recorded in AUDIT §4.x, not this script's runtime.
 *
 * Bootstrapping: load N's full dependency set (next/vendor/autoload.php) so every SpsNext DTO can load,
 * then PREPEND the local SpsFW working-tree src over the vendored copy so the parity check exercises the
 * code under development, and register next/src for the SpsNext\ PSR-4 root.
 */

declare(strict_types=1);

use SpsFW\Core\Compile\Introspection\DtoSchemaBuilder;
use SpsFW\Core\Router\Router;

$spsfwRoot = dirname(__DIR__, 2);
$nextRoot = dirname($spsfwRoot) . '/lk.sps38.pro/next';

if (!is_dir($nextRoot)) {
    fwrite(STDERR, "consumer repo not found at $nextRoot — nothing to probe\n");
    exit(2);
}

$nextAutoload = $nextRoot . '/vendor/autoload.php';
if (!is_file($nextAutoload)) {
    fwrite(STDERR, "next vendor autoload not found at $nextAutoload\n");
    exit(2);
}

/** @var \Composer\Autoload\ClassLoader $loader */
$loader = require $nextAutoload;
// Local SpsFW working tree wins over the vendored copy (prepend = true).
$loader->addPsr4('SpsFW\\', [$spsfwRoot . '/src'], true);
// SpsNext\ source root (PSR-4 per next/composer.json).
$loader->addPsr4('SpsNext\\', $nextRoot . '/src');

$routesPath = $nextRoot . '/.cache/compiled_routes.php';
if (!is_file($routesPath)) {
    fwrite(STDERR, "compiled_routes.php not found at $routesPath — rebuild N cache first\n");
    exit(2);
}
$routes = require $routesPath;

// Collect unique DTO FQCNs and count total bindings (with duplicates).
$unique = [];
$bindingCount = 0;
foreach ($routes as $route) {
    foreach (($route['dtos'] ?? []) as $binding) {
        $dto = $binding['dto'] ?? null;
        if (is_string($dto) && $dto !== '') {
            $unique[$dto] = true;
            $bindingCount++;
        }
    }
}
$unique = array_keys($unique);
sort($unique);

$router = (new ReflectionClass(Router::class))->newInstanceWithoutConstructor();
$extract = new ReflectionMethod(Router::class, 'extractValidationRules');
$builder = new DtoSchemaBuilder();

$loadable = 0;
$unloadable = [];
$divergences = [];

foreach ($unique as $dto) {
    try {
        if (!class_exists($dto)) {
            $unloadable[] = $dto . ' (not loadable)';
            continue;
        }
    } catch (\Throwable $e) {
        $unloadable[] = $dto . ' (load error: ' . $e->getMessage() . ')';
        continue;
    }

    $loadable++;
    try {
        $expected = $extract->invoke($router, $dto);
        $actual = $builder->ruleGraph($builder->build($dto))->rules;
    } catch (\Throwable $e) {
        $divergences[] = $dto . ' :: THROW ' . $e::class . ': ' . $e->getMessage();
        continue;
    }

    if ($expected !== $actual) {
        $divergences[] = $dto . "\n    expected=" . var_export($expected, true) . "\n    actual=  " . var_export($actual, true);
    }
}

echo "=== DtoSchemaBuilder rule-graph parity vs Router::extractValidationRules (consumer N DTOs) ===\n";
echo "DTO bindings in compiled_routes (with duplicates): {$bindingCount}\n";
echo "Unique DTO FQCNs: " . count($unique) . "\n";
echo "Loadable: {$loadable}\n";
echo "Unloadable: " . count($unloadable) . "\n";
echo "Divergences: " . count($divergences) . "\n";

foreach ($unloadable as $u) {
    echo "  UNLOADABLE: {$u}\n";
}
foreach ($divergences as $d) {
    echo "  DIVERGENCE: {$d}\n";
}

exit($divergences === [] ? 0 : 1);
