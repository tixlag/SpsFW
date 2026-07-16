<?php

/**
 * Dev-only integration probe (NOT a committed test). Validates the `next/preload.php` managed-compile wiring
 * (Step 6b #4, plan §11.2) by running the SAME Coordinator construction preload uses — but against a THROWAWAY
 * temp cache, so N's real .cache is untouched and NO database is needed (the compile-only DI path reads
 * Config::setDIBindings() directly; it never connects).
 *
 * It mirrors preload.php's ApplicationContext build verbatim (same maps, same configFiles, same mode/policy, same
 * discoveryPaths) — keep the two in sync. What it proves on N's real controller tree:
 *   - the Coordinator PUBLISHES (result->published === true) — the happy path the preload requires;
 *   - all 6 artifacts exist: compiled_routes.php, compiled_di.php, job_registry.php, swagger/openapi.yml (PRIMARY,
 *     legacy parity), swagger/openapi.generated.yml (SECONDARY), .compile_manifest.php;
 *   - the manifest fingerprint + artifact hashes are self-consistent;
 *   - the route cache is a loadable IR; the DI map + job registry are non-empty.
 *
 * Read-only on N's real .cache (publishes to a temp dir under sys_get_temp_dir()). Bootstrapping mirrors
 * gen_n_coordinator_dryrun.php (N's full deps, local SpsFW src prepended over the vendored copy, SpsNext\ registered,
 * SPSFW_PROJECT_ROOT → N) — this is how the symlinked-vendor runtime will behave once the framework is released.
 *
 * Run from the SpsFW repo:  php docs/metadata_compiler_audit/gen_n_preload_integration.php
 */

declare(strict_types=1);

use SpsFW\Core\Compile\ApplicationContext;
use SpsFW\Core\Compile\CompileMode;
use SpsFW\Core\Compile\Coordinator;
use SpsFW\Core\Config;
use SpsFW\Core\Router\PathManager;

$spsfwRoot = dirname(__DIR__, 2);
$nextRoot = dirname($spsfwRoot) . '/lk.sps38.pro/next';

if (!is_dir($nextRoot)) {
    fwrite(STDERR, "consumer repo not found at $nextRoot — nothing to probe\n");
    exit(2);
}
$nextAutoload = $nextRoot . '/vendor/autoload.php';
if (!is_file($nextAutoload)) {
    fwrite(STDERR, "next vendor autoload not found at $nextAutoload\n");
    exit(2);
}

/** @var \Composer\Autoload\ClassLoader $loader */
$loader = require $nextAutoload;
$loader->addPsr4('SpsFW\\', [$spsfwRoot . '/src'], true); // local working tree wins over the vendored copy
$loader->addPsr4('SpsNext\\', $nextRoot . '/src');

// Override the optimized classmap for EVERY local src class (Composer resolves classmap before PSR-4) so the working
// tree is authoritative — same bootstrap as gen_n_coordinator_dryrun.php.
$localClassMap = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($spsfwRoot . '/src', FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (!$f->isFile() || $f->getExtension() !== 'php') {
        continue;
    }
    $src = file_get_contents($f->getPathname());
    $ns = preg_match('/^\s*namespace\s+([^\s;]+)\s*;/m', $src, $m) ? $m[1] : '';
    if (preg_match('/^\s*(?:final\s+|abstract\s+|readonly\s+)*?(?:class|interface|trait|enum)\s+([A-Za-z0-9_]+)/m', $src, $m)) {
        $localClassMap[($ns !== '' ? $ns . '\\' : '') . $m[1]] = $f->getPathname();
    }
}
$loader->addClassMap($localClassMap);

putenv('SPSFW_PROJECT_ROOT=' . $nextRoot);
$_ENV['SPSFW_PROJECT_ROOT'] = $nextRoot;

// Seed DI bindings exactly as preload does (preload.php: Config::setDIBindings(require config/di_config.php)).
$diConfig = $nextRoot . '/config/di_config.php';
if (is_file($diConfig)) {
    $diBindings = require $diConfig;
    if (is_array($diBindings)) {
        Config::setDIBindings($diBindings);
    }
}

// ============================================================================
// Mirror preload.php's ApplicationContext construction EXACTLY — but publish to a temp cache (read-only on N's .cache).
// ============================================================================
$cachePath = sys_get_temp_dir() . '/spsfw_preload_' . bin2hex(random_bytes(8));
mkdir($cachePath, 0777, true);

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

$ctx = new ApplicationContext(
    projectRoot: $nextRoot,
    cachePath: $cachePath,
    discoveryPaths: PathManager::getControllersDirs(),
    configInputs: [
        'openapi_title' => 'next',
        'openapi_version' => '0.1.0',
    ],
    mode: CompileMode::Managed, // the managed preload target; runtime guards keep managed requests off the lazy path
    diagnosticPolicy: ApplicationContext::POLICY_PARITY,
    operationIdMap: $operationIdMap,
    routeOverrideMap: $routeOverrideMap,
    configFiles: $configFiles,
    lockTimeoutSec: 30.0,
);

echo "==== N preload integration (temp cache: $cachePath) ====\n";
$result = (new Coordinator($ctx))->compile();

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

// ---- Assertions: the happy-path contract preload.php relies on (result->published === true + full artifact set).
$checks = [];
$checks['success'] = $result->success;
$checks['published'] = $result->published;
$checks['not_dry_run'] = !$result->dryRun;
$checks['zero_errors'] = $result->errorCount === 0;

$artifacts = [
    'compiled_routes.php',
    'compiled_di.php',
    'job_registry.php',
    'swagger/openapi.yml',
    'swagger/openapi.generated.yml',
    '.compile_manifest.php',
];
foreach ($artifacts as $rel) {
    $checks["exists:$rel"] = is_file($cachePath . '/' . $rel);
}

// Manifest fingerprint + artifact hashes self-consistent.
$manifest = is_file($cachePath . '/.compile_manifest.php') ? (require $cachePath . '/.compile_manifest.php') : [];
$checks['manifest_fingerprint_matches'] = is_array($manifest) && ($manifest['fingerprint'] ?? null) === $result->fingerprint;
foreach (['compiled_routes.php', 'compiled_di.php', 'job_registry.php', 'swagger/openapi.yml', 'swagger/openapi.generated.yml'] as $rel) {
    if (is_file($cachePath . '/' . $rel)) {
        $checks["hash:$rel"] = ($manifest['artifact_hashes'][$rel] ?? null) === md5_file($cachePath . '/' . $rel);
    }
}

// Route cache loadable; DI map + job registry non-empty.
$routes = is_file($cachePath . '/compiled_routes.php') ? (require $cachePath . '/compiled_routes.php') : [];
$diMap = is_file($cachePath . '/compiled_di.php') ? (require $cachePath . '/compiled_di.php') : [];
$jobs = is_file($cachePath . '/job_registry.php') ? (require $cachePath . '/job_registry.php') : [];
$checks['routes_is_array'] = is_array($routes);
$checks['routes_non_empty'] = is_array($routes) && count($routes) > 0;
$checks['di_map_non_empty'] = is_array($diMap) && count($diMap) > 0;
$checks['overrides_6'] = count($result->overrides) === 6;

echo sprintf(
    "published=%s reason=%s errors=%d warnings=%d fingerprint=%s overrides=%d\n",
    $result->published ? 'true' : 'false',
    $result->reason ?? 'null',
    $result->errorCount,
    $result->warningCount,
    $result->fingerprint ?? 'null',
    count($result->overrides),
);
echo sprintf("routes=%d di_classes=%d jobs=%d\n", is_array($routes) ? count($routes) : 0, is_array($diMap) ? count($diMap) : 0, is_array($jobs) ? count($jobs) : 0);

echo "\n-- checks --\n";
$ok = true;
foreach ($checks as $name => $pass) {
    echo sprintf("  [%s] %s\n", $pass ? 'OK' : 'FAIL', $name);
    $ok = $ok && (bool) $pass;
}

// PRIMARY openapi.yml (parity) must be byte-identical to what the legacy producer writes — prove the Coordinator
// keeps the spec Orval reads fresh (Step 6b #5). Compare against DocsUtil::produceLegacyOpenApiYaml on the same scan.
$primaryYaml = is_file($cachePath . '/swagger/openapi.yml') ? file_get_contents($cachePath . '/swagger/openapi.yml') : '';
$legacyYaml = \SpsFW\Core\DocsUtil::produceLegacyOpenApiYaml(PathManager::getControllersDirs());
$parity = $primaryYaml === $legacyYaml;
echo sprintf("  [%s] primary openapi.yml == legacy producer (parity, byte-identical)\n", $parity ? 'OK' : 'FAIL');
$ok = $ok && $parity;

$rrm($cachePath);
echo "\nverdict: " . ($ok ? 'PASS' : 'FAIL') . "\n";
exit($ok ? 0 : 1);
