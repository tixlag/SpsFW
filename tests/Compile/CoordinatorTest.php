<?php

declare(strict_types=1);

use SpsFW\Core\Compile\ApplicationContext;
use SpsFW\Core\Compile\CompileDiagnostics;
use SpsFW\Core\Compile\CompileResult;
use SpsFW\Core\Compile\Coordinator;

require_once dirname(__DIR__) . '/bootstrap.php';

/**
 * Шаг 1 (M1): the Coordinator is an additive skeleton — compile() returns an empty success and touches
 * no runtime code, no production cache, no Router/Validator/DI flow. This pins that contract so later
 * steps can wire builders without accidentally dragging in side effects.
 */
$context = new ApplicationContext(
    projectRoot: '/tmp/app',
    cachePath: '/tmp/app/.cache',
    discoveryPaths: ['/tmp/app/src'],
    configInputs: ['di_config' => 'di.php'],
    mode: ApplicationContext::MODE_MANAGED,
);

$coordinator = new Coordinator($context);

assert_same($context, $coordinator->context(), 'context() returns the injected ApplicationContext');
assert_true($coordinator->diagnostics() instanceof CompileDiagnostics, 'diagnostics() exposes the collector');

$result = $coordinator->compile();
assert_true($result instanceof CompileResult, 'compile() returns a CompileResult');
assert_true($result->success, 'skeleton compile() reports success');
assert_same([], $result->artifacts, 'skeleton compile() publishes no artifacts');
assert_same(null, $result->manifestPath, 'skeleton compile() publishes no manifest');

// default mode is the BC-preserving legacy mode
$legacy = new ApplicationContext('/tmp/app', '/tmp/app/.cache', ['/tmp/app/src']);
assert_same(ApplicationContext::MODE_LEGACY, $legacy->mode, 'default application mode is legacy');

echo "Coordinator skeleton passed\n";
