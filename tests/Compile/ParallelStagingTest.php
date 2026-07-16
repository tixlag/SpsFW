<?php

declare(strict_types=1);

use SpsFW\Core\Compile\ApplicationContext;
use SpsFW\Core\Compile\Coordinator;

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/fixtures/clean/FlowCleanController.php';
require_once __DIR__ . '/fixtures/clean/FlowCreateDto.php';

/**
 * Step 5 fix-pass, required test #5: two PARALLEL runs do NOT share a staging directory.
 *
 * The old shared `.staging-compile` name let a second run's cleanDir wipe a first run's in-flight staging. The fix
 * gives every run a UNIQUE staging dir, and the whole-flow CompileLock serializes the mutating tail so the two never
 * interleave. Two forked children compile the SAME fixtures into the SAME cache concurrently; each reports the
 * staging dir it used, and the two MUST differ. (pcntl-only; skipped where pcntl is unavailable.)
 */

if (!function_exists('pcntl_fork')) {
    echo "ParallelStaging skipped (pcntl unavailable)\n";
    return;
}

$cleanDir = __DIR__ . '/fixtures/clean';
$tmpRoot = sys_get_temp_dir() . '/spsfw_parallel_' . bin2hex(random_bytes(8));
$cache = $tmpRoot . '/cache';
mkdir($cache, 0777, true);
$logA = $tmpRoot . '/childA.log';
$logB = $tmpRoot . '/childB.log';

// Each child compiles the SAME fixtures into the SAME cache concurrently. lockTimeoutSec > 0 so the second child
// WAITS for the first (rather than failing fast) — exercising real serialization.
$runChild = static function (string $logFile) use ($cache, $cleanDir): void {
    $ctx = new ApplicationContext(
        projectRoot: $cache,
        cachePath: $cache,
        discoveryPaths: [$cleanDir],
        mode: ApplicationContext::MODE_MANAGED,
        diagnosticPolicy: ApplicationContext::POLICY_PARITY,
        lockTimeoutSec: 15.0,
    );
    try {
        $result = (new Coordinator($ctx))->compile();
        file_put_contents($logFile, ($result->stagingDir ?? 'NONE') . '|' . ($result->published ? 'PUBLISHED' : 'NOTPUBLISHED'));
        exit($result->published ? 0 : 1);
    } catch (\Throwable $e) {
        file_put_contents($logFile, 'ERROR: ' . $e->getMessage());
        exit(2);
    }
};

$pidA = pcntl_fork();
if ($pidA === 0) {
    $runChild($logA);
}
$pidB = pcntl_fork();
if ($pidB === 0) {
    $runChild($logB);
}

// Parent: wait for both children.
$statusA = $statusB = null;
pcntl_waitpid($pidA, $statusA);
pcntl_waitpid($pidB, $statusB);

$exitA = pcntl_wifexited($statusA) ? pcntl_wexitstatus($statusA) : -1;
$exitB = pcntl_wifexited($statusB) ? pcntl_wexitstatus($statusB) : -1;
$reportA = is_file($logA) ? file_get_contents($logA) : '(no log)';
$reportB = is_file($logB) ? file_get_contents($logB) : '(no log)';

[$stagingA] = explode('|', $reportA . '|');
[$stagingB] = explode('|', $reportB . '|');

assert_same(0, $exitA, 'parallel run A succeeded (exit 0); report: ' . $reportA);
assert_same(0, $exitB, 'parallel run B succeeded (exit 0); report: ' . $reportB);
assert_true(str_ends_with($reportA, '|PUBLISHED'), 'parallel run A published');
assert_true(str_ends_with($reportB, '|PUBLISHED'), 'parallel run B published');
assert_true($stagingA !== '' && $stagingA !== 'NONE', 'parallel run A used a staging dir: ' . $stagingA);
assert_true($stagingB !== '' && $stagingB !== 'NONE', 'parallel run B used a staging dir: ' . $stagingB);
assert_true($stagingA !== $stagingB, 'two parallel runs use DISTINCT staging directories (no shared staging)');

// After both finish, the cache holds a valid set and NO leftover staging dir.
assert_true(is_file($cache . '/compiled_routes.php'), 'parallel: route cache published');
assert_true(is_file($cache . '/.compile_manifest.php'), 'parallel: manifest published');
$leftover = array_filter(scandir($cache), static fn(string $d): bool => str_starts_with($d, '.staging-'));
assert_same([], array_values($leftover), 'parallel: no leftover staging directory after both runs');

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
$rrm($tmpRoot);
echo "ParallelStaging passed\n";
