<?php

/**
 * Dev-only audit probe (NOT a committed test). The Step 4 OpenAPI parity measurement against the REAL consumer
 * app (N = lk.sps38.pro/next).
 *
 *   C. Secondary OpenAPI emission (PARITY mode). RouteMetadataCompiler builds OperationMetadata[] (tri-state
 *      lockfile); OpenApiEmitter emits the document ARRAY ONCE. The 374 response-projection migration gaps are
 *      WARNINGS — in parity mode they do NOT block emission. Structural errors (duplicate METHOD:path, schema
 *      collisions, unresolvable $ref, …) DO block PUBLICATION of the secondary file: the array-first contract is
 *      `emit() → OpenApiValidator::validate($doc) → publish gated by throwOnErrors`. On structural errors the
 *      probe still builds the in-memory preview for the parity report (section D), but the secondary file is NOT
 *      written — there is no published artifact. (In strict/managed mode the same gate is enforced fatally via
 *      CompileDiagnostics::throwOnErrors().)
 *
 *   D. Normalized parity vs legacy swagger-php openapi.yml — ParityReport parses BOTH docs (round-trip),
 *      normalizes (recursive ksort; strip x-fqcn; CANONICALIZE nullable⇒3.1 union on both sides; drop empty
 *      containers but KEEP security:[]), and compares array-to-array. Divergences are bucketed by the path
 *      segment they touch (operationId / responses / parameters / schemas / security …) so the audit can
 *      separate the by-design deferred operationId delta (M9) from the real response/schema migration worklist.
 *
 *   E. Prereq 4 — non-promoted constructor fields. The generated schema projection EXCLUDES them (they are never
 *      json_serialize'd); swagger-php includes OA-tagged non-promoted ctor params. For every DTO the emitter
 *      collected, this checks ctor params that are non-promoted + carry #[OA\Property] and confirms each such
 *      field is present in legacy but absent in generated — quantifying the divergence. The FQCN⇒name mapping
 *      comes from the emitter's INTERNAL componentRegistry() (never published as x-fqcn).
 *
 * The committed artifact is the numeric summary recorded in AUDIT §4.11, not this script's runtime.
 *
 * Bootstrapping mirrors gen_n_route_and_operation_parity.php: load N's full dependency set, PREPEND the local
 * SpsFW working-tree src over the vendored copy, register next/src for SpsNext\, and point SPSFW_PROJECT_ROOT
 * at N so PathManager resolves the SAME controller discovery dirs N's own Router scanned.
 */

declare(strict_types=1);

use OpenApi\Attributes as OA;
use SpsFW\Core\Compile\CompileDiagnostics;
use SpsFW\Core\Compile\OpenApi\OpenApiEmitter;
use SpsFW\Core\Compile\OpenApi\OpenApiValidator;
use SpsFW\Core\Compile\OpenApi\ParityReport;
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

putenv('SPSFW_PROJECT_ROOT=' . $nextRoot);
$_ENV['SPSFW_PROJECT_ROOT'] = $nextRoot;

$dirs = array_values(array_filter(PathManager::getControllersDirs(), 'is_dir'));

$legacyPath = $nextRoot . '/.cache/swagger/openapi.yml';
if (!is_file($legacyPath)) {
    fwrite(STDERR, "legacy openapi.yml not found at $legacyPath — rebuild N swagger cache first\n");
    exit(2);
}

// ============================================================================
// Tri-state operationId lockfile (from the reconciliation TSV) — same as the §4.8 probe part B.
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
            $map[$sig] = null;              // stays id-less (306 rows) — M9 canonical-id assignment
        } // 'out' (12) ⇒ absent ⇒ convention applies
    }
    fclose($fh);
}

// ============================================================================
// C. Secondary OpenAPI emission (PARITY mode — warnings do NOT block; structural errors DO block publish).
// ============================================================================
$opDiag = new CompileDiagnostics();
$opCompiler = new RouteMetadataCompiler($opDiag, operationIdMap: $map);
$ops = $opCompiler->compileAllOperations($dirs);

// Reuse legacy info.title / info.version so info.* is not a spurious mismatch.
$report = new ParityReport();
$legacy = $report->parseFile($legacyPath);
$title = is_array($legacy['info'] ?? null) ? ($legacy['info']['title'] ?? 'SpsFW API') : 'SpsFW API';
$version = is_array($legacy['info'] ?? null) ? ($legacy['info']['version'] ?? '0.1.0') : '0.1.0';

// ARRAY-FIRST: emit() builds the document ONCE and accumulates diagnostics ONCE (deduplicated). The serialized
// form is produced later from THIS array (dump/writeFile) without recompiling — no doubled diagnostics.
$emitDiag = new CompileDiagnostics();
$emitter = new OpenApiEmitter($emitDiag);
$doc = $emitter->emit($ops, title: $title, version: $version);

// Structural validation of the BUILT array (a YAML round-trip alone is insufficient). Validator findings are
// FATAL on their own diagnostics so they can be reported separately from emission fatals.
$validDiag = new CompileDiagnostics();
(new OpenApiValidator($validDiag))->validate($doc);

// Publication gate (plan Шаг 4 severity contract): the secondary file is published ONLY when there are no
// structural errors. throwOnErrors() enforces that in strict/managed (it throws). In parity-probe mode we honor
// the SAME gate — errors block the artifact — but still print the in-memory preview (section D), so we gate on
// hasErrors() rather than letting throwOnErrors() terminate the measurement.
$generatedPath = $nextRoot . '/.cache/swagger/openapi.generated.yml';
$publishable = !$emitDiag->hasErrors() && !$validDiag->hasErrors();

echo "=== C. Secondary OpenAPI emission (PARITY mode) ===\n";
echo "Discovery dirs: " . implode(', ', $dirs) . "\n";
echo "Raw operations fed to emitter: " . count($ops) . "\n";
echo "Effective paths emitted:       " . count($doc['paths']) . "\n";
echo "Component schemas emitted:     " . count($doc['components']['schemas']) . "\n";
echo "Emission diagnostics — fatal:   " . $emitDiag->errorCount() . "\n";
echo "Emission diagnostics — warning: " . $emitDiag->warningCount() . "\n";
$dupKeys = 0;
$collisions = 0;
foreach ($emitDiag->errors() as $e) {
    if (str_contains($e['cause'] ?? '', 'duplicate operation key')) {
        $dupKeys++;
    } elseif (str_contains($e['cause'] ?? '', 'schema name collision')) {
        $collisions++;
    }
}
echo "  emission fatals — duplicate METHOD:path (last-wins, mirrors Router): $dupKeys\n";
echo "  emission fatals — schema-name collision:                              $collisions\n";
foreach ($emitDiag->errors() as $e) {
    $cause = $e['cause'] ?? '';
    if (!str_contains($cause, 'duplicate operation key') && !str_contains($cause, 'schema name collision')) {
        echo "  emission fatal (other): dto=" . ($e['dto'] ?? '?') . ' field=' . ($e['field'] ?? '?') . ' :: ' . $cause . "\n";
    }
}
echo "Structural validation findings (OpenApiValidator): " . $validDiag->errorCount() . "\n";
foreach ($validDiag->errors() as $e) {
    echo "  validation fatal: field=" . ($e['field'] ?? '?') . ' :: ' . ($e['cause'] ?? '') . "\n";
}

if ($publishable) {
    $emitter->writeFile($doc, $generatedPath);
    echo "Published secondary: $generatedPath (no structural errors — throwOnErrors() would pass)\n";
} else {
    echo "Publication BLOCKED by " . ($emitDiag->errorCount() + $validDiag->errorCount()) . " structural error(s) — secondary file NOT written (in-memory preview only, per parity-mode contract)\n";
    echo "  strict/managed would call throwOnErrors() and HALT on these error(s)\n";
}

// Mode behavior: parity tolerates the migration gaps; strict/managed halts.
$opWarnings = $opDiag->warningCount();
$opErrors = $opDiag->errorCount();
echo "Operation-projection migration gaps (warnings): $opWarnings — NON-BLOCKING in parity mode\n";
echo "Operation-projection structural errors:         $opErrors\n";
echo "=> strict/managed would call throwOnErrorsAndWarnings() and HALT on $opWarnings warning(s) + $opErrors error(s)\n";

// ============================================================================
// D. Normalized parity vs legacy swagger-php openapi.yml (in-memory — always produced, even when unpublished).
// ============================================================================
$result = $report->compare($doc, $legacy);

echo "\n=== D. Normalized parity (generated vs legacy swagger-php openapi.yml) ===\n";
echo "Generated — paths:{$result['generated']['paths']} schemas:{$result['generated']['schemas']} operations:{$result['generated']['operations']}\n";
echo "Legacy    — paths:{$result['legacy']['paths']} schemas:{$result['legacy']['schemas']} operations:{$result['legacy']['operations']}\n";
echo "Paths only in generated: " . count($result['paths_only_in_generated']) . "\n";
echo "Paths only in legacy:    " . count($result['paths_only_in_legacy']) . "\n";
echo "Schemas only in generated: " . count($result['schemas_only_in_generated']) . "\n";
echo "Schemas only in legacy:    " . count($result['schemas_only_in_legacy']) . "\n";
echo "Total normalized divergences: {$result['divergence_count']}\n";
echo "Divergences by category: " . json_encode($result['divergences_by_category'], JSON_UNESCAPED_UNICODE) . "\n";

// Bucket the same divergences by the path SEGMENT they touch — separates the deferred operationId delta
// (M9, by-design) from the real response/schema migration worklist.
$segmentBuckets = [];
foreach ($result['divergences_sample'] as $d) {
    $p = $d['path'];
    $seg = match (true) {
        str_contains($p, '.operationId') => 'operationId',
        str_contains($p, '.responses') => 'responses',
        str_contains($p, '.parameters') => 'parameters',
        str_contains($p, '.requestBody') => 'requestBody',
        str_contains($p, '.security') || str_contains($p, 'x-required-rules') => 'security',
        str_contains($p, 'components.schemas') => 'schemas',
        str_contains($p, '.tags') => 'tags',
        str_contains($p, '.info') => 'info',
        default => 'other',
    };
    $segmentBuckets[$seg] = ($segmentBuckets[$seg] ?? 0) + 1;
}
echo "Divergence SAMPLE bucketed by segment (sample size " . count($result['divergences_sample']) . "):\n";
foreach ($segmentBuckets as $seg => $c) {
    echo "  $seg: $c\n";
}

// A few concrete samples per non-operationId segment (the real worklist).
echo "Sample non-operationId divergences (first 12):\n";
$shown = 0;
foreach ($result['divergences_sample'] as $d) {
    if (str_contains($d['path'], '.operationId')) {
        continue;
    }
    echo "  [{$d['category']}] {$d['path']}  gen=" . json_encode($d['generated'], JSON_UNESCAPED_UNICODE) . " leg=" . json_encode($d['legacy'], JSON_UNESCAPED_UNICODE) . "\n";
    if (++$shown >= 12) {
        break;
    }
}

// ============================================================================
// E. Prereq 4 — non-promoted constructor fields (swagger-php includes them; the projection excludes them).
//    The FQCN⇒name mapping is the emitter's INTERNAL componentRegistry() (never published as x-fqcn).
// ============================================================================
echo "\n=== E. Prereq 4: non-promoted constructor fields on real N DTOs ===\n";
$genSchemas = $doc['components']['schemas'] ?? [];
$legSchemas = $legacy['components']['schemas'] ?? [];
$nameToFqcn = array_flip($emitter->componentRegistry()); // name ⇒ owning FQCN
$dtoCount = 0;
$divergentDtoCount = 0;
$divergentFields = [];
foreach ($genSchemas as $shortName => $genSchema) {
    $fqcn = $nameToFqcn[$shortName] ?? null;
    if ($fqcn === null || !class_exists($fqcn)) {
        continue;
    }
    $ctor = (new ReflectionClass($fqcn))->getConstructor();
    if ($ctor === null) {
        continue;
    }
    $nonPromotedTagged = [];
    foreach ($ctor->getParameters() as $param) {
        if ($param->isPromoted()) {
            continue;
        }
        $attrs = $param->getAttributes(OA\Property::class);
        if ($attrs === []) {
            continue;
        }
        // Resolve the OA property name (property: arg) or fall back to the param name.
        $name = $param->getName();
        try {
            $inst = $attrs[0]->newInstance();
            /** @var OA\Property $inst */
            if ($inst->property !== null) {
                $name = $inst->property;
            }
        } catch (\Throwable) {
            // leave the param name
        }
        $nonPromotedTagged[] = $name;
    }
    if ($nonPromotedTagged === []) {
        continue;
    }
    $dtoCount++;
    $legProps = array_keys($legSchemas[$shortName]['properties'] ?? []);
    $genProps = array_keys($genSchema['properties'] ?? []);
    foreach ($nonPromotedTagged as $field) {
        $inLegacy = in_array($field, $legProps, true);
        $inGenerated = in_array($field, $genProps, true);
        if ($inLegacy && !$inGenerated) {
            // Confirmed: swagger-php emitted a non-promoted ctor field the projection (correctly) omits.
            $divergentFields[] = $shortName . '::' . $field;
        }
    }
    $hasDiv = false;
    foreach ($nonPromotedTagged as $field) {
        if (in_array($field, array_keys($legSchemas[$shortName]['properties'] ?? []), true)
            && !in_array($field, array_keys($genSchema['properties'] ?? []), true)) {
            $hasDiv = true;
        }
    }
    if ($hasDiv) {
        $divergentDtoCount++;
    }
}
echo "DTOs with non-promoted ctor params tagged #[OA\\Property]: $dtoCount\n";
echo "DTOs where swagger-php emitted such a field the projection omits: $divergentDtoCount\n";
echo "Total such non-promoted-field divergences: " . count($divergentFields) . "\n";
foreach (array_slice($divergentFields, 0, 20) as $f) {
    echo "  $f\n";
}

echo "\nProbe complete (exit 0 — parity is a measurement, divergences are expected migration gaps).\n";
exit(0);
