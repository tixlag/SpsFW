<?php

declare(strict_types=1);

namespace SpsFW\Core\Compile\Publication;

use SpsFW\Core\Compile\CompileException;

/**
 * Safe publication of a compiled artifact set (plan §11.3, Step 5 fix-pass).
 *
 * Contract:
 *   1. The CALLER (the Coordinator) holds a whole-flow {@see CompileLock}; this publisher takes NO lock of its own
 *      (it must not re-acquire the same lock the Coordinator already holds).
 *   2. STAGE every artifact on the SAME filesystem as the cache, so each per-file `rename()` is atomic.
 *   3. PUBLISH one file at a time: back the current target up, journal it, then `rename(staging ⇒ target)`.
 *   4. On ANY failure mid-publication, ROLL BACK via the journal — RESTORING each target and CHECKING each result.
 *      A rollback that cannot fully restore is NOT reported as "restored": the unrestorable targets and their
 *      PRESERVED backups are surfaced explicitly in the exception, and those backups are kept on disk for manual
 *      recovery. The guarantee is EXCEPTION-SAFE (best-effort restore with honest reporting) — NOT crash-safe: there
 *      is no persistent journal/versioned directory, so a process killed mid-publication leaves a mixed set.
 *   5. The caller publishes the MANIFEST LAST (as the final entry), so its presence signals a complete set; a
 *      manifest write failure rolls the artifacts back too — a partial set never carries a valid manifest.
 *
 * No production file is ever unlinked BEFORE the new one is in place: the old set survives until the new set is
 * fully published and verified, and is restored wholesale on failure (to the extent the rollback succeeds).
 *
 * The `$faultHook` (test/dev only) is invoked as `fn(int $step, string $target)` immediately before each file's
 * staging⇒target rename (after its backup is staged and journaled). Throwing from it injects a failure at that
 * exact step, exercising the rollback path — including the in-flight file — without filesystem hacks.
 */
final class StagingPublisher
{
    public function __construct(
        private readonly string $cachePath,
    ) {
    }

    /**
     * Publish a set of files with a best-effort, exception-safe rollback guarantee.
     *
     * @param array<string,string> $stagingToTarget map of staging-file ABSOLUTE path => target ABSOLUTE path.
     *        All targets (and their staging files) MUST live on the same filesystem as {@see $cachePath} so each
     *        rename is atomic. The map's iteration order IS the publish order — the caller puts the manifest LAST.
     * @param ?\Closure(int, string): void $faultHook optional fault injector (see class doc).
     * @return list<string> the published target paths, in publish order.
     * @throws CompileException on any publish failure (after best-effort rollback), or when rollback was incomplete.
     */
    public function publish(array $stagingToTarget, ?\Closure $faultHook = null): array
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

            // Everything published — the backups of the old content are no longer needed.
            $this->cleanupBackups($journal, $backupDir, []);
            return $published;
        } catch (\Throwable $e) {
            // Best-effort full rollback: restore the previous set target-by-target, CHECKING each rename/unlink.
            $unrestorable = $this->rollback($journal);
            // Keep backups for any target that could NOT be restored; remove only the successfully-restored ones.
            $this->cleanupBackups($journal, $backupDir, $unrestorable);
            throw $this->rollbackException($e, $unrestorable);
        }
    }

    /**
     * Restore the pre-publish state for every journaled target, newest-first, CHECKING the result of each operation.
     *
     * @param list<array{0: string, 1: string|null}> $journal
     * @return list<array{target: string, backup: ?string, reason: string}> the targets that could NOT be restored
     *         (their backups are intentionally left in place for manual recovery).
     */
    private function rollback(array $journal): array
    {
        $unrestorable = [];
        foreach (array_reverse($journal) as [$target, $backupPath]) {
            if ($backupPath !== null && is_file($backupPath)) {
                // Atomically restore the previous version over whatever the new build placed at target.
                if (!@rename($backupPath, $target)) {
                    $unrestorable[] = ['target' => $target, 'backup' => $backupPath, 'reason' => 'restore rename(backup ⇒ target) failed'];
                }
            } elseif (is_file($target)) {
                // No prior version existed — the new file we created must be removed to restore "absent".
                if (!@unlink($target)) {
                    $unrestorable[] = ['target' => $target, 'backup' => null, 'reason' => 'unlink of newly-created target failed'];
                }
            }
            // If neither branch applies (no backup, target already absent) there is nothing to restore — not a failure.
        }
        return $unrestorable;
    }

    /**
     * Remove backups whose target was successfully restored (so they are no longer needed). Backups of UNRESTORABLE
     * targets are KEPT on disk for manual recovery. On a successful publish, $unrestorable is empty (every backup
     * is stale old content) so all are removed.
     *
     * @param list<array{0: string, 1: string|null}> $journal
     * @param list<array{target: string, backup: ?string, reason: string}> $unrestorable
     */
    private function cleanupBackups(array $journal, string $backupDir, array $unrestorable): void
    {
        $keep = [];
        foreach ($unrestorable as $entry) {
            if ($entry['backup'] !== null) {
                $keep[$entry['backup']] = true;
            }
        }
        foreach ($journal as [$target, $backupPath]) {
            if ($backupPath !== null && is_file($backupPath) && !isset($keep[$backupPath])) {
                @unlink($backupPath);
            }
        }
        if (is_dir($backupDir)) {
            // Only remove if empty (no preserved unrestorable backups, and no concurrent compile sharing it).
            @rmdir($backupDir);
        }
    }

    /**
     * Build an HONEST rollback exception: claim "restored" only when every target was restored; otherwise name the
     * unrestorable targets and the preserved backups so an operator can recover.
     *
     * @param list<array{target: string, backup: ?string, reason: string}> $unrestorable
     */
    private function rollbackException(\Throwable $e, array $unrestorable): CompileException
    {
        if ($unrestorable === []) {
            return new CompileException(
                'Publication failed and was rolled back — the previous artifact set is restored. Cause: ' . $e->getMessage(),
                0,
                $e,
            );
        }
        $lines = [];
        foreach ($unrestorable as $entry) {
            $lines[] = sprintf('  • %s — %s (backup preserved at %s)', $entry['target'], $entry['reason'], $entry['backup'] ?? '(none)');
        }
        return new CompileException(
            "Publication failed AND rollback was INCOMPLETE — the previous set is NOT fully restored. Unrestorable:\n"
            . implode("\n", $lines)
            . "\nOriginal cause: " . $e->getMessage(),
            0,
            $e,
        );
    }
}
