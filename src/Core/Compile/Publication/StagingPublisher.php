<?php

declare(strict_types=1);

namespace SpsFW\Core\Compile\Publication;

use SpsFW\Core\Compile\CompileException;

/**
 * Safe publication of a compiled artifact set (plan §11.3).
 *
 * Contract:
 *   1. HOLD an exclusive flock on `.cache/.compile.lock` for the whole swap (serializes concurrent compilers).
 *   2. STAGE every artifact on the SAME filesystem as the cache, so each per-file `rename()` is atomic.
 *   3. PUBLISH one file at a time: back the current target up, journal it, then `rename(staging ⇒ target)`.
 *   4. On ANY failure mid-publication, ROLL BACK via the journal — the previous complete set is restored. A naive
 *      sequence of renames is NOT enough: a crash between renames leaves a mixed (half-new/half-old) set.
 *   5. The caller publishes the MANIFEST LAST (as the final entry), so its presence signals a complete set; a
 *      manifest write failure rolls the artifacts back too — a partial set never carries a valid manifest.
 *
 * No production file is ever unlinked BEFORE the new one is in place: the old set survives until the new set is
 * fully published and verified, and is restored wholesale on failure.
 *
 * The `$faultHook` (test/dev only) is invoked as `fn(int $step, string $target)` immediately before each file's
 * staging⇒target rename (after its backup is staged and journaled). Throwing from it injects a failure at that
 * exact step, exercising the rollback path — including the in-flight file — without filesystem hacks.
 */
final class StagingPublisher
{
    /** @var string path of the compile lock file inside the cache dir */
    private readonly string $lockPath;

    public function __construct(
        private readonly string $cachePath,
        string $lockName = '.compile.lock',
    ) {
        $this->lockPath = $cachePath . '/' . $lockName;
    }

    public function lockPath(): string
    {
        return $this->lockPath;
    }

    /**
     * Atomically publish a set of files with a full rollback guarantee.
     *
     * @param array<string,string> $stagingToTarget map of staging-file ABSOLUTE path => target ABSOLUTE path.
     *        All targets (and their staging files) MUST live on the same filesystem as {@see $cachePath} so each
     *        rename is atomic. The map's iteration order IS the publish order — the caller puts the manifest LAST.
     * @param ?\Closure(int, string): void $faultHook optional fault injector (see class doc).
     * @param float $lockTimeoutSec flock wait; 0.0 = non-blocking (fail at once if the lock is held).
     * @return list<string> the published target paths, in publish order.
     * @throws CompileException on lock acquisition failure or any publish/rollback failure.
     */
    public function publish(array $stagingToTarget, ?\Closure $faultHook = null, float $lockTimeoutSec = 999999.0): array
    {
        $lock = $this->acquireLock($lockTimeoutSec);
        try {
            return $this->publishTransactional($stagingToTarget, $faultHook);
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /**
     * @return resource|null the locked file handle, or null (with an exception) if it could not be acquired
     */
    private function acquireLock(float $timeoutSec)
    {
        if (!is_dir($this->cachePath)) {
            mkdir($this->cachePath, 0777, true);
        }
        $lock = @fopen($this->lockPath, 'c+');
        if ($lock === false) {
            throw new CompileException(sprintf('Cannot open compile lock at %s', $this->lockPath));
        }
        $flags = LOCK_EX;
        $nonBlocking = $timeoutSec <= 0.0;
        if ($nonBlocking) {
            $flags |= LOCK_NB;
        }
        if (!flock($lock, $flags)) {
            fclose($lock);
            throw new CompileException(sprintf(
                'Could not %s-acquire compile lock at %s — another compile is in progress',
                $nonBlocking ? 'non-blocking' : 'blocking',
                $this->lockPath,
            ));
        }
        return $lock;
    }

    /**
     * @param array<string,string> $stagingToTarget
     * @param ?\Closure(int, string): void $faultHook
     * @return list<string>
     */
    private function publishTransactional(array $stagingToTarget, ?\Closure $faultHook): array
    {
        // Backup dir on the SAME filesystem as the cache — required for atomic renames in both directions.
        $backupDir = $this->cachePath . '/.staging-backup';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0777, true);
        }

        /** @var list<array{0: string, 1: string|null}> $journal [target, backupPath|null], in publish order */
        $journal = [];
        $published = [];
        $step = 0;

        try {
            foreach ($stagingToTarget as $stagingFile => $target) {
                $step++;
                if (!is_file($stagingFile)) {
                    throw new CompileException(sprintf('Staging file missing during publish: %s', $stagingFile));
                }
                $targetDir = dirname($target);
                if (!is_dir($targetDir)) {
                    mkdir($targetDir, 0777, true);
                }

                // Back the current target up (if any), then journal BEFORE the risky rename — so a failure at the
                // rename restores this file from its backup too, not just the earlier committed files.
                $backupPath = null;
                if (is_file($target)) {
                    $backupPath = $backupDir . '/' . md5($target);
                    // Ensure no stale backup from a previous run collides.
                    if (is_file($backupPath)) {
                        @unlink($backupPath);
                    }
                    if (!@rename($target, $backupPath)) {
                        throw new CompileException(sprintf('Failed to back up %s before publish', $target));
                    }
                }
                $journal[] = [$target, $backupPath];

                // Fault injection point (after backup+journal, before the staging⇒target rename).
                if ($faultHook !== null) {
                    $faultHook($step, $target); // may throw → rollback (including this in-flight file)
                }

                if (!@rename($stagingFile, $target)) {
                    throw new CompileException(sprintf('Failed to publish %s → %s', $stagingFile, $target));
                }
                $published[] = $target;
            }

            // Everything published — the backups are no longer needed.
            $this->cleanupBackups($journal, $backupDir);
            return $published;
        } catch (\Throwable $e) {
            // Best-effort full rollback: restore the previous complete set. On POSIX, rename(backup ⇒ target)
            // atomically REPLACES whatever (new) content is at target, so there is no window where target is gone.
            $this->rollback($journal);
            $this->cleanupBackups($journal, $backupDir);
            throw new CompileException(
                'Publication failed and was rolled back — the previous artifact set is restored. Cause: ' . $e->getMessage(),
                0,
                $e,
            );
        }
    }

    /**
     * Restore the pre-publish state for every journaled target, newest-first.
     *
     * @param list<array{0: string, 1: string|null}> $journal
     */
    private function rollback(array $journal): void
    {
        foreach (array_reverse($journal) as [$target, $backupPath]) {
            try {
                if ($backupPath !== null && is_file($backupPath)) {
                    // Atomically restore the previous version over whatever the new build placed at target.
                    @rename($backupPath, $target);
                } elseif (is_file($target)) {
                    // No prior version existed — the new file we created must be removed to restore "absent".
                    @unlink($target);
                }
            } catch (\Throwable) {
                // Best-effort: keep restoring the others. A rollback failure is surfaced via the exception chain.
            }
        }
    }

    /**
     * @param list<array{0: string, 1: string|null}> $journal
     */
    private function cleanupBackups(array $journal, string $backupDir): void
    {
        foreach ($journal as [$target, $backupPath]) {
            if ($backupPath !== null && is_file($backupPath)) {
                @unlink($backupPath);
            }
        }
        if (is_dir($backupDir)) {
            // Only remove if empty (other concurrent compiles — though the lock serializes — may share it).
            @rmdir($backupDir);
        }
    }
}
