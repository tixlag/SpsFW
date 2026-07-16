<?php

declare(strict_types=1);

use SpsFW\Core\Compile\ApplicationContext;
use SpsFW\Core\Compile\Coordinator;
use SpsFW\Core\Compile\Publication\Fingerprinter;

require_once dirname(__DIR__) . '/bootstrap.php';
// Load every fixture class up front so DI/rule-graph reflection (which needs the class loaded) works regardless
// of autoloading — the real app relies on PSR-4; the fixtures live under tests/ and are required explicitly.
require_once __DIR__ . '/fixtures/clean/FlowCreateDto.php';
require_once __DIR__ . '/fixtures/clean/FlowCleanController.php';
require_once __DIR__ . '/fixtures/error/FlowDupController.php';
require_once __DIR__ . '/fixtures/warn/FlowWarnController.php';

/**
 * Шаг 5: Coordinator end-to-end flow on fixture controllers. Covers the required tests:
 *   1. successful staging + publication (clean build);
 *   2. compile ERROR → old artifact hashes UNCHANGED;
 *   3. a WARNING passes under PARITY and blocks under STRICT (mode and strictness are decoupled);
 * plus a dry-run that builds + reports but writes nothing (the read-only probe contract).
 */

$fixturesRoot = __DIR__ . '/fixtures';
$cleanDir = $fixturesRoot . '/clean';
$errorDir = $fixturesRoot . '/error';
$warnDir = $fixturesRoot . '/warn';

$mkCache = static function (): string {
    $dir = sys_get_temp_dir() . '/spsfw_coord_' . bin2hex(random_bytes(8));
    mkdir($dir, 0777, true);
    return $dir;
};
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
$caches = [];

// ============================================================================
// 1. Successful staging + publication (clean controller, managed + parity).
// ============================================================================
$cache = $mkCache();
$caches[] = $cache;
$ctx = new ApplicationContext(
    projectRoot: $cache,
    cachePath: $cache,
    discoveryPaths: [$cleanDir],
    configInputs: ['openapi_title' => 'Flow API', 'openapi_version' => '9.9'],
    mode: ApplicationContext::MODE_MANAGED,
    diagnosticPolicy: ApplicationContext::POLICY_PARITY,
);
$result = (new Coordinator($ctx))->compile();
assert_true($result->success, 'clean build: success');
assert_true($result->published, 'clean build: published');
assert_true(!$result->dryRun, 'clean build: not a dry run');
assert_same(0, $result->errorCount, 'clean build: no errors');
assert_same(0, $result->warningCount, 'clean build: no warnings');

// All artifacts + manifest present:
assert_true(is_file($cache . '/compiled_routes.php'), 'clean build: route cache published');
assert_true(is_file($cache . '/compiled_di.php'), 'clean build: DI map published');
assert_true(is_file($cache . '/job_registry.php'), 'clean build: job registry published');
assert_true(is_file($cache . '/swagger/openapi.generated.yml'), 'clean build: secondary openapi published');
assert_true(is_file($cache . '/.compile_manifest.php'), 'clean build: manifest published');
assert_same($cache . '/.compile_manifest.php', $result->manifestPath, 'clean build: manifest path reported');
assert_true(!is_dir($cache . '/.staging-compile'), 'clean build: staging dir cleaned up');

// The published route cache is a loadable PHP array, byte-compatible with Router's IR (METHOD:path keys).
$routes = require($cache . '/compiled_routes.php');
assert_true(is_array($routes), 'clean build: route cache is a PHP array');
assert_same(2, count($routes), 'clean build: 2 routes cached');
assert_true(array_key_exists('GET:/flow/health', $routes), 'clean build: GET:/flow/health key present');
assert_true(array_key_exists('POST:/flow/create', $routes), 'clean build: POST:/flow/create key present');

// Manifest carries compiler version, fingerprint, artifact hashes (built_at present but not in fingerprint).
$manifest = require($cache . '/.compile_manifest.php');
assert_same(Fingerprinter::COMPILER_VERSION, $manifest['compiler_version'], 'clean build: compiler version recorded');
assert_same($result->fingerprint, $manifest['fingerprint'], 'clean build: manifest fingerprint matches the result');
assert_true(array_key_exists('built_at', $manifest), 'clean build: built_at recorded in manifest');
foreach (['compiled_routes.php', 'compiled_di.php', 'job_registry.php', 'swagger/openapi.generated.yml'] as $rel) {
    assert_true(array_key_exists($rel, $manifest['artifact_hashes']), "clean build: manifest hashes $rel");
    assert_same(md5_file($cache . '/' . $rel), $manifest['artifact_hashes'][$rel], "clean build: manifest hash of $rel matches the published file");
}

// The DI map is loadable and includes the analyzed fixtures.
$diMap = require($cache . '/compiled_di.php');
assert_true(is_array($diMap), 'clean build: DI map is a PHP array');
assert_true(array_key_exists(\SpsFWTest\CompileFixtures\Clean\FlowCleanController::class, $diMap), 'clean build: controller in DI map');
assert_true(array_key_exists(\SpsFWTest\CompileFixtures\Clean\FlowCreateDto::class, $diMap), 'clean build: DTO in DI map');

// ============================================================================
// 2. Compile ERROR → old artifact hashes UNCHANGED. Establish a prior good set, then run an error build against
//    the SAME cache: the 15-style structural ERROR must block publication and leave the prior set byte-identical.
// ============================================================================
$cache2 = $mkCache();
$caches[] = $cache2;
$ctxPrior = new ApplicationContext(projectRoot: $cache2, cachePath: $cache2, discoveryPaths: [$cleanDir], mode: ApplicationContext::MODE_MANAGED);
(new Coordinator($ctxPrior))->compile();
$priorRouteHash = md5_file($cache2 . '/compiled_routes.php');
$priorDiHash = md5_file($cache2 . '/compiled_di.php');
$priorManifest = require($cache2 . '/.compile_manifest.php');

$ctxErr = new ApplicationContext(projectRoot: $cache2, cachePath: $cache2, discoveryPaths: [$errorDir], mode: ApplicationContext::MODE_MANAGED);
$errResult = (new Coordinator($ctxErr))->compile();
assert_true($errResult->success, 'error build: completed (no crash)');
assert_true(!$errResult->published, 'error build: NOT published');
assert_same('errors', $errResult->reason, 'error build: reason is errors');
assert_true($errResult->errorCount >= 1, 'error build: at least one error recorded');

// The prior (good) artifact set is UNCHANGED — hashes identical, no half-published overwrite.
assert_same($priorRouteHash, md5_file($cache2 . '/compiled_routes.php'), 'error build: old route cache hash unchanged');
assert_same($priorDiHash, md5_file($cache2 . '/compiled_di.php'), 'error build: old DI map hash unchanged');
assert_same($priorManifest['fingerprint'], (require($cache2 . '/.compile_manifest.php'))['fingerprint'], 'error build: old manifest (fingerprint) unchanged');

// ============================================================================
// 3. A WARNING passes PARITY and blocks STRICT — mode and strictness are DECOUPLED (the same warning fixture,
//    only the diagnosticPolicy differs).
// ============================================================================
// 3a. PARITY: the array-return warning is tolerated → published.
$cacheP = $mkCache();
$caches[] = $cacheP;
$ctxP = new ApplicationContext(projectRoot: $cacheP, cachePath: $cacheP, discoveryPaths: [$warnDir], diagnosticPolicy: ApplicationContext::POLICY_PARITY);
$resP = (new Coordinator($ctxP))->compile();
assert_true($resP->warningCount >= 1, 'warn fixture: at least one warning recorded');
assert_same(0, $resP->errorCount, 'warn fixture: no errors (it is a migration gap, not structural)');
assert_true($resP->published, 'parity tolerates the warning → published');
assert_true(is_file($cacheP . '/compiled_routes.php'), 'parity: route cache written');

// 3b. STRICT: the same warning blocks → NOT published, nothing written.
$cacheS = $mkCache();
$caches[] = $cacheS;
$ctxS = new ApplicationContext(projectRoot: $cacheS, cachePath: $cacheS, discoveryPaths: [$warnDir], diagnosticPolicy: ApplicationContext::POLICY_STRICT);
$resS = (new Coordinator($ctxS))->compile();
assert_true($resS->warningCount >= 1, 'strict: same warning present');
assert_true(!$resS->published, 'strict blocks on the warning → NOT published');
assert_same('strict-warnings', $resS->reason, 'strict: reason is strict-warnings');
assert_true(!is_file($cacheS . '/compiled_routes.php'), 'strict blocked: nothing published');

// 3c. DECOUPLED: managed+strict and legacy+parity are both honored — mode does not override the policy gate.
$ctxMS = new ApplicationContext(projectRoot: $cacheP, cachePath: $mkCache(), discoveryPaths: [$warnDir], mode: ApplicationContext::MODE_MANAGED, diagnosticPolicy: ApplicationContext::POLICY_STRICT);
$caches[] = $ctxMS->cachePath;
assert_true(!(new Coordinator($ctxMS))->compile()->published, 'managed+strict still blocks on a warning');
$ctxLP = new ApplicationContext(projectRoot: $cacheP, cachePath: $mkCache(), discoveryPaths: [$warnDir], mode: ApplicationContext::MODE_LEGACY, diagnosticPolicy: ApplicationContext::POLICY_PARITY);
$caches[] = $ctxLP->cachePath;
assert_true((new Coordinator($ctxLP))->compile()->published, 'legacy+parity still tolerates a warning');

// ============================================================================
// Dry run (read-only probe contract): builds + validates + reports a fingerprint, but writes NOTHING.
// ============================================================================
$cacheD = $mkCache();
$caches[] = $cacheD;
$ctxD = new ApplicationContext(projectRoot: $cacheD, cachePath: $cacheD, discoveryPaths: [$cleanDir]);
$resD = (new Coordinator($ctxD))->compile(dryRun: true);
assert_true($resD->dryRun, 'dry run: flagged');
assert_true(!$resD->published, 'dry run: not published');
assert_same('dry-run', $resD->reason, 'dry run: reason is dry-run');
assert_true($resD->fingerprint !== null, 'dry run: fingerprint still computed');
assert_true(!is_file($cacheD . '/compiled_routes.php'), 'dry run: no route cache written');
assert_true(!is_file($cacheD . '/.compile_manifest.php'), 'dry run: no manifest written');

foreach ($caches as $c) {
    $rrm($c);
}
echo "CoordinatorFlow passed\n";
