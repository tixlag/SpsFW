<?php

declare(strict_types=1);

use SpsFW\Core\Compile\CompileException;
use SpsFW\Core\Compile\Publication\StagingPublisher;

require_once dirname(__DIR__, 2) . '/bootstrap.php';

/**
 * Шаг 5: StagingPublisher — flock → same-FS staging → atomic per-file publish with a ROLLBACK JOURNAL. A naive
 * sequence of renames is NOT enough: a failure between renames leaves a mixed set. The journal restores the
 * previous complete set wholesale. Includes fault injection at every step and lock contention.
 */

$tmpRoot = sys_get_temp_dir() . '/spsfw_pub_' . getmypid();
$rrm = static function (string $dir) use (&$rrm): void {
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        is_dir($path) && !is_link($path) ? $rrm($path) : @unlink($path);
    }
    @rmdir($dir);
};

/**
 * Build a cache dir with OLD artifact content, and a staging dir with NEW content, for N artifacts.
 * Returns the staging⇒target map plus the expected old/new contents.
 */
$setup = static function (string $cacheDir, string $stagingDir, int $n, bool $withOld = true) use (&$rrm): array {
    $rrm($cacheDir);
    $rrm($stagingDir);
    mkdir($cacheDir . '/sub', 0777, true);
    mkdir($stagingDir . '/sub', 0777, true);
    $map = [];
    $oldContent = [];
    $newContent = [];
    // A mix of flat + nested targets, mirroring .cache/ + .cache/swagger/.
    $relPaths = [];
    for ($i = 1; $i <= $n; $i++) {
        $relPaths[$i] = $i === $n ? 'sub/openapi.generated.yml' : "art{$i}.php";
    }
    for ($i = 1; $i <= $n; $i++) {
        $rel = $relPaths[$i];
        $target = $cacheDir . '/' . $rel;
        $staging = $stagingDir . '/' . $rel;
        $oldContent[$rel] = "OLD-{$rel}";
        $newContent[$rel] = "NEW-{$rel}";
        file_put_contents($staging, $newContent[$rel]);
        if ($withOld) {
            file_put_contents($target, $oldContent[$rel]);
        }
        $map[$staging] = $target;
    }
    return [$map, $relPaths, $oldContent, $newContent];
};

// ============================================================================
// 1. Successful staging + publication: every target receives the NEW content; the old set is replaced; no
//    backup dir is left behind.
// ============================================================================
$cacheDir = $tmpRoot . '/ok-cache';
$stagingDir = $tmpRoot . '/ok-staging';
[$map, $relPaths, $oldContent, $newContent] = $setup($cacheDir, $stagingDir, 3, withOld: true);
$publisher = new StagingPublisher($cacheDir);
$published = $publisher->publish($map);
assert_same(3, count($published), 'successful publish publishes all 3 files');
foreach ($relPaths as $rel) {
    assert_same($newContent[$rel], file_get_contents($cacheDir . '/' . $rel), "successful publish: $rel has NEW content");
}
assert_same([], glob($cacheDir . '/.backup-*'), 'successful publish leaves no recovery backup dir');

// ============================================================================
// 2. Rollback on injected failure at EACH step: no matter which publish step fails, EVERY target is restored to
//    its OLD content (or re-removed if it had none) — never a mixed half-new/half-old set.
// ============================================================================
$cacheDir = $tmpRoot . '/rollback-cache';
$stagingDir = $tmpRoot . '/rollback-staging';
[$map, $relPaths, $oldContent, $newContent] = $setup($cacheDir, $stagingDir, 3, withOld: true);
$totalSteps = count($map);
for ($failAt = 1; $failAt <= $totalSteps; $failAt++) {
    // Re-create OLD content before each attempt (a prior successful rollback already restored it, but be explicit).
    foreach ($relPaths as $rel) {
        file_put_contents($cacheDir . '/' . $rel, $oldContent[$rel]);
    }
    $publisher = new StagingPublisher($cacheDir);
    $caught = null;
    try {
        $publisher->publish($map, faultHook: static function (int $step) use ($failAt): void {
            if ($step === $failAt) {
                throw new RuntimeException("injected failure at step {$step}");
            }
        });
    } catch (CompileException $e) {
        $caught = $e;
    }
    assert_true($caught !== null, "failure at step {$failAt}: publish throws CompileException");
    foreach ($relPaths as $rel) {
        assert_same($oldContent[$rel], file_get_contents($cacheDir . '/' . $rel), "failure at step {$failAt}: $rel rolled back to OLD content");
    }
    assert_true(empty(glob($cacheDir . '/.backup-*')), "failure at step {$failAt}: rollback fully restores, no recovery backup dir left");
}

// ============================================================================
// 2b. Rollback where some targets had NO prior version (newly-created files): after rollback they are ABSENT
//     again, never left as the half-published new content.
// ============================================================================
$cacheDir = $tmpRoot . '/rollback-new-cache';
$stagingDir = $tmpRoot . '/rollback-new-staging';
[$map, $relPaths, $oldContent, $newContent] = $setup($cacheDir, $stagingDir, 2, withOld: false);
$publisher = new StagingPublisher($cacheDir);
try {
    $publisher->publish($map, faultHook: static function (int $step): void {
        if ($step === 1) {
            throw new RuntimeException('injected failure at first step (newly-created targets)');
        }
    });
    assert_true(false, 'first-step failure on newly-created targets should have thrown');
} catch (CompileException $e) {
    // expected
}
foreach ($relPaths as $rel) {
    assert_true(!is_file($cacheDir . '/' . $rel), "newly-created target $rel is ABSENT after rollback (no prior version to restore)");
}

// ============================================================================
// 3. Manifest published LAST: a failure at the manifest step rolls the artifacts back too, so a partial set
//    never carries a manifest. (The caller orders the map; here the manifest is the final entry.)
// ============================================================================
$cacheDir = $tmpRoot . '/manifest-cache';
$stagingDir = $tmpRoot . '/manifest-staging';
[$map, $relPaths, $oldContent, $newContent] = $setup($cacheDir, $stagingDir, 2, withOld: true);
// Append the manifest as the LAST entry — it lives in .cache/ and must publish after the artifacts.
$manifestTarget = $cacheDir . '/.compile_manifest.php';
$manifestStaging = $stagingDir . '/.compile_manifest.php';
file_put_contents($manifestStaging, "<?php return ['fingerprint' => 'MANIFEST-NEW'];");
file_put_contents($manifestTarget, "<?php return ['fingerprint' => 'MANIFEST-OLD'];");
$mapWithManifest = $map;
$mapWithManifest[$manifestStaging] = $manifestTarget;
$totalSteps = count($mapWithManifest);
$manifestStep = $totalSteps; // last entry

// 3a. Success: manifest ends up with NEW content and is present.
$publisher = new StagingPublisher($cacheDir);
$publisher->publish($mapWithManifest);
assert_same('MANIFEST-NEW', (require($manifestTarget))['fingerprint'], 'on success the manifest is published with its new content');

// 3b. Failure exactly at the manifest (last) step: artifacts AND manifest roll back to OLD.
foreach ($relPaths as $rel) {
    file_put_contents($cacheDir . '/' . $rel, $oldContent[$rel]);
}
file_put_contents($manifestTarget, "<?php return ['fingerprint' => 'MANIFEST-OLD'];");
$publisher = new StagingPublisher($cacheDir);
try {
    $publisher->publish($mapWithManifest, faultHook: static function (int $step) use ($manifestStep): void {
        if ($step === $manifestStep) {
            throw new RuntimeException('manifest step failed');
        }
    });
    assert_true(false, 'manifest-step failure should have thrown');
} catch (CompileException $e) {
    // expected
}
foreach ($relPaths as $rel) {
    assert_same($oldContent[$rel], file_get_contents($cacheDir . '/' . $rel), 'manifest-step failure: artifact rolled back to OLD');
}
assert_same('MANIFEST-OLD', (require($manifestTarget))['fingerprint'], 'manifest-step failure: manifest rolled back to OLD (no partial set keeps a stale manifest)');

// ============================================================================
// 4. Rollback FAILURE keeps the backup and fails EXPLICITLY (Step 5 fix-pass, required test). When the rollback
//    restore rename CANNOT succeed (the target's parent dir is gone), the publisher does NOT claim "restored": it
//    reports an INCOMPLETE rollback, names the unrestorable target, and PRESERVES its backup on disk for manual
//    recovery. Exception-safe + honest — never a false "restored", never a deleted unrestorable backup.
// ============================================================================
$cacheDir = $tmpRoot . '/rollback-fail-cache';
$stagingDir = $tmpRoot . '/rollback-fail-staging';
[$map, $relPaths, $oldContent, $newContent] = $setup($cacheDir, $stagingDir, 1, withOld: true);
$target = $cacheDir . '/' . $relPaths[1];
$publisher = new StagingPublisher($cacheDir);
$caught = null;
try {
    $publisher->publish($map, faultHook: static function (int $step, string $t) use ($target): void {
        if ($step !== 1 || $t !== $target) {
            return;
        }
        // The in-flight file was already backed up (target⇒backup), so the target's parent dir is now EMPTY. Remove
        // it so the rollback's restore rename(backup⇒target) has nowhere to write ⇒ restore FAILS.
        @rmdir(dirname($target));
        throw new RuntimeException('injected failure after backup; parent dir removed so restore cannot land');
    });
    assert_true(false, 'a publish whose rollback cannot restore should throw');
} catch (CompileException $e) {
    $caught = $e;
}
assert_true($caught !== null, 'rollback failure throws CompileException');
assert_true(str_contains($caught->getMessage(), 'INCOMPLETE'), 'rollback failure message says INCOMPLETE (not "restored")');
assert_true(str_contains($caught->getMessage(), $target), 'rollback failure message names the unrestorable target');
// The recovery backup is PRESERVED in a UNIQUE per-run dir (not the shared `.staging-backup`) so an operator can
// recover it manually — and so a LATER publish cannot delete or reuse it.
$backupDirs = glob($cacheDir . '/.backup-*');
assert_same(1, count($backupDirs), 'incomplete rollback keeps exactly ONE recovery backup dir');
$preservedDir = $backupDirs[0];
$preserved = glob($preservedDir . '/*');
assert_same(1, count($preserved), 'exactly one unrestorable backup is preserved in it');
assert_true(str_contains($caught->getMessage(), $preservedDir), 'the INCOMPLETE message names the preserved recovery backup dir');
// The target could NOT be restored, so it is absent (the rollback honestly left it unrestored).
assert_true(!is_file($target), 'the unrestorable target is absent (restore honestly failed)');

// ============================================================================
// 5. REGRESSION (Step 5 fix-pass, required test): an INCOMPLETE rollback's preserved recovery backup SURVIVES a
//    second publish — it is neither deleted nor reused. The second publish gets its OWN unique backup dir; the first
//    run's dir (and its backup file) must STILL exist and be BYTE-IDENTICAL afterward.
// ============================================================================
// Snapshot the first run's preserved recovery backup (its bytes are the OLD content).
$preservedFile = $preserved[0];
$preservedBytes = file_get_contents($preservedFile);
assert_same($oldContent[$relPaths[1]], $preservedBytes, 'the preserved recovery backup holds the OLD content');

// Second publish: fresh content for the SAME target. The first run left the target ABSENT (it could not restore) and
// removed the target's parent dir, so the publisher recreates the parent and writes the target with nothing to back
// up — its own (empty) backup dir is removed on success.
$stagingFile2 = $stagingDir . '/' . $relPaths[1];
$target2 = $cacheDir . '/' . $relPaths[1];
file_put_contents($stagingFile2, "FRESHER-{$relPaths[1]}");
$publisher2 = new StagingPublisher($cacheDir);
$publisher2->publish([$stagingFile2 => $target2]);
assert_same("FRESHER-{$relPaths[1]}", file_get_contents($target2), 'second publish wrote its fresh content');

// The FIRST run's recovery backup is UNTOUCHED — never deleted, never reused, byte-identical.
assert_true(is_dir($preservedDir), 'a second publish does not delete the first run\'s recovery backup dir');
assert_true(is_file($preservedFile), 'the first run\'s backup file is still present after a second publish');
assert_same($preservedBytes, file_get_contents($preservedFile), 'the first run\'s recovery backup is byte-identical after a second publish');
// Only the first run's preserved dir remains — the second run created its OWN dir and cleaned it up on success.
assert_same([$preservedDir], glob($cacheDir . '/.backup-*'), 'after a second publish only the first run\'s preserved recovery backup remains');

$rrm($tmpRoot);
echo "StagingPublisher passed\n";
