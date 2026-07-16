<?php

declare(strict_types=1);

use SpsFW\Core\Compile\ApplicationContext;
use SpsFW\Core\Compile\Coordinator;
use SpsFW\Core\Compile\CompileException;
use SpsFW\Core\Compile\Publication\CompileLock;

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once __DIR__ . '/../fixtures/clean/FlowCleanController.php';
require_once __DIR__ . '/../fixtures/clean/FlowCreateDto.php';

/**
 * Step 5 fix-pass: CompileLock — the whole-flow lock with a REAL LOCK_NB + deadline timeout.
 *
 * Required test #4: lock CONTENTION blocks publication BEFORE any staging dir is created (staging is created only
 * after the lock is acquired). Plus the timeout contract the fix restores: a POSITIVE deadline actually busy-waits
 * up to the deadline (the previous code only honored timeout=0 — a single non-blocking attempt — because the blocking
 * branch ignored the deadline).
 */

$tmpRoot = sys_get_temp_dir() . '/spsfw_lock_' . bin2hex(random_bytes(8));
mkdir($tmpRoot, 0777, true);
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

// ============================================================================
// 1. The REAL timeout: holding the lock externally, acquire(positive) busy-waits up to the deadline then throws.
//    It must actually WAIT (elapsed >= ~80% of the deadline) — proving the deadline is honored, not zero-ized.
// ============================================================================
$lockPath = $tmpRoot . '/.compile.lock';
$holder = fopen($lockPath, 'c+');
assert_true(flock($holder, LOCK_EX), 'test pre-acquires the lock externally');

$lock = new CompileLock($lockPath);
$start = microtime(true);
$caught = null;
try {
    $lock->acquire(0.3);
} catch (CompileException $e) {
    $caught = $e;
}
$elapsed = microtime(true) - $start;
assert_true($caught !== null, 'a positive-deadline acquire under contention throws');
assert_true($elapsed >= 0.20, sprintf('a positive deadline actually waits (elapsed %.3fs >= ~0.20s)', $elapsed));
assert_true(str_contains($caught->getMessage(), '0.300'), 'the timeout message names the requested deadline');

// ============================================================================
// 2. timeout ≤ 0 = a single NON-BLOCKING attempt: it fails at once (no busy-wait) while the lock is held.
// ============================================================================
$start2 = microtime(true);
$caught2 = null;
try {
    (new CompileLock($lockPath))->acquire(0.0);
} catch (CompileException $e) {
    $caught2 = $e;
}
assert_true($caught2 !== null, 'a zero/negative deadline fails fast (non-blocking) under contention');
assert_true((microtime(true) - $start2) < 0.10, 'the non-blocking attempt does not busy-wait');

// ============================================================================
// 3. acquire → release → acquire works (the lock is reusable once released).
// ============================================================================
flock($holder, LOCK_UN);
fclose($holder);
$reusable = new CompileLock($lockPath);
$reusable->acquire(0.0);
$reusable->release();
$reusable->acquire(0.0); // re-acquire after release succeeds
$reusable->release();
// release() when not held is a safe no-op.
$reusable->release();

// ============================================================================
// 4. REQUIRED: lock CONTENTION blocks publication BEFORE staging creation. Pre-hold the Coordinator's lock; a real
//    publish (clean fixtures) cannot proceed and must NOT create any `.staging-*` directory.
// ============================================================================
$cleanDir = __DIR__ . '/../fixtures/clean';
$cache = $tmpRoot . '/cache';
mkdir($cache, 0777, true);
$coordinatorLockPath = $cache . '/.compile.lock';
$pre = fopen($coordinatorLockPath, 'c+');
assert_true(flock($pre, LOCK_EX), 'pre-hold the Coordinator compile lock');

$ctx = new ApplicationContext(
    projectRoot: $cache,
    cachePath: $cache,
    discoveryPaths: [$cleanDir],
    mode: ApplicationContext::MODE_MANAGED,
    diagnosticPolicy: ApplicationContext::POLICY_PARITY,
    lockTimeoutSec: 0.0, // non-blocking: fail at once under contention
);
$blocked = null;
try {
    (new Coordinator($ctx))->compile();
} catch (CompileException $e) {
    $blocked = $e;
}
assert_true($blocked !== null, 'a publish under lock contention throws CompileException');
assert_true(str_contains($blocked->getMessage(), 'lock'), 'the contention error mentions the lock');
$leftover = array_filter(scandir($cache), static fn(string $d): bool => str_starts_with($d, '.staging-'));
assert_same([], array_values($leftover), 'under contention NO staging directory is created (staging happens only after the lock is acquired)');
assert_true(!is_file($cache . '/compiled_routes.php'), 'under contention nothing is published');

flock($pre, LOCK_UN);
fclose($pre);

$rrm($tmpRoot);
echo "CompileLock passed\n";
