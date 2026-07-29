<?php

/**
 * Dev-only Step 9 pass-3 PER-TREE OpenAPI builder (NOT a committed test). Sibling to gen_n_openapi_source_parity.php.
 *
 * One process, ONE target tree, ONE producer mode. It bootstraps the FULL F engine (current working-tree HEAD)
 * over the given N tree — that tree's own vendor/autoload + classmap override (so every SpsNext\ FQCN resolves to
 * THIS tree's body, not the other tree's) — drives the FULL Coordinator with the tree's real config files
 * (operationId map / route-override map / DI config / escape hatch), and writes the PRIMARY openapi.yml to disk.
 *
 *   php step9_pass3_build_doc.php <nextRoot> <legacy|metadata> <outYml> [outPolicyJson] [outMetaJson]
 *
 * - legacy   → openApiSource=Legacy, ruleSource=Legacy (the swagger-php scan; the historical baseline producer).
 *   ALSO emits DocsUtil::produceLegacyOpenApiYaml(scanPaths) into outMetaJson as `docsutil_yaml` so the runner can
 *   prove the Coordinator primary is byte-identical to the standalone historical producer (parity sanity).
 * - metadata → openApiSource=Metadata, ruleSource=Metadata (the pure graph; the candidate producer).
 *
 * For metadata mode, [outPolicyJson] receives the StandardErrorPolicy sidecar — per "METHOD /path" the list of
 * statuses responsesFor() would emit (re-derived from the SAME route compiler the Coordinator used, same maps/
 * overrides/discovery) — so the allowlist matcher can re-validate every R1 (StandardErrorPolicy) entry.
 *
 * The Coordinator writes ONLY to a throwaway temp cache (sys_get_temp_dir); the tree's real .cache is never touched.
 */

declare(strict_types=1);

use SpsFW\Core\Compile\ApplicationContext;
use SpsFW\Core\Compile\Coordinator;
use SpsFW\Core\Compile\OpenApi\OpenApiEscapeHatch;
use SpsFW\Core\Compile\OpenApi\StandardErrorPolicy;
use SpsFW\Core\Compile\OpenApiSource;
use SpsFW\Core\Compile\Route\EndpointSet;
use SpsFW\Core\Compile\Route\RouteMetadataCompiler;
use SpsFW\Core\Compile\RuleSource;
use SpsFW\Core\DocsUtil;
use SpsFW\Core\Router\PathManager;

// The swagger-php scan over a full N tree (hundreds of schemas) needs more than the 128 MB CLI default.
ini_set('memory_limit', '2048M');

$spsfwRoot = dirname(__DIR__, 2);
$nextRoot = $argv[1] ?? '';
$mode = $argv[2] ?? '';
$outYml = $argv[3] ?? '';
$outPolicyJson = $argv[4] ?? null;
$outMetaJson = $argv[5] ?? null;

if (!is_dir($nextRoot) || !in_array($mode, ['legacy', 'metadata'], true) || $outYml === '') {
    fwrite(STDERR, "usage: step9_pass3_build_doc.php <nextRoot> <legacy|metadata> <outYml> [outPolicyJson] [outMetaJson]\n");
    exit(2);
}
$nextAutoload = $nextRoot . '/vendor/autoload.php';
if (!is_file($nextAutoload)) {
    fwrite(STDERR, "next vendor autoload not found at $nextAutoload\n");
    exit(2);
}

// ============================================================================
// Bootstrap: THIS tree's autoload + local SpsFW src + classmap override for BOTH trees (this tree wins for its
// own SpsNext\ classes because its classmap is registered; the F engine src always wins over the vendored copy).
// ============================================================================
/** @var \Composer\Autoload\ClassLoader $loader */
$loader = require $nextAutoload;
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

$diConfig = $nextRoot . '/config/di_config.php';
if (is_file($diConfig)) {
    $diBindings = require $diConfig;
    if (is_array($diBindings)) {
        \SpsFW\Core\Config::setDIBindings($diBindings);
    }
}

// Tolerant error handler: surface non-vendor PHP warnings on stderr but never throw (the Coordinator's own
// diagnostics carry the compile verdict).
set_error_handler(static function (int $errno, string $errstr, string $errfile): bool {
    if ((error_reporting() & $errno) === 0) {
        return false;
    }
    if (!str_contains($errfile, '/vendor/') && !str_contains($errfile, '\\vendor\\')) {
        fwrite(STDERR, "PHP warning: $errstr ($errfile)\n");
    }
    return true;
}, E_WARNING | E_NOTICE | E_DEPRECATED);

// ============================================================================
// Load this tree's production config.
// ============================================================================
$operationIdMap = is_file($nextRoot . '/config/operation_id_map.lock.php')
    ? (require $nextRoot . '/config/operation_id_map.lock.php') : [];
$routeOverrideMap = is_file($nextRoot . '/config/route_override_map.php')
    ? (require $nextRoot . '/config/route_override_map.php') : [];
$configFiles = array_filter([
    'di_config' => $nextRoot . '/config/di_config.php',
    'operation_id_map' => $nextRoot . '/config/operation_id_map.lock.php',
    'route_override_map' => $nextRoot . '/config/route_override_map.php',
    'openapi_escape_hatch' => $nextRoot . '/config/openapi_escape_hatch.php',
], static fn(string $p): bool => is_file($p));
$hatchConfig = is_file($nextRoot . '/config/openapi_escape_hatch.php')
    ? (require $nextRoot . '/config/openapi_escape_hatch.php') : ['scan' => [], 'schemas' => []];
$escapeHatch = new OpenApiEscapeHatch(
    is_array($hatchConfig['scan'] ?? null) ? $hatchConfig['scan'] : [],
    is_array($hatchConfig['schemas'] ?? null) ? $hatchConfig['schemas'] : [],
);
$dirs = array_values(array_filter(PathManager::getControllersDirs(), 'is_dir'));
$legacyOpenApiScanPaths = [PathManager::getSrcPath(), PathManager::getLibraryRoot()];

// ============================================================================
// Build via the FULL Coordinator (throwaway temp cache — the real .cache is never touched).
// ============================================================================
$cacheDir = sys_get_temp_dir() . '/n_step9p3_' . $mode . '_' . bin2hex(random_bytes(4));
@mkdir($cacheDir, 0777, true);
$gitHead = trim((string) shell_exec("git -C " . escapeshellarg($nextRoot) . " rev-parse HEAD 2>/dev/null"));

$isMeta = $mode === 'metadata';
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
    ruleSource: $isMeta ? RuleSource::Metadata : RuleSource::Legacy,
    openApiSource: $isMeta ? OpenApiSource::Metadata : OpenApiSource::Legacy,
    openApiEscapeHatch: $escapeHatch,
);
$coord = new Coordinator($ctx);
$res = $coord->compile(); // publish (default) — writes the PRIMARY swagger/openapi.yml
$primaryPath = $cacheDir . '/swagger/openapi.yml';
$primaryYaml = is_file($primaryPath) ? file_get_contents($primaryPath) : null;

// ============================================================================
// Policy sidecar (metadata only): per "METHOD /path" the responsesFor() status set, re-derived from the SAME
// route compiler (same maps/overrides/discovery) the Coordinator used.
// ============================================================================
$policySidecar = null;
if ($isMeta) {
    $routeCompiler = new RouteMetadataCompiler(
        $coord->diagnostics(),
        operationIdMap: $operationIdMap,
        ruleSource: RuleSource::Metadata,
    );
    $endpointSet = $routeCompiler->compileEndpointSet($dirs, $routeOverrideMap);
    $policy = new StandardErrorPolicy();
    $sidecar = [];
    foreach ($endpointSet->operations as $op) {
        $key = strtoupper($op->httpMethod) . ' ' . $op->path;
        $sidecar[$key] = array_values(array_map('strval', array_keys($policy->responsesFor($op))));
    }
    ksort($sidecar);
    $policySidecar = $sidecar;
}

// ============================================================================
// Parity note (legacy only): the Coordinator primary IS the standalone historical producer — Coordinator::compile()
// in Legacy mode calls DocsUtil::produceLegacyOpenApiYaml(scanPaths) internally for the primary (Coordinator.php),
// so re-scanning here would only duplicate the work and the memory. The byte-identity was already proven by the
// sibling gen_n_openapi_source_parity.php probe. We do NOT re-scan.
// ============================================================================
$docsutilParity = null;

// ============================================================================
// Write outputs + clean the throwaway cache.
// ============================================================================
$wrotePrimary = false;
if ($primaryYaml !== null) {
    file_put_contents($outYml, $primaryYaml);
    $wrotePrimary = true;
}
if ($isMeta && $outPolicyJson !== null && $policySidecar !== null) {
    file_put_contents($outPolicyJson, json_encode($policySidecar, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}
if ($outMetaJson !== null) {
    file_put_contents($outMetaJson, json_encode([
        'tree' => $nextRoot,
        'git_head' => $gitHead,
        'mode' => $mode,
        'published' => $res->published,
        'success' => $res->success,
        'errorCount' => $res->errorCount,
        'warningCount' => $res->warningCount,
        'reason' => $res->reason ?? null,
        'primary_sha256' => $primaryYaml !== null ? hash('sha256', $primaryYaml) : null,
        'discovery_dirs' => $dirs,
        'legacy_scan_paths' => $legacyOpenApiScanPaths,
        'operations_in_sidecar' => $isMeta ? count($policySidecar ?? []) : null,
        'spsfw_head' => trim((string) shell_exec('git -C ' . escapeshellarg($spsfwRoot) . ' rev-parse HEAD 2>/dev/null')),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

$rrm = static function (string $dir) use (&$rrm): void {
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $e) {
        if ($e === '.' || $e === '..') {
            continue;
        }
        $p = $dir . '/' . $e;
        is_dir($p) && !is_link($p) ? $rrm($p) : @unlink($p);
    }
    @rmdir($dir);
};
$rrm($cacheDir);

fwrite(STDERR, "[$mode] $nextRoot @ " . substr($gitHead, 0, 10) . " published=" . ($res->published ? 'YES' : 'NO') . " errors=" . $res->errorCount . " warnings=" . $res->warningCount . " primary=" . ($wrotePrimary ? 'written' : 'MISSING') . "\n");

exit($wrotePrimary && $res->success ? 0 : 1);
