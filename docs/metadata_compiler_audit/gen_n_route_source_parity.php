<?php

/**
 * Dev-only audit probe (NOT a committed test). Step 7 (M5) N-side FULL-COORDINATOR parity — the rule-source
 * PRODUCER SWITCH, proven on REAL production controllers through the WHOLE engine (not just the route compiler).
 *
 * SpsFW clean-checkout tests MUST NOT depend on the consumer repo (N). This script is run by hand against the
 * live consumer app to prove, on REAL production controllers, that switching the route-cache rule-graph producer
 * from the legacy OA source (Router::extractValidationRules) to the metadata source (DtoSchemaBuilder) is
 * observably a no-op on N's step6b tree — exactly the precondition for flipping it on (plan §15 M5, Step 7).
 *
 * It drives the FULL Coordinator TWICE over N's real discovery tree — once with ruleSource=Legacy, once with
 * ruleSource=Metadata — into TWO throwaway temp cache dirs (real N/.cache is never touched, proven by a
 * before/after snapshot). Both runs use IDENTICAL inputs: discovery paths, operationId map, route-override map,
 * DI bindings, legacy OpenAPI scan paths, and the parity diagnostic policy. Only ruleSource differs. Then it
 * asserts the WHOLE published artifact set is equivalent between modes:
 *
 *   MANDATORY: legacy errors = 0 AND metadata errors = 0 (a clean step6b tree has the EmployeeDocuments +
 *     CreateNewsDto fixes, so both modes are error-free; a non-zero ERROR in EITHER mode is a real defect, not
 *     a parity matter). The probe is NOT successful when both modes have the same non-zero ERROR count.
 *   - both runs PUBLISH (published=true, success=true) the full 6-file artifact set;
 *   - the route KEY SETS (METHOD:path) are identical (discovery + overrides are rule-source-independent);
 *   - compiled_routes.php is BYTE-IDENTICAL between modes (especially dtos[].rules — the only rule-source-
 *     dependent field) under strict file-content ===;
 *   - the manifest records config_inputs.rule_source = 'legacy' vs 'metadata' respectively;
 *   - the fingerprints DIFFER on a source switch (rule_source participates in the fingerprint ⇒ cache
 *     invalidation when the producer flips, even though the artifacts stay byte-identical under parity);
 *   - the PRIMARY swagger/openapi.yml (legacy swagger-php parity — what Orval reads) is identical between modes;
 *   - DI/job artifacts (compiled_di.php, job_registry.php) are identical between modes (rule-source-independent).
 *
 * WARNINGS are tolerated under POLICY_PARITY (they block only under STRICT) and are reported SEPARATELY from the
 * verdict — they never flip PASS to FAIL unless they block publication. Any unsuppressed PHP warning/notice/
 * deprecation during the compile or the comparisons FAILS the probe (set_error_handler → ErrorException): the
 * previous version of this probe read `$route->httpMethod->value` on a STRING (httpMethod is a string, not the
 * HttpMethod enum, to keep the emitted cache identical) — a warning that collapsed every route key to `:<path>`
 * and made the comparison spuriously pass; the handler makes such a regression fatal.
 *
 * READ-ONLY w.r.t. N: it publishes only to throwaway temp dirs and writes nothing to real N/.cache (proven by the
 * before/after snapshot). Temp dirs are removed in a `finally`.
 *
 * TARGETING A SPECIFIC N TREE: set SPSFW_N_NEXT_ROOT to the `next/` root of the tree to probe. The default is the
 * sibling checkout; to probe the step6b worktree run with, e.g.
 *   SPSFW_N_NEXT_ROOT=/home/tixlag/PhpstormProjects/.wt/lk-step6b/next php docs/metadata_compiler_audit/gen_n_route_source_parity.php
 *
 * Bootstrapping mirrors gen_n_coordinator_dryrun.php: load N's full dependency set, PREPEND the local SpsFW
 * working-tree src over the vendored copy, OVERRIDE the OPTIMIZED composer classmap for BOTH SpsFW\ and SpsNext\
 * (Composer resolves the classmap BEFORE PSR-4, and N ships a classmap pointing every class at the vendored / the
 * main-checkout path — so when probing a worktree, the classmap must be rebuilt from the worktree's src to avoid
 * loading the wrong file), register next/src for SpsNext\, seed DI bindings, and point SPSFW_PROJECT_ROOT at N so
 * PathManager resolves the SAME controller discovery dirs + legacy OpenAPI scan set N's preload uses.
 */

declare(strict_types=1);

use SpsFW\Core\Compile\ApplicationContext;
use SpsFW\Core\Compile\Coordinator;
use SpsFW\Core\Compile\RuleSource;
use SpsFW\Core\Router\PathManager;

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
// Override the optimized classmap for BOTH trees so the PROBED src is authoritative (last write wins). Without this,
// N's classmap would load SpsNext\ classes from the main-checkout (dispatcher branch) instead of the worktree.
$loader->addClassMap($scanClassMap($spsfwRoot . '/src'));
$loader->addClassMap($scanClassMap($nextRoot . '/src'));

putenv('SPSFW_PROJECT_ROOT=' . $nextRoot);
$_ENV['SPSFW_PROJECT_ROOT'] = $nextRoot;

// Seed DI bindings exactly as N's preload does — so the compile-only DI stage behaves as in production.
// Config::getDIBindings() reads the static map directly and does NOT require the DB-side Config::init().
$diConfig = $nextRoot . '/config/di_config.php';
if (is_file($diConfig)) {
    $diBindings = require $diConfig;
    if (is_array($diBindings)) {
        \SpsFW\Core\Config::setDIBindings($diBindings);
    }
}

// ============================================================================
// 2. An unsuppressed PHP warning/notice/deprecation that originates from THIS probe, the SpsFW working-tree src, or
//    the probed SpsNext src is a probe FAILURE — this is what catches a regression like the old `$route->httpMethod
//    ->value` (reading a property on a string → E_WARNING, raised from the probe file). The handler scopes the FAIL
//    rule by SOURCE FILE: errors raised from inside a Composer `vendor/` dir are SWALLOWED, because they are
//    library-internal and pre-existing in N — most notably swagger-php's `DefaultLogger::log()` raises `trigger_error
//    (…, E_USER_WARNING)` for "Multiple @OA\Response" / DTO-collision conditions that exist in N today. Those same
//    warnings fire in the PRODUCTION Coordinator path too (DocsUtil's suppressing logger overrides only `warning()`,
//    not `log()`), where they print to stderr and do NOT block publication (POLICY_PARITY tolerates them). Mirroring
//    that, the probe tolerates them rather than failing on a pre-existing N condition unrelated to the M5 switch.
//    (error_reporting() honors @-suppression; the handler never fires for silenced calls.)
// ============================================================================
set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
    if ((error_reporting() & $errno) === 0) {
        return false; // @-suppressed
    }
    if (str_contains($errfile, '/vendor/') || str_contains($errfile, '\\vendor\\')) {
        return true; // library-internal (swagger-php logger etc.) — tolerated as in production
    }
    throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
}, E_WARNING | E_NOTICE | E_DEPRECATED | E_USER_WARNING | E_USER_NOTICE | E_USER_DEPRECATED);

// ============================================================================
// 3. Load production config from real N files — NO hardcoded duplication of the route-override / operationId maps.
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
], static fn(string $p): bool => is_file($p));

$dirs = array_values(array_filter(PathManager::getControllersDirs(), 'is_dir'));
$legacyOpenApiScanPaths = [PathManager::getSrcPath(), PathManager::getLibraryRoot()]; // the historical [src, libraryRoot] parity order

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
        // Skip transient compile-lifecycle artifacts (never part of a published set).
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
// 5. Build the FULL Coordinator in BOTH rule-source modes, into throwaway temp caches. Only ruleSource differs.
// ============================================================================
$cacheBase = sys_get_temp_dir() . '/n_rule_parity_' . bin2hex(random_bytes(4));
$madeDirs = [];

$buildRun = static function (RuleSource $ruleSource) use (
    $nextRoot, $dirs, $operationIdMap, $routeOverrideMap, $configFiles, $legacyOpenApiScanPaths, $cacheBase, &$madeDirs
): array {
    $tag = $ruleSource->isLegacy() ? 'legacy' : 'metadata';
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
        ruleSource: $ruleSource,
    );
    $coord = new Coordinator($ctx);
    return [$coord->compile(), $coord, $cacheDir];
};

$failures = []; // accumulated check failures (string messages)
$warningsReport = ['legacy' => [], 'metadata' => []];

try {
    [$resL, $coordL, $cacheL] = $buildRun(RuleSource::Legacy);
    [$resM, $coordM, $cacheM] = $buildRun(RuleSource::Metadata);

    // Capture warning records (reported separately; never flip the verdict under POLICY_PARITY).
    $warningsReport['legacy'] = array_map(static fn(array $w): string => ($w['controller'] ?? '?') . ($w['method'] ? '::' . $w['method'] : '') . ' — ' . $w['cause'], $coordL->diagnostics()->warnings());
    $warningsReport['metadata'] = array_map(static fn(array $w): string => ($w['controller'] ?? '?') . ($w['method'] ? '::' . $w['method'] : '') . ' — ' . $w['cause'], $coordM->diagnostics()->warnings());

    // ---- helper: assert + record on failure (do not short-circuit; collect all check results) ----
    $check = static function (bool $ok, string $label, string $detail = '') use (&$failures): void {
        if (!$ok) {
            $failures[] = $label . ($detail !== '' ? ' :: ' . $detail : '');
        }
    };

    // ---- published + mandatory 0 errors (the headline M5 precondition on a clean step6b tree) ----
    $check($resL->success && $resL->published, 'Legacy run published', "success=" . ($resL->success ? 'true' : 'false') . " published=" . ($resL->published ? 'true' : 'false') . " reason=" . ($resL->reason ?? 'null'));
    $check($resM->success && $resM->published, 'Metadata run published', "success=" . ($resM->success ? 'true' : 'false') . " published=" . ($resM->published ? 'true' : 'false') . " reason=" . ($resM->reason ?? 'null'));
    $check($resL->errorCount === 0, 'Legacy run: 0 errors (MANDATORY)', 'errorCount=' . $resL->errorCount);
    $check($resM->errorCount === 0, 'Metadata run: 0 errors (MANDATORY)', 'errorCount=' . $resM->errorCount);

    // ---- the full 6-file artifact set exists in each throwaway cache ----
    $artifacts = ['compiled_routes.php', 'compiled_di.php', 'job_registry.php', 'swagger/openapi.yml', 'swagger/openapi.generated.yml', '.compile_manifest.php'];
    foreach (['legacy' => $cacheL, 'metadata' => $cacheM] as $tag => $cacheDir) {
        foreach ($artifacts as $rel) {
            $check(is_file($cacheDir . '/' . $rel), ucfirst($tag) . ' cache has artifact ' . $rel);
        }
    }

    // ---- route KEY SETS identical (each compiled_routes.php is required exactly ONCE) ----
    $routesL = require $cacheL . '/compiled_routes.php';
    $routesM = require $cacheM . '/compiled_routes.php';
    $keysL = array_keys($routesL);
    $keysM = array_keys($routesM);
    $check($keysL === $keysM, 'Route key sets identical', 'legacy=' . count($keysL) . ' metadata=' . count($keysM)
        . ($keysL !== $keysM ? ' only-legacy=[' . implode(',', array_slice(array_values(array_diff($keysL, $keysM)), 0, 8)) . '] only-metadata=[' . implode(',', array_slice(array_values(array_diff($keysM, $keysL)), 0, 8)) . ']' : ''));

    // ---- compiled_routes.php BYTE-IDENTICAL (file content ===; covers dtos[].rules) AND structurally === ----
    $routesBytesL = file_get_contents($cacheL . '/compiled_routes.php');
    $routesBytesM = file_get_contents($cacheM . '/compiled_routes.php');
    $check($routesBytesL === $routesBytesM, 'compiled_routes.php byte-identical (dtos[].rules)', 'len legacy=' . strlen($routesBytesL) . ' metadata=' . strlen($routesBytesM));
    $check($routesL === $routesM, 'compiled_routes.php structurally === (strict array parity)');

    // ---- manifest rule_source + fingerprint ----
    $manifestL = require $cacheL . '/.compile_manifest.php';
    $manifestM = require $cacheM . '/.compile_manifest.php';
    $check(($manifestL['config_inputs']['rule_source'] ?? null) === 'legacy', 'Legacy manifest rule_source=legacy', 'got ' . var_export($manifestL['config_inputs']['rule_source'] ?? null, true));
    $check(($manifestM['config_inputs']['rule_source'] ?? null) === 'metadata', 'Metadata manifest rule_source=metadata', 'got ' . var_export($manifestM['config_inputs']['rule_source'] ?? null, true));
    $check(($manifestL['fingerprint'] ?? null) !== ($manifestM['fingerprint'] ?? null), 'Fingerprints differ on source switch (rule_source invalidates cache)');

    // ---- PRIMARY swagger/openapi.yml identical (legacy swagger-php parity — rule-source-independent) ----
    $oaL = file_get_contents($cacheL . '/swagger/openapi.yml');
    $oaM = file_get_contents($cacheM . '/swagger/openapi.yml');
    $check($oaL === $oaM, 'PRIMARY swagger/openapi.yml identical between modes');

    // ---- DI/job artifacts identical (rule-source-independent) ----
    $check(file_get_contents($cacheL . '/compiled_di.php') === file_get_contents($cacheM . '/compiled_di.php'), 'compiled_di.php identical between modes');
    $check(file_get_contents($cacheL . '/job_registry.php') === file_get_contents($cacheM . '/job_registry.php'), 'job_registry.php identical between modes');

    // ---- real N/.cache BYTE-UNCHANGED (the probe is read-only w.r.t. the live cache) ----
    $cacheAfter = $snapshotCache($nextRoot . '/.cache');
    $check($cacheBefore === $cacheAfter, 'Real N/.cache byte-unchanged before/after', 'files before=' . count($cacheBefore) . ' after=' . count($cacheAfter));

    // ---- SECONDARY openapi.generated.yml (informational only — the emitter is M3/M6, not part of the M5 parity contract) ----
    $genIdentical = file_get_contents($cacheL . '/swagger/openapi.generated.yml') === file_get_contents($cacheM . '/swagger/openapi.generated.yml');

    // ============================================================================
    // 6. Report.
    // ============================================================================
    echo "==== N route-source parity — FULL Coordinator (Step 7 / M5) ====\n";
    echo "next root: $nextRoot\n";
    echo "discovery dirs: " . implode(', ', $dirs) . "\n";
    echo "legacy OpenAPI scan: " . implode(', ', $legacyOpenApiScanPaths) . "\n";
    echo "route overrides: " . count($routeOverrideMap) . " | operationId map: " . count($operationIdMap) . " | config files: " . implode(',', array_keys($configFiles)) . "\n";
    echo "\n";
    echo "routes: legacy=" . count($keysL) . " metadata=" . count($keysM) . "\n";
    echo "published: legacy=" . ($resL->published ? 'YES' : 'NO') . " metadata=" . ($resM->published ? 'YES' : 'NO') . "\n";
    echo "errors:   legacy=" . $resL->errorCount . " metadata=" . $resM->errorCount . "  (MANDATORY: both 0)\n";
    echo "warnings: legacy=" . $resL->warningCount . " metadata=" . $resM->warningCount . "  (tolerated under POLICY_PARITY; reported separately)\n";
    echo "fingerprints differ: legacy=" . substr((string)$manifestL['fingerprint'], 0, 12) . " metadata=" . substr((string)$manifestM['fingerprint'], 0, 12) . "\n";
    echo "manifest rule_source: legacy=" . ($manifestL['config_inputs']['rule_source'] ?? '?') . " metadata=" . ($manifestM['config_inputs']['rule_source'] ?? '?') . "\n";
    echo "compiled_routes.php byte-identical: " . ($routesBytesL === $routesBytesM ? 'YES' : 'NO') . "\n";
    echo "PRIMARY swagger/openapi.yml identical: " . ($oaL === $oaM ? 'YES' : 'NO') . "\n";
    echo "compiled_di.php identical: " . (file_get_contents($cacheL . '/compiled_di.php') === file_get_contents($cacheM . '/compiled_di.php') ? 'YES' : 'NO') . "\n";
    echo "job_registry.php identical: " . (file_get_contents($cacheL . '/job_registry.php') === file_get_contents($cacheM . '/job_registry.php') ? 'YES' : 'NO') . "\n";
    echo "SECONDARY swagger/openapi.generated.yml identical: " . ($genIdentical ? 'YES' : 'NO') . "  (informational; not part of the M5 parity contract)\n";
    echo "real N/.cache byte-unchanged: " . ($cacheBefore === $cacheAfter ? 'YES' : 'NO') . "\n";

    if (!empty($warningsReport['legacy']) || !empty($warningsReport['metadata'])) {
        echo "\n-- warnings (parity-tolerated; reported separately from the verdict) --\n";
        foreach (['legacy' => $warningsReport['legacy'], 'metadata' => $warningsReport['metadata']] as $tag => $ws) {
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
    echo "rule-source switch is observably a no-op on this N tree (0 errors both, byte-identical route cache, fingerprints differ): " . ($ok ? 'PASS' : 'FAIL') . "\n";

    $exit = $ok ? 0 : 1;
} catch (\Throwable $e) {
    fwrite(STDERR, "PROBE FAILED (thrown): " . $e->getMessage() . "\n(" . $e->getFile() . ":" . $e->getLine() . ")\n");
    $exit = 1;
} finally {
    // Remove the throwaway temp caches (real N/.cache is untouched).
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
