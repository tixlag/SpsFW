<?php

/**
 * Dev-only audit probe (NOT a committed test).
 *
 * SpsFW clean-checkout tests MUST NOT depend on the consumer repo (N). This script is run by hand against
 * the live consumer app to prove, on REAL production controllers, two things:
 *
 *   A. Route-IR parity — RouteMetadataCompiler + RouteCacheEmitter (local working tree) reproduce N's
 *      compiled_routes.php byte-for-byte (strict ===), all METHOD:path keys, including the middleware/access
 *      asymmetry, dtos rule graphs, and last-wins duplicate-key behaviour.
 *
 *   B. Operation projection — with the tri-state operationId lockfile built from the reconciliation TSV
 *      (in => preserved canonical_id, deferred => null, out => absent => convention), report the operation
 *      count, the operationId distribution (preserved / deferred-null / convention), and the compile
 *      diagnostics bucketed by category (the response-projection halts: missing return type, mixed,
 *      itemless array, unsupported union, non-eligible entity, JsonSerializable, path-param mismatch,
 *      operationId collision, Items misuse).
 *
 * The committed artifact is the numeric summary recorded in AUDIT §4.x, not this script's runtime.
 *
 * Bootstrapping mirrors gen_dto_rulegraph_parity.php: load N's full dependency set, PREPEND the local SpsFW
 * working-tree src over the vendored copy, register next/src for SpsNext\, and point SPSFW_PROJECT_ROOT at N
 * so PathManager resolves the SAME controller discovery dirs ([libraryRoot, next/src]) N's own Router scanned.
 */

declare(strict_types=1);

use SpsFW\Core\Compile\CompileDiagnostics;
use SpsFW\Core\Compile\Route\RouteCacheEmitter;
use SpsFW\Core\Compile\Route\RouteMetadataCompiler;
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

// Point the framework at N so getSrcPath() = next/src (the app controllers); libraryRoot is the local src/Core.
putenv('SPSFW_PROJECT_ROOT=' . $nextRoot);
$_ENV['SPSFW_PROJECT_ROOT'] = $nextRoot;

$dirs = array_values(array_filter(PathManager::getControllersDirs(), 'is_dir'));

$routesPath = $nextRoot . '/.cache/compiled_routes.php';
if (!is_file($routesPath)) {
    fwrite(STDERR, "compiled_routes.php not found at $routesPath — rebuild N cache first\n");
    exit(2);
}
$golden = require $routesPath;

// ============================================================================
// A. Route-IR parity: local compiler + emitter vs N's compiled_routes.php (strict ===).
// ============================================================================
$irDiag = new CompileDiagnostics();
$irCompiler = new RouteMetadataCompiler($irDiag);
$routes = $irCompiler->compile($dirs);
$emitted = (new RouteCacheEmitter())->emit($routes);

$goldenKeys = array_keys($golden);
$emittedKeys = array_keys($emitted);
$missing = array_diff($goldenKeys, $emittedKeys);
$extra = array_diff($emittedKeys, $goldenKeys);
$divergences = [];
foreach ($emitted as $key => $entry) {
    if (isset($golden[$key]) && $golden[$key] !== $entry) {
        $divergences[] = $key;
    }
}

echo "=== A. Route-IR parity (local compiler+emitter vs N compiled_routes.php) ===\n";
echo "Discovery dirs: " . implode(', ', $dirs) . "\n";
echo "Golden keys:  " . count($goldenKeys) . "\n";
echo "Emitted keys: " . count($emittedKeys) . "\n";
echo "Missing (golden only): " . count($missing) . "\n";
echo "Extra (emitted only):  " . count($extra) . "\n";
echo "Per-key divergences (!==): " . count($divergences) . "\n";
echo "Route-IR diagnostics (duplicate keys / untyped DTO params / missing DTO): " . $irDiag->count() . "\n";
foreach (array_slice($divergences, 0, 15) as $d) {
    echo "  DIVERGENCE: $d\n";
}
foreach (array_slice(array_values($missing), 0, 15) as $m) {
    echo "  MISSING: $m\n";
}
foreach (array_slice(array_values($extra), 0, 15) as $e) {
    echo "  EXTRA: $e\n";
}

// ============================================================================
// B. Operation projection with the tri-state operationId lockfile (from the reconciliation TSV).
// ============================================================================
$tsv = $spsfwRoot . '/docs/metadata_compiler_audit/operation_id_reconciliation.tsv';
$map = [];
$fh = @fopen($tsv, 'r');
if ($fh) {
    while (($row = fgetcsv($fh, 0, "\t")) !== false) {
        $first = $row[0] ?? '';
        if ($first === '' || $first === 'METHOD' || str_starts_with($first, '#')) {
            continue;
        }
        $sig = $row[2] ?? '';
        $canonical = $row[9] ?? '';
        $lockfile = $row[10] ?? '';
        if ($sig === '') {
            continue;
        }
        if ($lockfile === 'in') {
            $map[$sig] = $canonical;        // preserved canonical id (61 rows)
        } elseif ($lockfile === 'deferred') {
            $map[$sig] = null;              // stays id-less (306 rows)
        } // 'out' (12) ⇒ absent ⇒ convention applies
    }
    fclose($fh);
}

$opDiag = new CompileDiagnostics();
$opCompiler = new RouteMetadataCompiler($opDiag, operationIdMap: $map);
$ops = $opCompiler->compileAllOperations($dirs); // assertUnique() runs inside compileOperationClasses

$total = count($ops);
$idNonNull = 0;
$idNull = 0;
foreach ($ops as $op) {
    if ($op->operationId === null) {
        $idNull++;
    } else {
        $idNonNull++;
    }
}

echo "\n=== B. Operation projection (tri-state lockfile from reconciliation TSV) ===\n";
echo "Lockfile map: " . count($map) . " signatures (in+deferred); 'out' rows absent ⇒ convention\n";
echo "Operations compiled: $total\n";
echo "operationId non-null (preserved + convention): $idNonNull\n";
echo "operationId null (deferred, id-less):          $idNull\n";
echo "Diagnostics: " . $opDiag->count() . "\n";

$byField = [];
foreach ($opDiag->errors() as $e) {
    $f = $e['field'] ?? '?';
    $byField[$f] = ($byField[$f] ?? 0) + 1;
}
foreach ($byField as $f => $c) {
    echo "  field[$f]: $c\n";
}

$causeBuckets = [];
foreach ($opDiag->errors() as $e) {
    $cause = $e['cause'] ?? '';
    $bucket = match (true) {
        str_contains($cause, 'declares no return type') => 'missing-return-type',
        str_contains($cause, 'mixed return type') => 'mixed-return',
        str_contains($cause, 'array return type') => 'itemless-array',
        str_contains($cause, 'is not auto-derivable') => 'unsupported-return-type',
        str_contains($cause, 'not a DTO-eligible') => 'non-eligible-entity',
        str_contains($cause, 'implements JsonSerializable') => 'jsonserializable-response',
        str_contains($cause, 'path parameter') => 'path-param-mismatch',
        str_contains($cause, 'operationId') => 'operationId-collision',
        str_contains($cause, 'Items') => 'items-misuse',
        default => 'other: ' . mb_substr($cause, 0, 60),
    };
    $causeBuckets[$bucket] = ($causeBuckets[$bucket] ?? 0) + 1;
}
foreach ($causeBuckets as $b => $c) {
    echo "  cause[$b]: $c\n";
}

exit($divergences === [] && $missing === [] && $extra === [] ? 0 : 1);
