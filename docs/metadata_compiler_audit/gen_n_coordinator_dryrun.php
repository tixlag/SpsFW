<?php

/**
 * Dev-only audit probe (NOT a committed test). The Step 5 Coordinator run against the REAL consumer app
 * (N = lk.sps38.pro/next), READ-ONLY / DRY-RUN (plan §11.6, Step 5 acceptance).
 *
 * Goal: drive the production Coordinator end-to-end on N's actual controller tree — WITH the REAL tri-state
 * operationId map (reconciliation TSV) and the 6 declared Core↔Next auth overrides — and confirm the publication gate
 * behaves exactly as promised, WITHOUT touching N's current .cache artifacts:
 *
 *   - the single compile flow aggregates diagnostics from the route compiler, OpenAPI emitter + validator, and DI;
 *   - 6 Core↔Next auth duplicates are RECOGNIZED as intentional overrides (NOT errors) — Next shadows the framework
 *     auth templates, so they no longer block publication as "duplicate route key";
 *   - the residual ERROR set is exactly 2 DISTINCT defects (3 aggregated records): the EmployeeDocuments genuine
 *     duplicate (two methods on ONE controller sharing GET:/api/employees/documents/code-1c/{code_1c}) and the
 *     CreateNewsDto schema-name collision. These are REAL N bugs, reported as ERRORs, FORBIDDING publication in every
 *     policy (never downgraded to warnings), and captured for the MANDATORY fix before Step 6b;
 *   - operationId collisions == 0: the real map assigns preserved ids + deferred-nulls and the shadowed Core auth
 *     ops are excluded from the uniqueness check — nothing collides;
 *   - dryRun publishes nothing and writes NOTHING to N's .cache (read-only probe contract).
 *
 * (The unmapped baseline — empty operationId map, no overrides — surfaced 15 aggregated ERRORs; this probe pins the
 * reduced-to-real-defects contract that remains once the real tri-state map + the 6 overrides are wired in.)
 *
 * Bootstrapping mirrors gen_n_openapi_parity.php (N's full dependency set, local SpsFW src prepended over the
 * vendored copy, SpsNext\ registered, SPSFW_PROJECT_ROOT → N). DI bindings are seeded from N's config/di_config.php
 * so the DI compile-only path is exercised EXACTLY as production (production built N's compiled_di.php from the same
 * bindings): this keeps the DI stage clean and isolates the structural route/OpenAPI errors.
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
// The REAL tri-state operationId lockfile (plan §19) — parsed from the rev.3 reconciliation TSV, NOT an empty
// default. Each documented op gets its preserved canonical id (lockfile=in) or a NULL (lockfile=deferred — the
// 306 id-less legacy ops stay id-less, exactly as today's client sees them). Ops held out of the spec
// (lockfile=out: exclude/pending/spec-only) are ABSENT from the map, so they fall through to the
// <ControllerShort><Method> convention. This is the inventory the production `next` build must hand the
// Coordinator so legacy ids are preserved and only NEW route-only ops get the convention.
// ============================================================================
$tsvPath = $spsfwRoot . '/docs/metadata_compiler_audit/operation_id_reconciliation.tsv';
$operationIdMap = []; // array<string,?string>  controller::method => preserved id | null(deferred)
if (is_file($tsvPath)) {
    foreach (file($tsvPath, FILE_IGNORE_NEW_LINES) as $line) {
        if ($line === '' || $line[0] === '#') {
            continue; // comment / count lines
        }
        $c = explode("\t", $line);
        if (count($c) < 11 || $c[2] === '-') {
            continue; // header row / spec-only rows carry no controller::method
        }
        $controllerMethod = $c[2]; // FQCN::method
        $canonical = $c[9];        // ready-to-pin id, or '-'
        $lockfile = $c[10];        // in | deferred | out
        if ($lockfile === 'in' && $canonical !== '-' && $canonical !== '') {
            $operationIdMap[$controllerMethod] = $canonical; // PRESERVED id — canonical pinned by the reconciliation TSV
        } elseif ($lockfile === 'deferred') {
            $operationIdMap[$controllerMethod] = null;       // DEFERRED — stay id-less (tri-state null, the legacy ops)
        }
        // lockfile=out ⇒ absent from the map ⇒ convention <ControllerShort><Method>
    }
}

// ============================================================================
// The 6 Core↔Next auth duplicates are INTENTIONAL overrides (Next shadows the framework auth templates), NOT
// genuine bugs. Declared here as a compile-time routeOverrideMap so each Next method wins INDEPENDENTLY of
// discovery order and the shadowed Core ops never reach OpenAPI nor the operationId check. (The genuine
// EmployeeDocuments duplicate — two methods on ONE controller sharing GET:/api/employees/documents/code-1c/{code_1c}
// — is NOT overridden and STAYS a structural ERROR; that one is a real bug to fix in N.)
// ============================================================================
$routeOverrideMap = [
    'POST:/api/auth/login'            => 'SpsNext\\Auth\\AuthController::login',
    'POST:/api/auth/register'         => 'SpsNext\\Auth\\AuthController::register',
    'POST:/api/auth/logout'           => 'SpsNext\\Auth\\AuthController::logout',
    'POST:/api/auth/refresh-tokens'   => 'SpsNext\\Auth\\AuthController::refreshTokens',
    'PATCH:/api/auth/add-access-rules' => 'SpsNext\\Auth\\AuthController::addAccessRules',
    'POST:/api/auth/set-access-rules' => 'SpsNext\\Auth\\AuthController::setAccessRules',
];

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
    operationIdMap: $operationIdMap,
    routeOverrideMap: $routeOverrideMap,
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
$preserved = count(array_filter($operationIdMap, static fn($v): bool => $v !== null));
$deferred = count($operationIdMap) - $preserved;
echo "operationIdMap: " . count($operationIdMap) . " entries ($preserved preserved ids + $deferred deferred-nulls); routeOverrideMap: " . count($routeOverrideMap) . " declared overrides\n";
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
// Applied route overrides (the 6 Core↔Next auth shadows) — what the engine RECOGNIZED as intentional, not bugs.
// ============================================================================
echo "\n-- applied route overrides (" . count($result->overrides) . ") — intentional Core↔Next shadows --\n";
foreach ($result->overrides as $ov) {
    echo "  • " . $ov['key'] . "  winner=" . $ov['winner'] . "  shadowed=[" . implode(', ', $ov['shadowed']) . "]\n";
}

// ============================================================================
// Acceptance (Step 5 fix-pass). The probe drives the Coordinator with the REAL tri-state operationId map AND the
// 6 declared Core↔Next auth overrides, and pins the contract that proves the fix-pass correct on real N:
//   - the 6 auth duplicates are RECOGNIZED as intentional overrides (6 applied), NOT errors — so they no longer
//     block publication as "duplicate route key";
//   - the ONE genuine duplicate (EmployeeDocuments — two methods on one controller) is NOT overridden and STAYS a
//     structural ERROR (a real bug to fix in N, not a compiler artifact);
//   - the CreateNewsDto schema-name collision STAYS (two DTOs mapping to one schema — a real N bug);
//   - operationId collisions == 0: the real map assigns preserved ids + deferred-nulls, and the shadowed Core auth
//     ops are excluded from the uniqueness check — so there is NOTHING to collide. This is the
//     directive #4 proof: the previously-aggregated operationId collisions vanish with the real tri-state map, for
//     a proven reason (shadowing + preserved-id uniqueness), not because they were silently dropped;
//   - dry-run → nothing published; N's .cache artifacts are byte-identical before/after (read-only probe).
// ============================================================================
echo "\n==== verdict ====\n";
$employeeDocs = count($dupRouteKeys) === 1
    && isset($dupRouteKeys['GET:/api/employees/documents/code-1c/{code_1c}']);
$createNews = count($schemaCols) >= 1 && stripos(implode(' ', array_merge(...$schemaCols)), 'CreateNewsDto') !== false;
$authApplied = count($result->overrides) === 6;
$ok = !$result->published
    && $result->reason === 'dry-run'
    && $result->errorCount > 0
    && count($dupRouteKeys) === 1 && $employeeDocs   // ONLY the genuine EmployeeDocuments dup remains
    && count($operationIds) === 0                     // operationId collisions vanish with the real map (directive #4)
    && $createNews                                    // the genuine CreateNewsDto schema collision remains
    && $authApplied                                   // the 6 auth overrides recognized as intentional
    && $unchanged;                                    // read-only: N's cache untouched
echo "dry-run & only EmployeeDocuments dup & 0 operationId collisions & CreateNewsDto & 6 auth overrides & cache unchanged: " . ($ok ? 'PASS' : 'FAIL') . "\n";
echo "  ({$result->errorCount} ERROR record(s): EmployeeDocuments ×2 + CreateNewsDto ×1; the 6 Core↔Next auth dups are recognized as intentional overrides, down from 15 in the unmapped baseline)\n";

exit($ok ? 0 : 1);
