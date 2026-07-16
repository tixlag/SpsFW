<?php

declare(strict_types=1);

use SpsFW\Core\Compile\ApplicationContext;
use SpsFW\Core\Compile\CompileDiagnostics;
use SpsFW\Core\Compile\CompileResult;
use SpsFW\Core\Compile\Coordinator;

require_once dirname(__DIR__) . '/bootstrap.php';

/**
 * Шаг 1 → Шаг 5: the Coordinator contract. Step 5 wires the real compilation flow (see CoordinatorFlowTest for
 * the staging/publication behavior). This file pins the constructor contract — context()/diagnostics() return the
 * injected values — and that compile() returns a CompileResult, using a DRY RUN so it stays side-effect-free
 * (no production cache is written here).
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

// Dry run: a CompileResult is returned, nothing is published (the /tmp/app/src dir does not exist, so discovery
// finds nothing; the engine completes and reports a fingerprint without writing any artifact).
$result = $coordinator->compile(dryRun: true);
assert_true($result instanceof CompileResult, 'compile() returns a CompileResult');
assert_true($result->success, 'compile() reports success (it completed)');
assert_true(!$result->published, 'dry-run compile() publishes nothing');
assert_true($result->dryRun, 'dry-run compile() is flagged as a dry run');
assert_same([], $result->artifacts, 'dry-run compile() publishes no artifacts');
assert_same(null, $result->manifestPath, 'dry-run compile() publishes no manifest');

// default mode is the BC-preserving legacy mode
$legacy = new ApplicationContext('/tmp/app', '/tmp/app/.cache', ['/tmp/app/src']);
assert_same(ApplicationContext::MODE_LEGACY, $legacy->mode, 'default application mode is legacy');

echo "Coordinator contract passed\n";
