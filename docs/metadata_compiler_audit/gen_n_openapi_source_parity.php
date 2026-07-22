<?php

/**
 * Dev-only audit probe (NOT a committed test). Step 8 (M6) N-side FULL-COORDINATOR parity — the OpenAPI-source
 * PRIMARY-PRODUCER SWITCH, proven on REAL production controllers through the WHOLE engine.
 *
 * Sibling to gen_n_route_source_parity.php (same non-invasive bootstrap). It drives the FULL Coordinator TWICE
 * over N's real discovery tree — once with openApiSource=Legacy (A), once with openApiSource=Metadata (B) — into
 * TWO throwaway temp cache dirs (real N/.cache is never touched, proven by a before/after snapshot). Both runs use
 * IDENTICAL inputs: discovery paths, operationId map, route-override map, DI bindings, legacy OpenAPI scan paths,
 * the empty escape hatch, ruleSource=Metadata (the N production default — UNCHANGED), and POLICY_PARITY. Only
 * openApiSource differs. Then it asserts:
 *
 *   MANDATORY: A errors = 0 AND B errors = 0 (a non-zero ERROR in EITHER mode is a real defect). The probe is NOT
 *     successful when both modes share the same non-zero ERROR count.
 *   - both runs PUBLISH (published=true, success=true) the full 6-file artifact set;
 *   - compiled_routes.php / compiled_di.php / job_registry.php are BYTE-IDENTICAL between A and B (route/DI/job
 *     artifacts are openapi-source-independent — the switch touches ONLY the PRIMARY openapi.yml);
 *   - the SECONDARY swagger/openapi.generated.yml is BYTE-IDENTICAL between A and B (the pure graph never depends
 *     on the primary producer);
 *   - A's PRIMARY swagger/openapi.yml is byte-identical to the standalone historical producer
 *     DocsUtil::produceLegacyOpenApiYaml(scanPaths) — sanity that the Legacy path reproduces swagger-php exactly;
 *   - B's PRIMARY swagger/openapi.yml (empty hatch ⇒ merge no-op) EQUALS B's SECONDARY (both are the pure graph),
 *     normalized-array strict ===, and PASSES OpenApiValidator;
 *   - the manifest records config_inputs.openapi_source = 'legacy' (A) vs 'metadata' (B) respectively;
 *   - the fingerprints DIFFER between A and B (openapi_source participates in the fingerprint ⇒ cache
 *     invalidation when the primary producer flips);
 *   - real N/.cache is byte-unchanged before/after; NO files outside the throwaway temp caches are touched (P /
 *     public_next is never referenced by this probe).
 *
 * WARNINGS are tolerated under POLICY_PARITY and reported SEPARATELY from the verdict. For B, the probe also
 * records the normalized divergence vs A's primary (legacy swagger-php vs the graph are DIFFERENT projections of
 * the same app — divergence at M6 is expected backlog for M7–M9, NOT auto-failure if structurally valid). Any
 * unsuppressed PHP warning/notice/deprecation originating from THIS probe, the SpsFW working-tree src, or the
 * probed SpsNext src FAILS the probe (set_error_handler → ErrorException); library-internal vendor warnings
 * (swagger-php logger) are swallowed, mirroring the production Coordinator path.
 *
 * TARGETING A SPECIFIC N TREE: set SPSFW_N_NEXT_ROOT, e.g.
 *   SPSFW_N_NEXT_ROOT=/home/tixlag/PhpstormProjects/.wt/lk-step6b/next php docs/metadata_compiler_audit/gen_n_openapi_source_parity.php
 *
 * Bootstrapping mirrors gen_n_route_source_parity.php (see its header for the classmap-override rationale).
 */

declare(strict_types=1);

use SpsFW\Core\Compile\ApplicationContext;
use SpsFW\Core\Compile\Coordinator;
use SpsFW\Core\Compile\OpenApi\OpenApiEscapeHatch;
use SpsFW\Core\Compile\OpenApi\OpenApiValidator;
use SpsFW\Core\Compile\OpenApiSource;
use SpsFW\Core\Compile\RuleSource;
use SpsFW\Core\DocsUtil;
use SpsFW\Core\Router\PathManager;
use SpsFW\Core\Compile\CompileDiagnostics;
use Symfony\Component\Yaml\Yaml;

// ============================================================================
// 0. Locate the N tree (env override for a worktree; default = sibling checkout).
// ============================================================================
$spsfwRoot = dirname(__DIR__, 2);
$nextRoot = (static function () use ($spsfwRoot): string {
    $env = getenv('SPSFW_N_NEXT_ROOT');
    return is_string($env) && $env !== '' ? rtrim($env, '/') : dirname($spsfwRoot) . '/lk.sps38.pro/next';
})();

if (!is_dir($nextRoot)) {
    fwrite(STDERR, "consumer repo not found at $nextRoot — nothing to probe\n");
    exit(2);
}
$nextAutoload = $nextRoot . '/vendor/autoload.php';
if (!is_file($nextAutoload)) {
    fwrite(STDERR, "next vendor autoload not found at $nextAutoload\n");
    exit(2);
}

// ============================================================================
// 1. Bootstrap: N autoload + prepend the local SpsFW src + override BOTH classmaps + register SpsNext\.
// ============================================================================
/** @var \Composer\Autoload\ClassLoader $loader */
$loader = require $nextAutoload;

/** Scan a src dir and return FQCN => path for every class/interface/trait/enum declared in it. */
$scanClassMap = static function (string $srcDir): array {
    $map = [];
    if (!is_dir($srcDir)) {
        return $map;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile() || $f->getExtension() !== 'php') {
            continue;
        }
        $src = file_get_contents($f->getPathname());
        $ns = preg_match('/^\s*namespace\s+([^\s;]+)\s*;/m', $src, $m) ? $m[1] : '';
        if (preg_match('/^\s*(?:final\s+|abstract\s+|readonly\s+)*?(?:class|interface|trait|enum)\s+([A-Za-z0-9_]+)/m', $src, $m)) {
            $map[($ns !== '' ? $ns . '\\' : '') . $m[1]] = $f->getPathname();
        }
    }
    return $map;
};

$loader->addPsr4('SpsFW\\', [$spsfwRoot . '/src'], true); // local working tree wins over the vendored copy
$loader->addPsr4('SpsNext\\', $nextRoot . '/src');
$loader->addClassMap($scanClassMap($spsfwRoot . '/src'));
$loader->addClassMap($scanClassMap($nextRoot . '/src'));

putenv('SPSFW_PROJECT_ROOT=' . $nextRoot);
$_ENV['SPSFW_PROJECT_ROOT'] = $nextRoot;

// Seed DI bindings exactly as N's preload does.
$diConfig = $nextRoot . '/config/di_config.php';
if (is_file($diConfig)) {
    $diBindings = require $diConfig;
    if (is_array($diBindings)) {
        \SpsFW\Core\Config::setDIBindings($diBindings);
    }
}

// ============================================================================
// 2. Error handler: vendor-internal warnings swallowed (swagger-php logger); everything else is a probe FAILURE.
// ============================================================================
set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
    if ((error_reporting() & $errno) === 0) {
        return false; // @-suppressed
    }
    if (str_contains($errfile, '/vendor/') || str_contains($errfile, '\\vendor\\')) {
        return true; // library-internal — tolerated as in production
    }
    throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
}, E_WARNING | E_NOTICE | E_DEPRECATED | E_USER_WARNING | E_USER_NOTICE | E_USER_DEPRECATED);

// ============================================================================
// 3. Load production config from real N files — including the (empty) escape hatch.
// ============================================================================
$operationIdMap = is_file($nextRoot . '/config/operation_id_map.lock.php')
    ? (require $nextRoot . '/config/operation_id_map.lock.php')
    : [];
$routeOverrideMap = is_file($nextRoot . '/config/route_override_map.php')
    ? (require $nextRoot . '/config/route_override_map.php')
    : [];
$configFiles = array_filter([
    'di_config' => $nextRoot . '/config/di_config.php',
    'operation_id_map' => $nextRoot . '/config/operation_id_map.lock.php',
    'route_override_map' => $nextRoot . '/config/route_override_map.php',
    'openapi_escape_hatch' => $nextRoot . '/config/openapi_escape_hatch.php',
], static fn(string $p): bool => is_file($p));

// The escape hatch (Step 8 / M6) — N ships the empty default, so the Metadata PRIMARY is the pure graph.
$hatchConfig = is_file($nextRoot . '/config/openapi_escape_hatch.php')
    ? (require $nextRoot . '/config/openapi_escape_hatch.php')
    : ['scan' => [], 'schemas' => []];
$escapeHatch = new OpenApiEscapeHatch(
    is_array($hatchConfig['scan'] ?? null) ? $hatchConfig['scan'] : [],
    is_array($hatchConfig['schemas'] ?? null) ? $hatchConfig['schemas'] : [],
);

$dirs = array_values(array_filter(PathManager::getControllersDirs(), 'is_dir'));
$legacyOpenApiScanPaths = [PathManager::getSrcPath(), PathManager::getLibraryRoot()]; // historical [src, libraryRoot] parity order

// ============================================================================
// 4. Snapshot real N/.cache BEFORE (proves the probe is read-only w.r.t. the live cache).
// ============================================================================
$snapshotCache = static function (string $dir): array {
    $hashes = [];
    if (!is_dir($dir)) {
        return $hashes;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile()) {
            continue;
        }
        $rel = substr($f->getPathname(), strlen($dir) + 1);
        if (preg_match('#^(?:\.compile\.lock$|\.staging-|\.backup-)#', $rel)) {
            continue;
        }
        $hashes[$rel] = md5_file($f->getPathname());
    }
    ksort($hashes);
    return $hashes;
};
$cacheBefore = $snapshotCache($nextRoot . '/.cache');

// ============================================================================
// 5. Build the FULL Coordinator in BOTH openapi-source modes, into throwaway temp caches. Only openApiSource
//    differs — ruleSource stays Metadata (the N production default), the hatch is empty for both.
// ============================================================================
$cacheBase = sys_get_temp_dir() . '/n_oasrc_parity_' . bin2hex(random_bytes(4));
$madeDirs = [];

$buildRun = static function (OpenApiSource $openApiSource) use (
    $nextRoot, $dirs, $operationIdMap, $routeOverrideMap, $configFiles, $legacyOpenApiScanPaths, $escapeHatch, $cacheBase, &$madeDirs
): array {
    $tag = $openApiSource->isLegacy() ? 'legacy' : 'metadata';
    $cacheDir = $cacheBase . '/' . $tag . '_' . bin2hex(random_bytes(4));
    mkdir($cacheDir, 0777, true);
    $madeDirs[] = $cacheDir;

    $ctx = new ApplicationContext(
        projectRoot: $nextRoot,
        cachePath: $cacheDir,
        discoveryPaths: $dirs,
        configInputs: ['openapi_title' => 'next', 'openapi_version' => '0.1.0'],
        mode: ApplicationContext::MODE_MANAGED,
        diagnosticPolicy: ApplicationContext::POLICY_PARITY,
        operationIdMap: $operationIdMap,
        routeOverrideMap: $routeOverrideMap,
        configFiles: $configFiles,
        lockTimeoutSec: 30.0,
        legacyOpenApiScanPaths: $legacyOpenApiScanPaths,
        ruleSource: RuleSource::Metadata, // the N production default — UNCHANGED by this probe
        openApiSource: $openApiSource, // the ONLY axis that differs between A and B
        openApiEscapeHatch: $escapeHatch, // empty for both ⇒ B primary == pure graph
    );
    $coord = new Coordinator($ctx);
    return [$coord->compile(), $coord, $cacheDir];
};

$failures = [];
$warningsReport = ['legacy' => [], 'metadata' => []];

try {
    [$resA, $coordA, $cacheA] = $buildRun(OpenApiSource::Legacy);
    [$resB, $coordB, $cacheB] = $buildRun(OpenApiSource::Metadata);

    $warningsReport['legacy'] = array_map(static fn(array $w): string => ($w['controller'] ?? '?') . ($w['method'] ? '::' . $w['method'] : '') . ' — ' . $w['cause'], $coordA->diagnostics()->warnings());
    $warningsReport['metadata'] = array_map(static fn(array $w): string => ($w['controller'] ?? '?') . ($w['method'] ? '::' . $w['method'] : '') . ' — ' . $w['cause'], $coordB->diagnostics()->warnings());

    $check = static function (bool $ok, string $label, string $detail = '') use (&$failures): void {
        if (!$ok) {
            $failures[] = $label . ($detail !== '' ? ' :: ' . $detail : '');
        }
    };

    // ---- published + mandatory 0 errors ----
    $check($resA->success && $resA->published, 'A (Legacy) run published', "success=" . ($resA->success ? 'true' : 'false') . " published=" . ($resA->published ? 'true' : 'false') . " reason=" . ($resA->reason ?? 'null'));
    $check($resB->success && $resB->published, 'B (Metadata) run published', "success=" . ($resB->success ? 'true' : 'false') . " published=" . ($resB->published ? 'true' : 'false') . " reason=" . ($resB->reason ?? 'null'));
    $check($resA->errorCount === 0, 'A (Legacy) run: 0 errors (MANDATORY)', 'errorCount=' . $resA->errorCount);
    $check($resB->errorCount === 0, 'B (Metadata) run: 0 errors (MANDATORY)', 'errorCount=' . $resB->errorCount);

    // ---- the full 6-file artifact set exists in each throwaway cache ----
    $artifacts = ['compiled_routes.php', 'compiled_di.php', 'job_registry.php', 'swagger/openapi.yml', 'swagger/openapi.generated.yml', '.compile_manifest.php'];
    foreach (['A(legacy)' => $cacheA, 'B(metadata)' => $cacheB] as $tag => $cacheDir) {
        foreach ($artifacts as $rel) {
            $check(is_file($cacheDir . '/' . $rel), "$tag cache has artifact $rel");
        }
    }

    // ---- route/DI/job artifacts BYTE-IDENTICAL between A and B (openapi-source-independent) ----
    $check(file_get_contents($cacheA . '/compiled_routes.php') === file_get_contents($cacheB . '/compiled_routes.php'), 'compiled_routes.php byte-identical A vs B', 'len A=' . filesize($cacheA . '/compiled_routes.php') . ' B=' . filesize($cacheB . '/compiled_routes.php'));
    $check(file_get_contents($cacheA . '/compiled_di.php') === file_get_contents($cacheB . '/compiled_di.php'), 'compiled_di.php byte-identical A vs B');
    $check(file_get_contents($cacheA . '/job_registry.php') === file_get_contents($cacheB . '/job_registry.php'), 'job_registry.php byte-identical A vs B');

    // ---- SECONDARY openapi.generated.yml byte-identical (the pure graph never depends on the primary producer) ----
    $genA = file_get_contents($cacheA . '/swagger/openapi.generated.yml');
    $genB = file_get_contents($cacheB . '/swagger/openapi.generated.yml');
    $check($genA === $genB, 'SECONDARY swagger/openapi.generated.yml byte-identical A vs B (pure graph)');

    // ---- A's PRIMARY == standalone historical producer (sanity: the Legacy path reproduces swagger-php exactly) ----
    $primaryA = file_get_contents($cacheA . '/swagger/openapi.yml');
    $historicalLegacy = DocsUtil::produceLegacyOpenApiYaml($legacyOpenApiScanPaths);
    $check($primaryA === $historicalLegacy, 'A PRIMARY swagger/openapi.yml byte-identical to DocsUtil::produceLegacyOpenApiYaml(scanPaths)');

    // ---- B's PRIMARY == B's SECONDARY (empty hatch ⇒ merge no-op ⇒ B primary is the pure graph) ----
    $primaryB = file_get_contents($cacheB . '/swagger/openapi.yml');
    $check($primaryB === $genB, 'B PRIMARY == B SECONDARY (empty hatch ⇒ Metadata primary is the pure graph)');

    // ---- B's PRIMARY passes OpenApiValidator (structurally valid; refs resolve) ----
    $bDoc = Yaml::parse($primaryB);
    $bValidDiag = new CompileDiagnostics();
    (new OpenApiValidator($bValidDiag))->validate(is_array($bDoc) ? $bDoc : []);
    $check(!$bValidDiag->hasErrors(), 'B PRIMARY passes OpenApiValidator', $bValidDiag->hasErrors() ? "\n" . $bValidDiag->render() : '');

    // ---- manifests record openapi_source + fingerprints differ ----
    $manifestA = require $cacheA . '/.compile_manifest.php';
    $manifestB = require $cacheB . '/.compile_manifest.php';
    $check(($manifestA['config_inputs']['openapi_source'] ?? null) === 'legacy', 'A manifest openapi_source=legacy', 'got ' . var_export($manifestA['config_inputs']['openapi_source'] ?? null, true));
    $check(($manifestB['config_inputs']['openapi_source'] ?? null) === 'metadata', 'B manifest openapi_source=metadata', 'got ' . var_export($manifestB['config_inputs']['openapi_source'] ?? null, true));
    $check(array_key_exists('escape_hatch_hash', $manifestA) && array_key_exists('escape_hatch_hash', $manifestB), 'both manifests carry escape_hatch_hash');
    $check(($manifestA['fingerprint'] ?? null) !== ($manifestB['fingerprint'] ?? null), 'Fingerprints differ on source switch (openapi_source invalidates cache)');

    // ---- real N/.cache BYTE-UNCHANGED ----
    $cacheAfter = $snapshotCache($nextRoot . '/.cache');
    $check($cacheBefore === $cacheAfter, 'Real N/.cache byte-unchanged before/after', 'files before=' . count($cacheBefore) . ' after=' . count($cacheAfter));

    // ============================================================================
    // 6. Report.
    // ============================================================================
    // Counts for the normalized divergence (informational — A legacy swagger-php vs B graph are different projections).
    $countDoc = static function (?array $doc): array {
        if (!is_array($doc)) {
            return ['paths' => 0, 'ops' => 0, 'schemas' => 0];
        }
        $paths = $doc['paths'] ?? [];
        $ops = 0;
        foreach ($paths as $methods) {
            if (is_array($methods)) {
                $ops += count($methods);
            }
        }
        return ['paths' => count($paths), 'ops' => $ops, 'schemas' => count($doc['components']['schemas'] ?? [])];
    };
    $aDoc = Yaml::parse($primaryA);
    $cA = $countDoc(is_array($aDoc) ? $aDoc : []);
    $cB = $countDoc(is_array($bDoc) ? $bDoc : []);

    echo "==== N openapi-source parity — FULL Coordinator (Step 8 / M6) ====\n";
    echo "next root: $nextRoot\n";
    echo "discovery dirs: " . implode(', ', $dirs) . "\n";
    echo "legacy OpenAPI scan: " . implode(', ', $legacyOpenApiScanPaths) . "\n";
    echo "escape hatch: " . ($escapeHatch->isEmpty() ? 'EMPTY (default)' : count($escapeHatch->scanTargets) . ' target(s)') . " | rule source: metadata (UNCHANGED)\n";
    echo "route overrides: " . count($routeOverrideMap) . " | operationId map: " . count($operationIdMap) . " | config files: " . implode(',', array_keys($configFiles)) . "\n";
    echo "\n";
    echo "published: A(legacy)=" . ($resA->published ? 'YES' : 'NO') . " B(metadata)=" . ($resB->published ? 'YES' : 'NO') . "\n";
    echo "errors:    A=" . $resA->errorCount . " B=" . $resB->errorCount . "  (MANDATORY: both 0)\n";
    echo "warnings:  A=" . $resA->warningCount . " B=" . $resB->warningCount . "  (tolerated under POLICY_PARITY; reported separately)\n";
    echo "fingerprints differ: A=" . substr((string)$manifestA['fingerprint'], 0, 12) . " B=" . substr((string)$manifestB['fingerprint'], 0, 12) . "\n";
    echo "manifest openapi_source: A=" . ($manifestA['config_inputs']['openapi_source'] ?? '?') . " B=" . ($manifestB['config_inputs']['openapi_source'] ?? '?') . "\n";
    echo "compiled_routes.php / compiled_di.php / job_registry.php byte-identical A vs B: " . ((file_get_contents($cacheA . '/compiled_routes.php') === file_get_contents($cacheB . '/compiled_routes.php')) && (file_get_contents($cacheA . '/compiled_di.php') === file_get_contents($cacheB . '/compiled_di.php')) && (file_get_contents($cacheA . '/job_registry.php') === file_get_contents($cacheB . '/job_registry.php')) ? 'YES' : 'NO') . "\n";
    echo "SECONDARY openapi.generated.yml identical A vs B: " . ($genA === $genB ? 'YES' : 'NO') . "\n";
    echo "A PRIMARY == DocsUtil historical producer: " . ($primaryA === $historicalLegacy ? 'YES' : 'NO') . "\n";
    echo "B PRIMARY == pure graph (== B secondary): " . ($primaryB === $genB ? 'YES' : 'NO') . "\n";
    echo "B PRIMARY passes OpenApiValidator: " . (!$bValidDiag->hasErrors() ? 'YES' : 'NO') . "\n";
    echo "real N/.cache byte-unchanged: " . ($cacheBefore === $cacheAfter ? 'YES' : 'NO') . "\n";
    echo "\n";
    echo "PRIMARY divergence (informational — legacy swagger-php vs graph; M7–M9 backlog):\n";
    echo "  A(legacy)  paths=" . $cA['paths'] . " operations=" . $cA['ops'] . " schemas=" . $cA['schemas'] . "\n";
    echo "  B(graph)   paths=" . $cB['paths'] . " operations=" . $cB['ops'] . " schemas=" . $cB['schemas'] . "\n";

    if (!empty($warningsReport['legacy']) || !empty($warningsReport['metadata'])) {
        echo "\n-- warnings (parity-tolerated; reported separately from the verdict) --\n";
        foreach (['A(legacy)' => $warningsReport['legacy'], 'B(metadata)' => $warningsReport['metadata']] as $tag => $ws) {
            foreach (array_slice($ws, 0, 15) as $w) {
                echo "  [$tag] $w\n";
            }
            if (count($ws) > 15) {
                echo "  [$tag] … (" . (count($ws) - 15) . " more)\n";
            }
        }
    }

    echo "\n==== verdict ====\n";
    $ok = $failures === [];
    if (!$ok) {
        echo "FAILED checks (" . count($failures) . "):\n";
        foreach ($failures as $f) {
            echo "  ✗ $f\n";
        }
    }
    echo "openapi-source switch: A reproduces the legacy producer, B produces the pure graph, route/DI/secondary identical, fingerprints differ: " . ($ok ? 'PASS' : 'FAIL') . "\n";
    echo "N production PRIMARY is the Metadata graph (M7b flip); the controller/DTO #[OA\...] source stays as the working rollback (Legacy) until the OA removal checkpoint.\n";

    $exit = $ok ? 0 : 1;
} catch (\Throwable $e) {
    fwrite(STDERR, "PROBE FAILED (thrown): " . $e->getMessage() . "\n(" . $e->getFile() . ":" . $e->getLine() . ")\n");
    $exit = 1;
} finally {
    $rrm = static function (string $dir) use (&$rrm): void {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $p = $dir . '/' . $entry;
            is_dir($p) && !is_link($p) ? $rrm($p) : @unlink($p);
        }
        @rmdir($dir);
    };
    foreach ($madeDirs as $d) {
        $rrm($d);
    }
    @rmdir($cacheBase);
}

exit($exit);
