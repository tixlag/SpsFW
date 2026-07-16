<?php

declare(strict_types=1);

namespace SpsFW\Core\Compile\Publication;

use SpsFW\Core\Compile\CompileException;

/**
 * Whole-flow compile lock (Step 5 fix-pass, plan §11.3).
 *
 * A SINGLE lock scope covers the ENTIRE compilation flow — discovery → build → validation → staging → publication
 * → cleanup — so two concurrent compiles can never interleave (one never stages while another publishes, never share
 * a staging dir, never half-overwrite an artifact set). {@see StagingPublisher} therefore does NOT take its own lock
 * (it must not re-acquire the SAME lock the Coordinator already holds): the Coordinator acquires this lock ONCE and
 * releases it in a `finally` after the publisher returns.
 *
 * TIMEOUT is REAL (plan §11.3): `LOCK_NB` + a busy-wait `usleep` loop up to a deadline. The historical `lockTimeoutSec`
 * effectively only worked for 0 (a single non-blocking attempt) because the blocking branch ignored it; here a
 * positive deadline is honored. `≤0` ⇒ a single NON-BLOCKING attempt (no busy-wait) — the safe default for a generic
 * CLI that must not hang on a contended lock.
 *
 * This is an EXCEPTION-SAFE process-local advisory lock (flock), NOT crash-safe: a process killed mid-flow leaves the
 * stale staging behind for the next run to clean (the lock itself is released by the OS on process death). No
 * persistent journal is claimed here — crash-safety of the artifact set is the {@see StagingPublisher} rollback
 * concern, which is likewise exception-safe, not crash-safe.
 */
final class CompileLock
{
    /** @var resource|null the held lock handle, null while not held */
    private $handle;

    /** The busy-wait granularity for the LOCK_NB + deadline loop. */
    private const POLL_MICROSECONDS = 50_000; // 50ms

    public function __construct(
        private readonly string $lockPath,
    ) {
    }

    public function lockPath(): string
    {
        return $this->lockPath;
    }

    /**
     * Acquire the exclusive lock, busy-waiting up to $timeoutSec.
     *
     * @param float $timeoutSec deadline; ≤0 ⇒ a single non-blocking attempt (fail at once if held).
     * @throws CompileException if the lock cannot be opened or cannot be acquired within the deadline.
     */
    public function acquire(float $timeoutSec): void
    {
        if ($this->handle !== null) {
            return; // already held by this instance — no re-acquire (whole-flow scope).
        }
        if (!is_dir(dirname($this->lockPath))) {
            mkdir(dirname($this->lockPath), 0777, true);
        }
        $handle = @fopen($this->lockPath, 'c+');
        if ($handle === false) {
            throw new CompileException(sprintf('Cannot open compile lock at %s', $this->lockPath));
        }

        $deadline = $timeoutSec > 0.0 ? microtime(true) + $timeoutSec : null;
        // First attempt is always immediate; the loop only spins when a positive deadline is set and the lock is held.
        while (true) {
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                $this->handle = $handle;
                return;
            }
            if ($deadline === null) {
                fclose($handle);
                throw new CompileException(sprintf(
                    'Could not acquire compile lock at %s (non-blocking attempt) — another compile is in progress',
                    $this->lockPath,
                ));
            }
            if (microtime(true) >= $deadline) {
                fclose($handle);
                throw new CompileException(sprintf(
                    'Could not acquire compile lock at %s within %.3fs — another compile is in progress',
                    $this->lockPath,
                    $timeoutSec,
                ));
            }
            usleep(self::POLL_MICROSECONDS);
        }
    }

    /**
     * Release the lock if held. Safe to call when not held (no-op). Runs best-effort: a release failure is ignored
     * (the OS reclaims the lock on process death regardless), since at this point the flow has already completed.
     */
    public function release(): void
    {
        $handle = $this->handle;
        if ($handle === null) {
            return;
        }
        $this->handle = null;
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
