<?php

declare(strict_types=1);

use SpsFW\Core\Compile\Publication\Fingerprinter;

require_once dirname(__DIR__, 2) . '/bootstrap.php';

/**
 * Шаг 5 fix-pass: Fingerprinter — the source+config fingerprint is DETERMINISTIC, EXCLUDES built_at, is
 * DEPLOY-PATH-INDEPENDENT (sources keyed relative to projectRoot), and hashes compile-time config FILES and the
 * operationId/route-override MAPS by CONTENT (the manifest stores only md5, never file content or the maps — no
 * secrets are stored wholesale).
 */

$fingerprinter = new Fingerprinter();

$tmpDir = sys_get_temp_dir() . '/spsfw_fp_' . bin2hex(random_bytes(8));
mkdir($tmpDir, 0777, true);
file_put_contents($tmpDir . '/A.php', "<?php\n// version one\n");
file_put_contents($tmpDir . '/B.php', "<?php\n// controller\n");

$files = $fingerprinter->sourceFiles([$tmpDir]);
assert_same(2, count($files), 'sourceFiles gathers the .php files');

// --- DETERMINISTIC: same source + same config + same projectRoot ⇒ identical fingerprint.
$fp1 = $fingerprinter->fingerprint($files, ['mode' => 'managed'], $tmpDir);
$fp2 = $fingerprinter->fingerprint($fingerprinter->sourceFiles([$tmpDir]), ['mode' => 'managed'], $tmpDir);
assert_same($fp1, $fp2, 'fingerprint is deterministic across calls');
assert_same(32, strlen($fp1), 'fingerprint is an md5 hex string');

// --- built_at is NOT in the fingerprint: manifests with different built_at share the same fingerprint.
$mEarly = $fingerprinter->manifest($fp1, ['compiled_routes.php' => 'abc'], ['mode' => 'managed'], [], [], [], [], '2020-01-01T00:00:00+00:00');
$mLate = $fingerprinter->manifest($fp1, ['compiled_routes.php' => 'abc'], ['mode' => 'managed'], [], [], [], [], '2030-12-31T23:59:59+00:00');
assert_same('2020-01-01T00:00:00+00:00', $mEarly['built_at'], 'manifest records built_at (early)');
assert_same('2030-12-31T23:59:59+00:00', $mLate['built_at'], 'manifest records built_at (late)');
assert_true($mEarly['built_at'] !== $mLate['built_at'], 'built_at differs between the two manifests');
assert_same($mEarly['fingerprint'], $mLate['fingerprint'], 'but the fingerprint is identical — built_at is not part of it');
assert_same(Fingerprinter::COMPILER_VERSION, $mEarly['compiler_version'], 'manifest carries the compiler version');

// --- a source content change ⇒ a DIFFERENT fingerprint (invalidation fires).
file_put_contents($tmpDir . '/A.php', "<?php\n// version TWO\n");
$fpAfterEdit = $fingerprinter->fingerprint($fingerprinter->sourceFiles([$tmpDir]), ['mode' => 'managed'], $tmpDir);
assert_true($fp1 !== $fpAfterEdit, 'a source content change produces a different fingerprint');

// --- a config change ⇒ a DIFFERENT fingerprint.
$fpConfigChange = $fingerprinter->fingerprint($fingerprinter->sourceFiles([$tmpDir]), ['mode' => 'legacy'], $tmpDir);
assert_true($fpAfterEdit !== $fpConfigChange, 'a config change produces a different fingerprint');

// --- config key ORDER is irrelevant (canonicalized).
$fpOrderA = $fingerprinter->fingerprint($fingerprinter->sourceFiles([$tmpDir]), ['b' => 2, 'a' => 1], $tmpDir);
$fpOrderB = $fingerprinter->fingerprint($fingerprinter->sourceFiles([$tmpDir]), ['a' => 1, 'b' => 2], $tmpDir);
assert_same($fpOrderA, $fpOrderB, 'config key order does not affect the fingerprint');

// ============================================================================
// DEPLOY-PATH-INDEPENDENCE (Step 5 fix-pass): the SAME source tree checked out at two different absolute roots
// yields the SAME fingerprint, because sources are keyed RELATIVE to projectRoot.
// ============================================================================
$rootA = sys_get_temp_dir() . '/spsfw_deployA_' . bin2hex(random_bytes(4));
$rootB = sys_get_temp_dir() . '/spsfw_deployB_' . bin2hex(random_bytes(4));
foreach ([$rootA, $rootB] as $root) {
    mkdir($root . '/src', 0777, true);
    file_put_contents($root . '/src/A.php', "<?php\n// identical content\n");
    file_put_contents($root . '/src/B.php', "<?php\n// identical controller\n");
}
$fpDeployA = $fingerprinter->fingerprint($fingerprinter->sourceFiles([$rootA . '/src']), [], $rootA);
$fpDeployB = $fingerprinter->fingerprint($fingerprinter->sourceFiles([$rootB . '/src']), [], $rootB);
assert_same($fpDeployA, $fpDeployB, 'deploy-path-independent: identical trees at different roots share a fingerprint');

// ============================================================================
// Compile-time CONFIG FILES are hashed by CONTENT (md5); a content change fires invalidation. The manifest stores
// ONLY the md5 — never the file content — so a secrets-bearing di_config is never stored wholesale.
// ============================================================================
$cfgRoot = sys_get_temp_dir() . '/spsfw_cfg_' . bin2hex(random_bytes(4));
mkdir($cfgRoot, 0777, true);
$diConfig = $cfgRoot . '/di_config.php';
file_put_contents($diConfig, "<?php\nreturn ['secret' => 'hunter2'];\n");
$configFiles = ['di_config' => $diConfig];
$fpCfg1 = $fingerprinter->fingerprint($fingerprinter->sourceFiles([$cfgRoot]), [], $cfgRoot, $configFiles);
file_put_contents($diConfig, "<?php\nreturn ['secret' => 'hunter2-changed'];\n");
$fpCfg2 = $fingerprinter->fingerprint($fingerprinter->sourceFiles([$cfgRoot]), [], $cfgRoot, $configFiles);
assert_true($fpCfg1 !== $fpCfg2, 'a di_config content change fires a fingerprint change');
// Manifest carries config_file_hashes (md5 only), and the content is NOT in the manifest.
$manifestCfg = $fingerprinter->manifest($fpCfg1, [], [], $configFiles, [], [], [], '2020-01-01T00:00:00+00:00');
assert_true(array_key_exists('config_file_hashes', $manifestCfg), 'manifest carries config_file_hashes');
assert_true(array_key_exists('di_config', $manifestCfg['config_file_hashes']), 'manifest carries the di_config hash');
assert_true(str_contains($manifestCfg['config_file_hashes']['di_config'], (string) md5_file($diConfig)) === false || $manifestCfg['config_file_hashes']['di_config'] === md5_file($diConfig), 'di_config hash is a plain md5');
$serialized = var_export($manifestCfg, true);
assert_true(!str_contains($serialized, 'hunter2'), 'the manifest does NOT store the di_config secret content');

// ============================================================================
// operationId / route-override MAPS are hashed into the fingerprint and stored in the manifest only as md5 — never
// the map wholesale.
// ============================================================================
$opMap = ['App\\Ctl::login' => 'loginUser', 'App\\Ctl::deferred' => null];
$routeMap = ['POST:/auth/login' => 'App\\Ctl::login'];
$fpMaps = $fingerprinter->fingerprint($fingerprinter->sourceFiles([$cfgRoot]), [], $cfgRoot, [], $opMap, $routeMap);
$fpNoMaps = $fingerprinter->fingerprint($fingerprinter->sourceFiles([$cfgRoot]), [], $cfgRoot, [], [], []);
assert_true($fpMaps !== $fpNoMaps, 'the operationId/route-override maps feed the fingerprint');
$manifestMaps = $fingerprinter->manifest($fpMaps, [], [], [], $opMap, $routeMap, [['key' => 'POST:/auth/login', 'winner' => 'App\\Ctl::login', 'shadowed' => ['Core\\Ctl::login']]], '2020-01-01T00:00:00+00:00');
assert_true(array_key_exists('map_hashes', $manifestMaps), 'manifest carries map_hashes');
assert_true(array_key_exists('operation_id_map', $manifestMaps['map_hashes']), 'manifest carries operation_id_map hash');
assert_true(array_key_exists('overrides_applied', $manifestMaps), 'manifest carries overrides_applied (observability)');
assert_same(1, count($manifestMaps['overrides_applied']), 'overrides_applied records the one applied override');
$serializedMaps = var_export($manifestMaps, true);
assert_true(!str_contains($serializedMaps, 'loginUser'), 'the manifest does NOT store the operationId map wholesale (only its hash)');

// --- a missing discovery dir is tolerated (empty file set ⇒ still a valid, stable fingerprint).
$emptyFp1 = $fingerprinter->fingerprint($fingerprinter->sourceFiles([$tmpDir . '/does-not-exist']), [], $tmpDir);
$emptyFp2 = $fingerprinter->fingerprint($fingerprinter->sourceFiles([$tmpDir . '/does-not-exist']), [], $tmpDir);
assert_same($emptyFp1, $emptyFp2, 'empty discovery yields a stable fingerprint');

// ============================================================================
// Step 6b parity fix: legacyOpenApiScanPaths PARTICIPATE in the fingerprint, ORDER-PRESERVING (swagger-php output is
// order-sensitive), RELATIVE-NORMALIZED (deploy-path-independent), and are NEVER stored in the manifest (absolute or
// relative). The historical [src, libraryRoot] order and the route-discovery [libraryRoot, src] order MUST yield
// different fingerprints — this PINS the parity contract (the latent bug was reusing discovery order for the spec).
// ============================================================================
$scanRoot = sys_get_temp_dir() . '/spsfw_scan_' . bin2hex(random_bytes(4));
mkdir($scanRoot, 0777, true);
$appScan = $scanRoot . '/scan_app';   // stands in for the app src
$libScan = $scanRoot . '/scan_lib';   // stands in for the framework libraryRoot
mkdir($appScan, 0777, true);
mkdir($libScan, 0777, true);
file_put_contents($appScan . '/C.php', "<?php\n// app\n");
file_put_contents($libScan . '/C.php', "<?php\n// library\n");
$scanFiles = $fingerprinter->sourceFiles([$appScan, $libScan]);

// Participates: empty vs present ⇒ different fingerprint.
$fpNoScan = $fingerprinter->fingerprint($scanFiles, [], $scanRoot, [], [], [], []);
$fpHistorical = $fingerprinter->fingerprint($scanFiles, [], $scanRoot, [], [], [], [$appScan, $libScan]);
assert_true($fpNoScan !== $fpHistorical, 'legacyOpenApiScanPaths participate in the fingerprint');

// ORDER-PRESERVING: historical [src, libraryRoot] ≠ discovery [libraryRoot, src] — order is NOT sorted away.
$fpDiscovery = $fingerprinter->fingerprint($scanFiles, [], $scanRoot, [], [], [], [$libScan, $appScan]);
assert_true($fpHistorical !== $fpDiscovery, 'scan-path ORDER matters: [src, libraryRoot] ≠ [libraryRoot, src] (pins the parity contract)');

// DEPLOY-PATH-INDEPENDENT (but order-preserving): identical trees at a different absolute root, same order ⇒ same fingerprint.
$scanRoot2 = sys_get_temp_dir() . '/spsfw_scan2_' . bin2hex(random_bytes(4));
mkdir($scanRoot2, 0777, true);
$appScan2 = $scanRoot2 . '/scan_app';
$libScan2 = $scanRoot2 . '/scan_lib';
mkdir($appScan2, 0777, true);
mkdir($libScan2, 0777, true);
file_put_contents($appScan2 . '/C.php', "<?php\n// app\n");
file_put_contents($libScan2 . '/C.php', "<?php\n// library\n");
$fpHistorical2 = $fingerprinter->fingerprint($fingerprinter->sourceFiles([$appScan2, $libScan2]), [], $scanRoot2, [], [], [], [$appScan2, $libScan2]);
assert_same($fpHistorical, $fpHistorical2, 'scan paths are relative-normalized: identical trees at different roots, same order ⇒ same fingerprint');

// NEVER in the manifest: only the fingerprint hash carries the scan paths — the manifest stores neither absolute nor relative paths.
$manifestScan = $fingerprinter->manifest($fpHistorical, [], [], [], [], [], [], '2020-01-01T00:00:00+00:00');
$manifestScanSerialized = var_export($manifestScan, true);
assert_true(!str_contains($manifestScanSerialized, $appScan) && !str_contains($manifestScanSerialized, $libScan), 'the manifest does NOT carry the absolute scan paths');
assert_true(!str_contains($manifestScanSerialized, 'scan_app') && !str_contains($manifestScanSerialized, 'scan_lib'), 'the manifest does NOT carry the relative scan paths either');

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
$rrm($rootA);
$rrm($rootB);
$rrm($cfgRoot);
$rrm($scanRoot);
$rrm($scanRoot2);
echo "Fingerprinter passed\n";
