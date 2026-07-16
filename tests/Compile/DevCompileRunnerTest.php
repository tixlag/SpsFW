<?php

declare(strict_types=1);

use SpsFW\Core\Compile\ApplicationContext;
use SpsFW\Core\Compile\DevCompileRunner;

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/fixtures/clean/FlowCreateDto.php';
require_once __DIR__ . '/fixtures/clean/FlowCleanController.php';

/**
 * Шаг 5: DevCompileRunner is a DEV-ONLY thin wrapper — it owns NO build logic, it just resolves an ApplicationContext
 * (here overridden to the clean fixtures + a temp cache) and delegates to Coordinator. This pins that contract: it
 * forwards mode/policy/dryRun, returns the Coordinator's CompileResult + diagnostics, and renders a report. It does
 * NOT load env/config/DI bindings (the production owner's job, plan §11.2).
 */

$cache = sys_get_temp_dir() . '/spsfw_dev_' . bin2hex(random_bytes(8));
mkdir($cache, 0777, true);

$runner = (new DevCompileRunner())->execute([
    'dryRun' => true,
    'cachePath' => $cache,
    'discoveryPaths' => [__DIR__ . '/fixtures/clean'],
    // The legacy OpenAPI scan set is now DECOUPLED from discovery (Step 6b). Point it at the clean fixtures too, so the
    // probe stays isolated from the host PathManager project-root resolution (which, in this dev checkout, picks a
    // sibling project whose src is not swagger-php-clean) — mirroring how a real preload passes its own scan paths.
    'legacyOpenApiScanPaths' => [__DIR__ . '/fixtures/clean'],
    'mode' => ApplicationContext::MODE_MANAGED,
    'diagnosticPolicy' => ApplicationContext::POLICY_PARITY,
]);

$result = $runner->result();
assert_true($result->success, 'dev runner: completed');
assert_true($result->dryRun, 'dev runner: forwards dry-run');
assert_true(!$result->published, 'dev runner: dry-run publishes nothing');
assert_same(0, $result->errorCount, 'dev runner: clean fixtures → no errors');

// The diagnostics come straight from the Coordinator (no separate collector on the wrapper).
assert_same(0, $runner->diagnostics()->errorCount(), 'dev runner: diagnostics are the Coordinator\'s');
assert_true(str_contains($runner->render(), 'NOT PUBLISHED'), 'dev runner: render reports non-publication');
assert_true(str_contains($runner->render(), 'mode=managed'), 'dev runner: render records the resolved mode');

// A real (non-dry-run) build on the clean fixtures PUBLISHES — the wrapper writes through to the temp cache.
$pub = (new DevCompileRunner())->execute([
    'cachePath' => $cache,
    'discoveryPaths' => [__DIR__ . '/fixtures/clean'],
    'legacyOpenApiScanPaths' => [__DIR__ . '/fixtures/clean'],
]);
assert_true($pub->result()->published, 'dev runner: non-dry-run clean build publishes');
assert_true(is_file($cache . '/compiled_routes.php'), 'dev runner: route cache written via the wrapper');

$rrm = static function (string $dir) use (&$rrm): void {
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $e) {
        if ($e === '.' || $e === '..') {
            continue;
        }
        $p = $dir . '/' . $e;
        is_dir($p) ? $rrm($p) : @unlink($p);
    }
    @rmdir($dir);
};
$rrm($cache);
echo "DevCompileRunner passed\n";
