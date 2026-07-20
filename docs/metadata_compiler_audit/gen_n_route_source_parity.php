<?php

/**
 * Dev-only audit probe (NOT a committed test). Step 7 (M5) N-side parity — the rule-source PRODUCER SWITCH.
 *
 * SpsFW clean-checkout tests MUST NOT depend on the consumer repo (N). This script is run by hand against the
 * live consumer app to prove, on REAL production controllers, that switching the route-cache rule-graph producer
 * from the legacy OA source (Router::extractValidationRules) to the metadata source (DtoSchemaBuilder::ruleGraph)
 * leaves the EMITTED route cache byte-identical — i.e. the M5 switch is observably a no-op on N's current tree,
 * which is exactly the precondition for flipping it on (plan §15 M5, Step 7 req 3/7).
 *
 * It drives RouteMetadataCompiler twice over N's real discovery tree — once with ruleSource=Legacy, once with
 * ruleSource=Metadata — diffing:
 *   - the METHOD:path route KEY SETS (discovery + overrides are rule-source-INDEPENDENT ⇒ must be identical);
 *   - the FULL emitted compiled_routes source (RouteCacheEmitter::emitSource) under strict === — the whole route
 *     IR, especially the dtos/rules, which is the only rule-source-dependent field;
 *   - the Metadata-mode error count: a non-zero DIFFERENCE vs Legacy would be a real parity violation (a DTO
 *     where DtoSchemaBuilder diverges from the OA source). Mode-INDEPENDENT errors (e.g. a DTO class N has since
 *     deleted from src while the cache still references it) appear identically in BOTH modes and do not affect
 *     the verdict — they are an N cache-freshness matter, not an M5 matter.
 *
 * The per-DTO parity (gen_dto_rulegraph_parity.php, 0 divergences over all loadable N DTOs) is the unit-level
 * proof; THIS probe is the assembled-artifact proof: the published compiled_routes.php would not change.
 *
 * READ-ONLY: it never publishes and writes nothing to N's .cache (RouteMetadataCompiler builds the IR in memory).
 *
 * Bootstrapping mirrors gen_n_coordinator_dryrun.php: load N's full dependency set, PREPEND the local SpsFW
 * working-tree src over the vendored copy (incl. the optimized-classmap override so DICacheBuilder/Router/etc.
 * load from the working tree, not the stale vendored snapshot), register next/src for SpsNext\, seed DI bindings,
 * and point SPSFW_PROJECT_ROOT at N so PathManager resolves the SAME controller discovery dirs N's Router scans.
 */

declare(strict_types=1);

use SpsFW\Core\Compile\CompileDiagnostics;
use SpsFW\Core\Compile\Route\RouteCacheEmitter;
use SpsFW\Core\Compile\Route\RouteMetadataCompiler;
use SpsFW\Core\Compile\RuleSource;
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

// N ships an OPTIMIZED composer classmap pointing every pre-existing SpsFW class at the VENDORED path; Composer
// resolves the classmap BEFORE PSR-4, so addPsr4(prepend) alone is not enough. Override the classmap for EVERY
// local src class so the working tree is authoritative (last write wins in ClassLoader::addClassMap).
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

// Seed DI bindings exactly as N's preload does — so the compile-only stages behave as in production. Config::getDIBinding()
// reads the static map directly and does NOT require the DB-side Config::init(), so no DB connection is made.
$diConfig = $nextRoot . '/config/di_config.php';
if (is_file($diConfig)) {
    $diBindings = require $diConfig;
    if (is_array($diBindings)) {
        \SpsFW\Core\Config::setDIBindings($diBindings);
    }
}

$dirs = array_values(array_filter(PathManager::getControllersDirs(), 'is_dir'));

// The 6 Core↔Next auth duplicates are INTENTIONAL overrides (Next shadows the framework auth templates). Declared
// here so duplicate keys resolve cleanly INDEPENDENTLY of discovery order — matching production. operationIdMap is
// OpenAPI-only and does not affect the route IR, so it is intentionally omitted.
$routeOverrideMap = [
    'POST:/api/auth/login'             => 'SpsNext\\Auth\\AuthController::login',
    'POST:/api/auth/register'          => 'SpsNext\\Auth\\AuthController::register',
    'POST:/api/auth/logout'            => 'SpsNext\\Auth\\AuthController::logout',
    'POST:/api/auth/refresh-tokens'    => 'SpsNext\\Auth\\AuthController::refreshTokens',
    'PATCH:/api/auth/add-access-rules' => 'SpsNext\\Auth\\AuthController::addAccessRules',
    'POST:/api/auth/set-access-rules'  => 'SpsNext\\Auth\\AuthController::setAccessRules',
];

// ============================================================================
// Build the route IR in BOTH rule-source modes over the SAME discovery tree + overrides. Only ruleSource differs.
// ============================================================================
$diagL = new CompileDiagnostics();
$routesL = (new RouteMetadataCompiler($diagL, ruleSource: RuleSource::Legacy))->compileEndpointSet($dirs, $routeOverrideMap)->routes;

$diagM = new CompileDiagnostics();
$routesM = (new RouteMetadataCompiler($diagM, ruleSource: RuleSource::Metadata))->compileEndpointSet($dirs, $routeOverrideMap)->routes;

$keysL = [];
foreach ($routesL as $r) {
    $keysL[$r->httpMethod->value . ':' . $r->rawPath] = ($r->controller . '::' . $r->method);
}
$keysM = [];
foreach ($routesM as $r) {
    $keysM[$r->httpMethod->value . ':' . $r->rawPath] = ($r->controller . '::' . $r->method);
}
$onlyL = array_diff_key($keysL, $keysM);
$onlyM = array_diff_key($keysM, $keysL);

// The emitted compiled_routes.php source — the actual published artifact — under strict byte identity.
$srcL = (new RouteCacheEmitter())->emitSource($routesL);
$srcM = (new RouteCacheEmitter())->emitSource($routesM);
$sourceIdentical = $srcL === $srcM;

// ============================================================================
// Report.
// ============================================================================
echo "==== N route-source parity (Step 7 / M5) ====\n";
echo "discovery dirs: " . implode(', ', $dirs) . "\n";
echo "routes: legacy=" . count($routesL) . " metadata=" . count($routesM) . "\n";
echo "route key sets identical: " . (count($onlyL) === 0 && count($onlyM) === 0 ? 'YES' : 'NO') . "\n";
if ($onlyL) {
    echo "  only-in-legacy (" . count($onlyL) . "): " . implode(', ', array_slice(array_keys($onlyL), 0, 10)) . "\n";
}
if ($onlyM) {
    echo "  only-in-metadata (" . count($onlyM) . "): " . implode(', ', array_slice(array_keys($onlyM), 0, 10)) . "\n";
}
echo "emitted compiled_routes source byte-identical: " . ($sourceIdentical ? 'YES' : 'NO') . "\n";
echo "error records: legacy=" . $diagL->errorCount() . " metadata=" . $diagM->errorCount() . "\n";

// A Metadata error count HIGHER than Legacy would be a genuine parity violation (a DTO where the two producers
// disagree). Mode-independent errors (e.g. a deleted DTO still referenced by a route) land in BOTH buckets equally
// and are an N cache-freshness concern, not an M5 regression — surface them for transparency, not as a failure.
$parityViolations = $diagM->errorCount() - $diagL->errorCount();
echo "metadata-vs-legacy parity violations: " . max(0, $parityViolations) . "\n";

if ($diagM->errorCount() > 0) {
    echo "\n-- metadata-mode error records (for transparency — N-structural, mode-independent unless noted) --\n";
    foreach (array_slice($diagM->errors(), 0, 20) as $e) {
        echo "  • " . ($e['controller'] ?? '?') . ($e['method'] ? '::' . $e['method'] : '') . " — " . $e['cause'] . "\n";
    }
}

// ============================================================================
// Verdict (Step 7 req 7). The producer switch is observably a no-op on N's current tree:
//   - identical route KEY SETS (discovery + overrides are rule-source-independent);
//   - byte-identical EMITTED route source (the whole IR, especially dtos/rules);
//   - NO Metadata-only parity violations (the metadata source agrees with the OA source everywhere it runs).
// A failure here means a real DTO where DtoSchemaBuilder diverges from extractValidationRules — fix BEFORE flip.
// ============================================================================
echo "\n==== verdict ====\n";
$ok = count($onlyL) === 0
    && count($onlyM) === 0
    && $sourceIdentical
    && $parityViolations <= 0;
echo "route keys identical & emitted source byte-identical & 0 metadata-only parity violations: " . ($ok ? 'PASS' : 'FAIL') . "\n";

exit($ok ? 0 : 1);
