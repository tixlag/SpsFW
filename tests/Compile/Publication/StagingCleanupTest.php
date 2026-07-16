<?php

declare(strict_types=1);

use SpsFW\Core\Compile\ApplicationContext;
use SpsFW\Core\Compile\CompileException;
use SpsFW\Core\Compile\Coordinator;

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once __DIR__ . '/../fixtures/clean/FlowCreateDto.php';
require_once __DIR__ . '/../fixtures/clean/FlowCleanController.php';

/**
 * Step 5 fix-pass, required test: the Coordinator cleans its UNIQUE `.staging-*` dir in a `finally` on BOTH a
 * successful publish AND a publication failure — no staging leftovers after an injected fault. The fault is injected
 * via the Coordinator's publish-fault seam (publishFaultHook), thrown at the first publish step so publication fails
 * and rolls back; the staging dir must still be removed. Recovery backups (a separate `.backup-*` dir) are only left
 * behind by an INCOMPLETE rollback (see StagingPublisherTest); a plain fault here fully restores, so none remain.
 */

$tmpRoot = sys_get_temp_dir() . '/spsfw_stageclean_' . bin2hex(random_bytes(8));
$cache = $tmpRoot . '/cache';
$cleanDir = __DIR__ . '/../fixtures/clean';
mkdir($cache, 0777, true);
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

$stagingLeftovers = static function (string $cache): array {
    return array_values(array_filter(
        scandir($cache),
        static fn(string $e): bool => str_starts_with($e, '.staging-'),
    ));
};

// ============================================================================
// 1. Inject a publication FAILURE at the first publish step: the Coordinator throws, rolls back, and its `finally`
//    removes the unique staging dir — no `.staging-*` leftover despite the failure.
// ============================================================================
$ctx = new ApplicationContext(
    projectRoot: $cache,
    cachePath: $cache,
    discoveryPaths: [$cleanDir],
    mode: ApplicationContext::MODE_MANAGED,
    diagnosticPolicy: ApplicationContext::POLICY_PARITY,
    lockTimeoutSec: 5.0,
);
$caught = null;
try {
    (new Coordinator($ctx))->compile(publishFaultHook: static function (int $step): void {
        if ($step === 1) {
            throw new RuntimeException('injected publication failure at step 1');
        }
    });
} catch (CompileException $e) {
    $caught = $e;
}
assert_true($caught !== null, 'an injected publication failure propagates as CompileException');
assert_true(str_contains($caught->getMessage(), 'rolled back'), 'the failure message reports the rollback');
assert_same([], $stagingLeftovers($cache), 'no staging dir is left behind after a failed publication (cleaned in finally)');
// A plain fault fully restores (the targets' parent dirs are intact), so no recovery backup dir is left either.
assert_same([], glob($cache . '/.backup-*'), 'a fully-restored failed publish leaves no recovery backup dir');
// And nothing was published.
assert_true(!is_file($cache . '/compiled_routes.php'), 'failed publish wrote no route cache');
assert_true(!is_file($cache . '/.compile_manifest.php'), 'failed publish wrote no manifest');

// ============================================================================
// 2. Positive control: the SAME project compiles cleanly (no fault) and also leaves no staging leftover.
// ============================================================================
$res = (new Coordinator($ctx))->compile();
assert_true($res->published, 'positive control: the clean compile publishes');
assert_same([], $stagingLeftovers($cache), 'a successful publish also leaves no staging dir');
assert_true(is_file($cache . '/compiled_routes.php'), 'positive control: route cache published');

$rrm($tmpRoot);
echo "StagingCleanup passed\n";
