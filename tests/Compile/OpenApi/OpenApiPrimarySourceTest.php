<?php

declare(strict_types=1);

use SpsFW\Core\Compile\ApplicationContext;
use SpsFW\Core\Compile\CompileDiagnostics;
use SpsFW\Core\Compile\Coordinator;
use SpsFW\Core\Compile\OpenApi\OpenApiEmitter;
use SpsFW\Core\Compile\OpenApi\OpenApiEscapeHatch;
use SpsFW\Core\Compile\Route\RouteMetadataCompiler;
use SpsFW\Core\Compile\RuleSource;
use SpsFW\Core\DocsUtil;

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once __DIR__ . '/../fixtures/clean/FlowCreateDto.php';
require_once __DIR__ . '/../fixtures/clean/FlowCleanController.php';
require_once __DIR__ . '/escape_hatch/PolymorphicFragment.php';
require_once __DIR__ . '/escape_hatch/ForbiddenOperationFragment.php';

/**
 * Step 8 (M6) — Coordinator-level: the PRIMARY `.cache/swagger/openapi.yml` producer is switchable via the
 * 4th independent {@see \SpsFW\Core\Compile\OpenApiSource} axis.
 *
 *   - Legacy (default): the published PRIMARY is byte-identical to DocsUtil::produceLegacyOpenApiYaml(scanPaths)
 *     (the historical swagger-php producer). PURE rollback for every client.
 *   - Metadata: the published PRIMARY equals dump(merge(emit(operations), escapeHatch)) — the graph document
 *     merged with the narrow OA escape hatch. With an EMPTY hatch (the M6 default / the common case) the merge is
 *     a no-op, so the Metadata primary EQUALS the pure graph document.
 *
 * The SECONDARY openapi.generated.yml is the PURE graph under BOTH modes (byte-identical between Legacy/Metadata).
 *
 * EMIT-ONCE: the graph is built by a SINGLE $emitter->emit() call in Coordinator::compile() (verified by code
 * review — exactly one call site at Coordinator.php). CompileDiagnostics DEDUPS by signature, so a diagnostic
 * COUNT cannot prove single-emission; instead the array-first contract is pinned separately (merge()/validate()
 * on an already-built doc add no new diagnostics — extended in OpenApiEmitterTest). No contrived counter seam.
 */

$fixturesRoot = dirname(__DIR__) . '/fixtures';
$cleanDir = $fixturesRoot . '/clean';

// The legacy scan target — an ISOLATED #[OA\Schema] fixture (PolymorphicFragment: PolymorphicThing + ConcreteA
// + ConcreteB, no operations). Scanning it yields a non-trivial, deterministic swagger-php doc WITHOUT the
// pre-existing framework duplicate-#[OA\Schema] warnings (the production default scans [src, libraryRoot];
// here an isolated fragment keeps the test hermetic). Passed explicitly so the byte-identity assertion compares
// against the EXACT paths the Coordinator scans.
$polyFragment = __DIR__ . '/escape_hatch/PolymorphicFragment.php';
$legacyScanPaths = [$polyFragment];

$mkCache = static function (): string {
    $dir = sys_get_temp_dir() . '/spsfw_prim_' . bin2hex(random_bytes(8));
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

// The EXPECTED producers, computed ONCE from the same fixtures/inputs the Coordinator will use.
$routeDiag = new CompileDiagnostics();
$compiler = new RouteMetadataCompiler($routeDiag, operationIdMap: [], ruleSource: RuleSource::Legacy);
$endpointSet = $compiler->compileEndpointSet([$cleanDir], []);
$expectedOperations = $endpointSet->operations;
$expectedGraphDoc = (new OpenApiEmitter(new CompileDiagnostics()))->emit($expectedOperations, title: 'Primary API', version: '8.8');
$expectedGraphYaml = (new OpenApiEmitter(new CompileDiagnostics()))->dump($expectedGraphDoc);
// Metadata primary with an EMPTY hatch ⇒ merge no-op ⇒ dump(graph).
$expectedMetadataYaml = (new OpenApiEmitter(new CompileDiagnostics()))->dump($expectedGraphDoc);
// Legacy primary ⇒ the historical swagger-php producer over the same scan paths.
$expectedLegacyYaml = DocsUtil::produceLegacyOpenApiYaml($legacyScanPaths);

// ============================================================================
// 1. LEGACY: published PRIMARY byte-identical to DocsUtil; SECONDARY is the pure graph.
// ============================================================================
$cacheLegacy = $mkCache();
$caches[] = $cacheLegacy;
$ctxLegacy = new ApplicationContext(
    projectRoot: $cacheLegacy,
    cachePath: $cacheLegacy,
    discoveryPaths: [$cleanDir],
    configInputs: ['openapi_title' => 'Primary API', 'openapi_version' => '8.8'],
    mode: ApplicationContext::MODE_MANAGED,
    legacyOpenApiScanPaths: $legacyScanPaths,
    openApiSource: ApplicationContext::OPENAPI_SOURCE_LEGACY,
);
$resLegacy = (new Coordinator($ctxLegacy))->compile();
assert_true($resLegacy->success && $resLegacy->published, 'legacy primary: clean build published');
assert_same(0, $resLegacy->errorCount, 'legacy primary: no errors');
assert_same($expectedLegacyYaml, file_get_contents($cacheLegacy . '/swagger/openapi.yml'), 'legacy: PRIMARY openapi.yml is byte-identical to DocsUtil::produceLegacyOpenApiYaml(scanPaths)');
assert_same($expectedGraphYaml, file_get_contents($cacheLegacy . '/swagger/openapi.generated.yml'), 'legacy: SECONDARY is the pure graph (dump(emit()))');
// sanity: the legacy swagger-php producer and the graph emitter are DIFFERENT projections of the same app.
assert_true($expectedLegacyYaml !== $expectedGraphYaml, 'legacy primary (swagger-php) ≠ secondary (graph) — distinct producers');

// ============================================================================
// 2. METADATA: published PRIMARY equals dump(merge(emit(), emptyHatch)) ⇒ with empty hatch, the pure graph;
//    SECONDARY is the pure graph under Metadata too (so primary == secondary here).
// ============================================================================
$cacheMeta = $mkCache();
$caches[] = $cacheMeta;
$ctxMeta = new ApplicationContext(
    projectRoot: $cacheMeta,
    cachePath: $cacheMeta,
    discoveryPaths: [$cleanDir],
    configInputs: ['openapi_title' => 'Primary API', 'openapi_version' => '8.8'],
    mode: ApplicationContext::MODE_MANAGED,
    legacyOpenApiScanPaths: $legacyScanPaths,
    openApiSource: ApplicationContext::OPENAPI_SOURCE_METADATA,
    openApiEscapeHatch: OpenApiEscapeHatch::empty(),
);
$resMeta = (new Coordinator($ctxMeta))->compile();
assert_true($resMeta->success && $resMeta->published, 'metadata primary: clean build published (empty hatch)');
assert_same(0, $resMeta->errorCount, 'metadata primary: no errors');
assert_same($expectedMetadataYaml, file_get_contents($cacheMeta . '/swagger/openapi.yml'), 'metadata: PRIMARY equals dump(merge(emit(), emptyHatch)) ⇒ the pure graph');
assert_same($expectedGraphYaml, file_get_contents($cacheMeta . '/swagger/openapi.generated.yml'), 'metadata: SECONDARY is the pure graph');
// empty hatch ⇒ Metadata primary == secondary (both are the pure graph).
assert_same(file_get_contents($cacheMeta . '/swagger/openapi.yml'), file_get_contents($cacheMeta . '/swagger/openapi.generated.yml'), 'metadata empty hatch: PRIMARY == SECONDARY (both pure graph)');

// ============================================================================
// 3. SECONDARY is byte-identical between Legacy and Metadata (the graph never depends on the primary producer).
// ============================================================================
assert_same(
    file_get_contents($cacheLegacy . '/swagger/openapi.generated.yml'),
    file_get_contents($cacheMeta . '/swagger/openapi.generated.yml'),
    'secondary: pure graph is byte-identical between Legacy and Metadata runs',
);

// ============================================================================
// 4. Manifest records config_inputs.openapi_source + escape_hatch_hash; fingerprint differs on the flip.
// ============================================================================
$mLegacy = require($cacheLegacy . '/.compile_manifest.php');
$mMeta = require($cacheMeta . '/.compile_manifest.php');
assert_same('legacy', $mLegacy['config_inputs']['openapi_source'], 'legacy manifest records openapi_source=legacy');
assert_same('metadata', $mMeta['config_inputs']['openapi_source'], 'metadata manifest records openapi_source=metadata');
assert_true(array_key_exists('escape_hatch_hash', $mLegacy), 'legacy manifest carries escape_hatch_hash');
assert_true(array_key_exists('escape_hatch_hash', $mMeta), 'metadata manifest carries escape_hatch_hash');
assert_same(32, strlen($mLegacy['escape_hatch_hash']), 'legacy escape_hatch_hash is a 32-char md5');
assert_same(32, strlen($mMeta['escape_hatch_hash']), 'metadata escape_hatch_hash is a 32-char md5');
// Both runs use an empty hatch ⇒ identical escape_hatch_hash (the hatch content is the same).
assert_same($mLegacy['escape_hatch_hash'], $mMeta['escape_hatch_hash'], 'empty hatch ⇒ identical escape_hatch_hash across modes');
// The flip changes openapi_source in config ⇒ different fingerprint (identical sources otherwise).
assert_true($resLegacy->fingerprint !== $resMeta->fingerprint, 'fingerprint differs on Legacy↔Metadata flip (openapi_source in config)');
assert_same($resLegacy->fingerprint, $mLegacy['fingerprint'], 'legacy manifest fingerprint matches the result');
assert_same($resMeta->fingerprint, $mMeta['fingerprint'], 'metadata manifest fingerprint matches the result');

// ============================================================================
// 5. ERROR in the merge (a forbidden-operation fragment under Metadata) ⇒ NOT published; prior artifact hashes
//    UNCHANGED (mirrors CoordinatorFlowTest #2). Establish a prior good Metadata set, then run an error build.
// ============================================================================
$cacheErr = $mkCache();
$caches[] = $cacheErr;
$ctxPrior = new ApplicationContext(
    projectRoot: $cacheErr,
    cachePath: $cacheErr,
    discoveryPaths: [$cleanDir],
    configInputs: ['openapi_title' => 'Primary API', 'openapi_version' => '8.8'],
    mode: ApplicationContext::MODE_MANAGED,
    legacyOpenApiScanPaths: $legacyScanPaths,
    openApiSource: ApplicationContext::OPENAPI_SOURCE_METADATA,
    openApiEscapeHatch: OpenApiEscapeHatch::empty(),
);
(new Coordinator($ctxPrior))->compile();
$priorPrimaryHash = md5_file($cacheErr . '/swagger/openapi.yml');
$priorSecondaryHash = md5_file($cacheErr . '/swagger/openapi.generated.yml');
$priorManifest = require($cacheErr . '/.compile_manifest.php');

$ctxForbidden = new ApplicationContext(
    projectRoot: $cacheErr,
    cachePath: $cacheErr,
    discoveryPaths: [$cleanDir],
    configInputs: ['openapi_title' => 'Primary API', 'openapi_version' => '8.8'],
    mode: ApplicationContext::MODE_MANAGED,
    legacyOpenApiScanPaths: $legacyScanPaths,
    openApiSource: ApplicationContext::OPENAPI_SOURCE_METADATA,
    // A hatch pointing at a fragment carrying a forbidden #[OA\Get] — the annotation guard FATALs the merge.
    openApiEscapeHatch: new OpenApiEscapeHatch([\SpsOaTest\EscapeHatch\ForbiddenOperationCarrier::class], []),
);
$resForbidden = (new Coordinator($ctxForbidden))->compile();
assert_true($resForbidden->success, 'forbidden-hatch build: completed (no crash)');
assert_true(!$resForbidden->published, 'forbidden-hatch build: NOT published');
assert_true($resForbidden->errorCount >= 1, 'forbidden-hatch build: at least one error recorded (the merge guard)');
assert_same('errors', $resForbidden->reason, 'forbidden-hatch build: reason is errors');
// Prior (good) artifact set UNCHANGED — no half-published overwrite.
assert_same($priorPrimaryHash, md5_file($cacheErr . '/swagger/openapi.yml'), 'forbidden-hatch build: prior PRIMARY hash unchanged');
assert_same($priorSecondaryHash, md5_file($cacheErr . '/swagger/openapi.generated.yml'), 'forbidden-hatch build: prior SECONDARY hash unchanged');
assert_same($priorManifest['fingerprint'], (require($cacheErr . '/.compile_manifest.php'))['fingerprint'], 'forbidden-hatch build: prior manifest (fingerprint) unchanged');

foreach ($caches as $c) {
    $rrm($c);
}
echo "OpenApiPrimarySource passed\n";
