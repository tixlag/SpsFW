<?php

declare(strict_types=1);

use SpsFW\Core\Compile\Publication\Fingerprinter;

require_once dirname(__DIR__, 2) . '/bootstrap.php';

/**
 * Шаг 5: Fingerprinter — the source+config fingerprint is DETERMINISTIC and EXCLUDES built_at, so invalidation
 * depends only on WHAT is built, never on WHEN. The manifest records built_at separately (for humans / ordering).
 */

$fingerprinter = new Fingerprinter();

$tmpDir = sys_get_temp_dir() . '/spsfw_fp_' . bin2hex(random_bytes(8));
mkdir($tmpDir, 0777, true);
file_put_contents($tmpDir . '/A.php', "<?php\n// version one\n");
file_put_contents($tmpDir . '/B.php', "<?php\n// controller\n");

$files = $fingerprinter->sourceFiles([$tmpDir]);
assert_same(2, count($files), 'sourceFiles gathers the .php files');

// --- DETERMINISTIC: same source + same config ⇒ identical fingerprint, across repeated calls.
$fp1 = $fingerprinter->fingerprint($files, ['mode' => 'managed', 'policy' => 'parity']);
$fp2 = $fingerprinter->fingerprint($fingerprinter->sourceFiles([$tmpDir]), ['mode' => 'managed', 'policy' => 'parity']);
assert_same($fp1, $fp2, 'fingerprint is deterministic across calls');
assert_same(32, strlen($fp1), 'fingerprint is an md5 hex string');

// --- built_at is NOT in the fingerprint: manifests with different built_at share the same fingerprint.
$mEarly = $fingerprinter->manifest($fp1, ['compiled_routes.php' => 'abc'], ['mode' => 'managed'], '2020-01-01T00:00:00+00:00');
$mLate = $fingerprinter->manifest($fp1, ['compiled_routes.php' => 'abc'], ['mode' => 'managed'], '2030-12-31T23:59:59+00:00');
assert_same('2020-01-01T00:00:00+00:00', $mEarly['built_at'], 'manifest records built_at (early)');
assert_same('2030-12-31T23:59:59+00:00', $mLate['built_at'], 'manifest records built_at (late)');
assert_true($mEarly['built_at'] !== $mLate['built_at'], 'built_at differs between the two manifests');
assert_same($mEarly['fingerprint'], $mLate['fingerprint'], 'but the fingerprint is identical — built_at is not part of it');
assert_same(Fingerprinter::COMPILER_VERSION, $mEarly['compiler_version'], 'manifest carries the compiler version');

// --- a source content change ⇒ a DIFFERENT fingerprint (invalidation fires).
file_put_contents($tmpDir . '/A.php', "<?php\n// version TWO\n");
$fpAfterEdit = $fingerprinter->fingerprint($fingerprinter->sourceFiles([$tmpDir]), ['mode' => 'managed', 'policy' => 'parity']);
assert_true($fp1 !== $fpAfterEdit, 'a source content change produces a different fingerprint');

// --- a config change ⇒ a DIFFERENT fingerprint.
$fpConfigChange = $fingerprinter->fingerprint($fingerprinter->sourceFiles([$tmpDir]), ['mode' => 'legacy', 'policy' => 'parity']);
assert_true($fpAfterEdit !== $fpConfigChange, 'a config change produces a different fingerprint');

// --- config key ORDER is irrelevant (canonicalized).
$fpOrderA = $fingerprinter->fingerprint($fingerprinter->sourceFiles([$tmpDir]), ['b' => 2, 'a' => 1]);
$fpOrderB = $fingerprinter->fingerprint($fingerprinter->sourceFiles([$tmpDir]), ['a' => 1, 'b' => 2]);
assert_same($fpOrderA, $fpOrderB, 'config key order does not affect the fingerprint');

// --- a missing discovery dir is tolerated (empty file set ⇒ still a valid, stable fingerprint).
$emptyFp1 = $fingerprinter->fingerprint($fingerprinter->sourceFiles([$tmpDir . '/does-not-exist']), []);
$emptyFp2 = $fingerprinter->fingerprint($fingerprinter->sourceFiles([$tmpDir . '/does-not-exist']), []);
assert_same($emptyFp1, $emptyFp2, 'empty discovery yields a stable fingerprint');

// cleanup
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
$rrm($tmpDir);
echo "Fingerprinter passed\n";
