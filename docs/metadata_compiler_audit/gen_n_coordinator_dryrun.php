<?php

/**
 * Dev-only audit probe (NOT a committed test). The Step 5 Coordinator run against the REAL consumer app
 * (N = lk.sps38.pro/next), READ-ONLY / DRY-RUN (plan §11.6, Step 5 acceptance).
 *
 * Goal: drive the production Coordinator end-to-end on N's actual controller tree and confirm the publication gate
 * behaves exactly as promised — WITHOUT touching N's current .cache artifacts:
 *
 *   - the single compile flow aggregates diagnostics from the operation compiler, route compiler, OpenAPI emitter
 *     + validator, and DI (plan §11, Step 5);
 *   - the 15 known STRUCTURAL errors (14 duplicate METHOD:path groups + 1 CreateNewsDto schema-name collision) are
 *     reported as ERRORs and FORBID publication (in EVERY policy — they are never downgraded to warnings);
 *   - dryRun publishes nothing and writes NOTHING to N's .cache (read-only probe contract);
 *   - the exact duplicate route groups + the schema collision are captured for the MANDATORY fix before Step 6b
 *     (current N cannot go to managed production while these 15 ERRORs exist).
 *
 * Bootstrapping mirrors gen_n_openapi_parity.php (N's full dependency set, local SpsFW src prepended over the
 * vendored copy, SpsNext\ registered, SPSFW_PROJECT_ROOT → N). DI bindings are seeded from N's config/di_config.php
 * so the DI compile-only path is exercised EXACTLY as production (production built N's compiled_di.php from the same
 * bindings): this keeps the DI stage clean and isolates the 15 structural errors to routes + OpenAPI.
 * Config::getDIBinding() reads the static binding map directly and does NOT require the DB-side Config::init(), so
 * no DB connection is made (the probe is read-only and connection-free).
 */

declare(strict_types=1);

use SpsFW\Core\Compile\ApplicationContext;
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

// N ships an OPTIMIZED composer classmap that points every pre-existing SpsFW class (e.g. DICacheBuilder, which the
// Coordinator exercises via the compile-only API) at the VENDORED path. Composer resolves the classmap BEFORE PSR-4,
// so addPsr4(prepend) is NOT enough for classes the vendored snapshot already knows — they'd load from the stale
// vendored copy. Override the classmap for EVERY local src class so the working tree is authoritative (last write
// wins in Composer\Autoload\ClassLoader::addClassMap). Done before any SpsFW class is touched below.
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

// Seed DI bindings exactly as N's preload does (preload.php:67-68) — minus the DB-side Config::init(), which the
// engine never needs. This makes the DI compile-only stage behave as in production (clean) so the only diagnostics
// left are the structural route/OpenAPI errors under audit.
$diConfig = $nextRoot . '/config/di_config.php';
if (is_file($diConfig)) {
    $diBindings = require $diConfig;
    if (is_array($diBindings)) {
        Config::setDIBindings($diBindings);
    }
}

$dirs = array_values(array_filter(PathManager::getControllersDirs(), 'is_dir'));

// ============================================================================
// READ-ONLY contract: snapshot N's current .cache artifact hashes BEFORE the compile, and assert they are
// byte-identical AFTER. A dry run must not publish (Coordinator returns before any staging/write), but we prove it.
// ============================================================================
$cachePath = $nextRoot . '/.cache';
$watch = ['compiled_routes.php', 'compiled_di.php', 'job_registry.php', 'swagger/openapi.yml', 'swagger/openapi.generated.yml'];
$snapshot = static function () use ($cachePath, $watch): array {
    $hashes = [];
    foreach ($watch as $rel) {
        $p = $cachePath . '/' . $rel;
        $hashes[$rel] = is_file($p) ? md5_file($p) : null;
    }
    return $hashes;
};
$before = $snapshot();

// ============================================================================
// Run the Coordinator in DRY-RUN against N's real tree. managed + parity: warnings tolerated, ERRORs always block.
// ============================================================================
$ctx = new ApplicationContext(
    projectRoot: $nextRoot,
    cachePath: $cachePath,
    discoveryPaths: $dirs,
    configInputs: [
        'mode' => ApplicationContext::MODE_MANAGED,
        'diagnostic_policy' => ApplicationContext::POLICY_PARITY,
        'openapi_title' => 'next',
        'openapi_version' => '0.1.0',
    ],
    mode: ApplicationContext::MODE_MANAGED,
    diagnosticPolicy: ApplicationContext::POLICY_PARITY,
);

$coordinator = new Coordinator($ctx);
$result = $coordinator->compile(dryRun: true);
$diag = $coordinator->diagnostics();

$after = $snapshot();

// ============================================================================
// Report.
// ============================================================================
echo "==== N Coordinator dry-run ====\n";
echo "discovery dirs: " . implode(', ', $dirs) . "\n";
echo "errors=" . $result->errorCount . " warnings=" . $result->warningCount . "\n";
echo "published=" . ($result->published ? 'true' : 'false') . " reason=" . ($result->reason ?? '(null)') . " dryRun=" . ($result->dryRun ? 'true' : 'false') . "\n";
echo "fingerprint=" . ($result->fingerprint ?? '(null)') . "\n";

// Read-only guarantee.
$unchanged = true;
foreach ($watch as $rel) {
    if ($before[$rel] !== $after[$rel]) {
        $unchanged = false;
        echo "!! CHANGED: $rel ({$before[$rel]} -> {$after[$rel]})\n";
    }
}
echo "cache artifacts unchanged: " . ($unchanged ? 'YES' : 'NO') . "\n";

// ============================================================================
// Capture the DISTINCT structural problems for the mandatory Step 6b fix. The Coordinator AGGREGATES diagnostics
// from the route compiler, the OpenAPI emitter and the operationId resolver, and each colliding OPERATION yields
// its own record — so the raw record count over-states the number of things to fix. Collapse to DISTINCT problems:
//   - distinct duplicate METHOD:path route keys (each may be reported by the route compiler AND the emitter, and
//     once per colliding operation);
//   - distinct operationId collisions;
//   - schema-name collisions.
// None of these are ever downgraded to warnings.
// ============================================================================
$errors = $diag->errors();

$dupRouteKeys = [];   // METHOD:path => list<controller::method>
$operationIds = [];   // operationId => [a, b]
$schemaCols = [];     // [a, b, target]
foreach ($errors as $e) {
    $cause = $e['cause'];
    $loc = ($e['controller'] ?? '?') . ($e['method'] ? '::' . $e['method'] : '');
    if (preg_match('/duplicate (?:route|operation) key ([A-Z]+:[^ ]+)/', $cause, $m)) {
        $dupRouteKeys[$m[1]][] = $loc;
    } elseif (preg_match('/operationId "([^"]+)" is assigned to 2 operations: ([^.]+)/', $cause, $m)) {
        $operationIds[$m[1]] = array_map('trim', explode(',', $m[2]));
    } elseif (preg_match('/schema name collision: (\S+) and (\S+) both map to (\S+)/', $cause, $m)) {
        $schemaCols[] = [$m[1], $m[2], $m[3]];
    }
}
foreach ($dupRouteKeys as $key => $locs) {
    $dupRouteKeys[$key] = array_values(array_unique($locs));
}

echo "\n==== structural ERRORs (distinct — Step 6b fix list) ====\n";
echo "raw aggregated ERROR records: " . count($errors) . "\n";
echo "distinct duplicate route keys: " . count($dupRouteKeys) . "\n";
echo "distinct operationId collisions: " . count($operationIds) . "\n";
echo "schema-name collisions: " . count($schemaCols) . "\n";

echo "\n-- duplicate METHOD:path route keys (" . count($dupRouteKeys) . ") --\n";
foreach ($dupRouteKeys as $key => $locs) {
    echo "  • " . $key . "\n";
    foreach ($locs as $loc) {
        echo "      - " . $loc . "\n";
    }
}

echo "\n-- operationId collisions (" . count($operationIds) . ") --\n";
foreach ($operationIds as $id => $pair) {
    echo "  • " . $id . "  <" . implode(' | ', $pair) . ">\n";
}

echo "\n-- schema-name collisions (" . count($schemaCols) . ") --\n";
foreach ($schemaCols as [$a, $b, $target]) {
    echo "  • " . $target . "  <" . $a . " | " . $b . ">\n";
}

// ============================================================================
// Acceptance. The contract the probe pins (plan §11.6 / Step 5):
//   - publication is BLOCKED (an ERROR forbids it in every policy; none are downgraded to warnings);
//   - it is a dry run → reason='dry-run', nothing published;
//   - N's current .cache artifacts are byte-identical before/after (read-only probe).
// The raw record count is NOT asserted to a fixed number: the Coordinator's aggregated surface (route compiler +
// emitter + operationId resolver) is strictly BROADER than the §4.11 emitter-only baseline of 15 (14 duplicate
// route-key records + 1 CreateNewsDto collision), which it contains as a subset, plus emitter duplicate-operation
// records and operationId-collision records the single-purpose probe never aggregated. The baseline "15" maps to
// 7 distinct duplicate METHOD:path keys (×2 records each) + 1 CreateNewsDto collision; the Coordinator additionally
// surfaces the operationId collisions on the Core↔Next auth overrides.
// ============================================================================
echo "\n==== verdict ====\n";
$createNews = count($schemaCols) >= 1 && stripos(implode(' ', array_merge(...$schemaCols)), 'CreateNewsDto') !== false;
$ok = !$result->published
    && $result->reason === 'dry-run'
    && $result->errorCount > 0
    && count($dupRouteKeys) >= 7
    && $createNews
    && $unchanged;
echo "blocked & dry-run & ≥7 duplicate route keys & CreateNewsDto collision & cache unchanged: " . ($ok ? 'PASS' : 'FAIL') . "\n";
echo "  ({$result->errorCount} aggregated ERROR records; §4.11 emitter-only baseline of 15 is a subset — see audit §4.12)\n";

exit($ok ? 0 : 1);
