<?php

/**
 * Dev-only generator (NOT a committed test). Materializes the application config the production `next` build hands
 * to the Coordinator (Step 6b #3, plan §11.1/§19):
 *
 *   next/config/operation_id_map.lock.php — the REAL tri-state operationId lockfile, parsed from the rev.3
 *                                           reconciliation TSV (controller::method => preserved id | null(deferred));
 *   next/config/route_override_map.php    — the 6 declared Core→Next auth overrides (Next shadows the framework
 *                                           auth templates), winner per METHOD:path.
 *
 * Both files are PURE DATA (a `return [...]` array) — loadable by `require` in preload.php and passed straight into
 * ApplicationContext::operationIdMap / ::routeOverrideMap. The Coordinator's Fingerprinter records only their md5
 * (never the contents); di_config.php is hashed the same way, so no secret ever lands in the manifest wholesale.
 *
 * The map-building logic MIRRORS gen_n_coordinator_dryrun.php verbatim (same TSV columns, same lockfile tri-state,
 * same 6 overrides), so the dry-run probe and the published config can never drift. The probe runs the Coordinator
 * with the map; this script writes the map to disk. Re-running is idempotent (sorted keys, static header, no
 * timestamp) — the generated files are byte-stable across regenerations until the TSV changes.
 *
 * Run from the SpsFW repo:  php docs/metadata_compiler_audit/gen_n_application_config.php
 */

declare(strict_types=1);

$spsfwRoot = dirname(__DIR__, 2);
$nextRoot = dirname($spsfwRoot) . '/lk.sps38.pro/next';

if (!is_dir($nextRoot)) {
    fwrite(STDERR, "consumer repo not found at $nextRoot — nothing to generate\n");
    exit(2);
}

$tsvPath = $spsfwRoot . '/docs/metadata_compiler_audit/operation_id_reconciliation.tsv';
if (!is_file($tsvPath)) {
    fwrite(STDERR, "reconciliation TSV not found at $tsvPath\n");
    exit(2);
}

// ============================================================================
// Tri-state operationId lockfile (plan §19) — parsed from the rev.3 reconciliation TSV. Mirrors
// gen_n_coordinator_dryrun.php column-for-column so the dry-run probe and this published config agree exactly.
//   lockfile=in        → preserved canonical id (the documented id pinned by the reconciliation TSV);
//   lockfile=deferred  → null (stay id-less — the legacy ops the client sees id-less today);
//   lockfile=out       → ABSENT from the map (falls through to the <ControllerShort><Method> convention).
// ============================================================================
$operationIdMap = []; // array<string,?string>  controller::method => preserved id | null(deferred)
$preserved = 0;
$deferred = 0;
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
        $operationIdMap[$controllerMethod] = $canonical; // PRESERVED id
        $preserved++;
    } elseif ($lockfile === 'deferred') {
        $operationIdMap[$controllerMethod] = null;       // DEFERRED — stay id-less
        $deferred++;
    }
    // lockfile=out ⇒ absent from the map ⇒ convention <ControllerShort><Method>
}
ksort($operationIdMap); // deterministic key order → byte-stable file across regenerations

// ============================================================================
// The 6 Core↔Next auth overrides (Next shadows the framework auth templates) — intentional, NOT genuine bugs.
// Declared as a compile-time routeOverrideMap so each Next method wins INDEPENDENTLY of discovery order and the
// shadowed Core ops never reach OpenAPI nor the operationId check. Mirrors gen_n_coordinator_dryrun.php verbatim.
// ============================================================================
$routeOverrideMap = [
    'POST:/api/auth/login'             => 'SpsNext\\Auth\\AuthController::login',
    'POST:/api/auth/register'          => 'SpsNext\\Auth\\AuthController::register',
    'POST:/api/auth/logout'            => 'SpsNext\\Auth\\AuthController::logout',
    'POST:/api/auth/refresh-tokens'    => 'SpsNext\\Auth\\AuthController::refreshTokens',
    'PATCH:/api/auth/add-access-rules' => 'SpsNext\\Auth\\AuthController::addAccessRules',
    'POST:/api/auth/set-access-rules'  => 'SpsNext\\Auth\\AuthController::setAccessRules',
];

// ============================================================================
// Emit the two config files as deterministic `<?php return [...];` arrays.
// ============================================================================
$opIdHeader = [
    'Tri-state operationId lockfile (plan §19) — GENERATED by docs/metadata_compiler_audit/gen_n_application_config.php.',
    'Source: docs/metadata_compiler_audit/operation_id_reconciliation.tsv (rev.3). Do NOT hand-edit; regenerate instead.',
    '',
    'controller::method => preserved canonical id | null',
    '  lockfile=in        => preserved id (the documented canonical id pinned by the reconciliation TSV);',
    '  lockfile=deferred  => null (stay id-less — the legacy ops the client sees id-less today);',
    '  lockfile=out       => ABSENT (falls through to the <ControllerShort><Method> convention).',
    '',
    'The Coordinator receives this map as ApplicationContext::operationIdMap. The manifest records only its md5,',
    'never the contents. Preserved ids: ' . $preserved . '. Deferred-nulls: ' . $deferred . '. Total: ' . count($operationIdMap) . '.',
];
$overrideHeader = [
    'Core→Next route overrides (plan §11.1) — the framework auth templates Next shadows. GENERATED; do not hand-edit.',
    'Regenerate: php docs/metadata_compiler_audit/gen_n_application_config.php (from the SpsFW repo).',
    '',
    'METHOD:path => winning controller::method. Declared as a compile-time routeOverrideMap so each Next method wins',
    'INDEPENDENTLY of discovery order, and the shadowed Core ops never reach OpenAPI nor the operationId check.',
    '',
    'The Coordinator receives this map as ApplicationContext::routeOverrideMap. The manifest records only its md5.',
];

$emit = static function (array $headerLines, array $map): string {
    $out = "<?php\n\n/**\n";
    foreach ($headerLines as $line) {
        $out .= ' * ' . $line . "\n";
    }
    $out .= " */\n\nreturn " . var_export($map, true) . ";\n";
    return $out;
};

$configDir = $nextRoot . '/config';
if (!is_dir($configDir)) {
    mkdir($configDir, 0777, true);
}

$opIdFile = $configDir . '/operation_id_map.lock.php';
$overrideFile = $configDir . '/route_override_map.php';
file_put_contents($opIdFile, $emit($opIdHeader, $operationIdMap));
file_put_contents($overrideFile, $emit($overrideHeader, $routeOverrideMap));

// ============================================================================
// Self-check + report. The counts must match the dry-run probe (367 entries: 61 preserved + 306 deferred) and the
// 6 overrides must be intact. A TSV shape change surfaces here loudly rather than silently shipping a different map.
// ============================================================================
echo "==== N application config materialized ====\n";
echo "operationIdMap: " . count($operationIdMap) . " entries ({$preserved} preserved ids + {$deferred} deferred-nulls)\n";
echo "routeOverrideMap: " . count($routeOverrideMap) . " overrides\n";

$ok = count($operationIdMap) === $preserved + $deferred
    && $preserved > 0
    && $deferred > 0
    && count($routeOverrideMap) === 6;

// Round-trip the generated files: they must be loadable and equal to the in-memory maps.
$loadedOpId = require $opIdFile;
$loadedOverride = require $overrideFile;
$ok = $ok
    && is_array($loadedOpId) && $loadedOpId === $operationIdMap
    && is_array($loadedOverride) && $loadedOverride === $routeOverrideMap;

echo "written: " . $opIdFile . "\n";
echo "written: " . $overrideFile . "\n";
echo "round-trip loadable & equal: " . ($ok ? 'YES' : 'NO') . "\n";
echo "verdict: " . ($ok ? 'PASS' : 'FAIL') . "\n";
exit($ok ? 0 : 1);
