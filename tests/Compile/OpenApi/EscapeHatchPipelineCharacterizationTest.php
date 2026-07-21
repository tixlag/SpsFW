<?php

declare(strict_types=1);

use SpsFW\Core\Compile\CompileDiagnostics;
use SpsFW\Core\Compile\OpenApi\OpenApiEscapeHatch;
use SpsFW\Core\Compile\OpenApi\OpenApiEscapeHatchMerger;

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once __DIR__ . '/escape_hatch/PolymorphicFragment.php';
require_once __DIR__ . '/escape_hatch/ForbiddenOperationFragment.php';

/**
 * Step 8 (M6) — CHARACTERIZATION of the escape-hatch swagger-php pipeline + annotation guard, in ISOLATION.
 *
 * This runs BEFORE the merger is wired into the Coordinator. It proves two things against the REAL
 * swagger-php pipeline (the schema-preserving one owned by {@see OpenApiEscapeHatchMerger}, which has no
 * BuildPaths/CleanUnusedComponents):
 *  (a) a polymorphic schema (oneOf + discriminator + local $refs) SURVIVES into components.schemas intact;
 *  (b) a forbidden operation annotation (#[OA\Get]) is DETECTED by {@see EscapeHatchAnalysisGuard} even though
 *      the pipeline drops path processors — i.e. the annotation-level guard, not a shape-only check, catches it.
 *
 * The merger's merge() is the pipeline entry point; it scans the whitelist and reports into a fresh
 * CompileDiagnostics, so this test exercises the exact scan path production uses (minus the graph).
 */

// A minimal graph document the fragments merge into (the real emitter always supplies Error + securitySchemes;
// here an empty schemas map is enough to prove fragment survival + collision behavior). Uniquely named so the
// recursive test runner (which `require`s every *Test.php in one scope) never collides with a sibling's helper.
function characterizationGraphDocument(): array
{
    return [
        'openapi' => '3.1.0',
        'info' => ['title' => 'T', 'version' => '1'],
        'paths' => [],
        'components' => ['schemas' => [], 'securitySchemes' => []],
    ];
}

// ============================================================================
// (a) A polymorphic fragment (oneOf + discriminator + local $refs) survives the pipeline intact.
// ============================================================================
$diag = new CompileDiagnostics();
$merger = new OpenApiEscapeHatchMerger($diag, dirname(__DIR__, 3));
$hatch = new OpenApiEscapeHatch(
    [\SpsOaTest\EscapeHatch\PolymorphicThing::class],
    ['PolymorphicThing', 'ConcreteA', 'ConcreteB'],
);
$merged = $merger->merge(characterizationGraphDocument(), $hatch);

assert_true(!$diag->hasErrors(), 'polymorphic fragment: scan produces no FATAL errors' . ($diag->hasErrors() ? "\n" . $diag->render() : ''));
$schemas = $merged['components']['schemas'];
assert_true(isset($schemas['PolymorphicThing']), 'PolymorphicThing merged as a component');
assert_true(isset($schemas['ConcreteA']), 'ConcreteA (oneOf sibling) merged');
assert_true(isset($schemas['ConcreteB']), 'ConcreteB (oneOf sibling) merged');

$poly = $schemas['PolymorphicThing'];
assert_true(isset($poly['oneOf']) && is_array($poly['oneOf']), 'PolymorphicThing preserves oneOf');
assert_same(2, count($poly['oneOf']), 'oneOf has both concrete refs');
assert_true(isset($poly['discriminator']), 'PolymorphicThing preserves discriminator');
assert_same('type', $poly['discriminator']['propertyName'], 'discriminator propertyName is "type"');

$oneOfRefs = [];
foreach ($poly['oneOf'] as $entry) {
    if (is_array($entry) && isset($entry['$ref'])) {
        $oneOfRefs[] = $entry['$ref'];
    }
}
assert_true(in_array('#/components/schemas/ConcreteA', $oneOfRefs, true), 'oneOf points at ConcreteA via local $ref');
assert_true(in_array('#/components/schemas/ConcreteB', $oneOfRefs, true), 'oneOf points at ConcreteB via local $ref');

// the merged fragment carries no paths / no other root section (shape guard holds on a clean fragment).
assert_true(!isset($merged['paths']) || $merged['paths'] === [], 'clean fragment contributes no paths');

// ============================================================================
// (b) A forbidden operation annotation (#[OA\Get]) is caught by the annotation guard EVEN WITHOUT BuildPaths.
//     The schema-preserving pipeline has no path builder, so the Get would vanish from the generated doc —
//     only the annotation-level EscapeHatchAnalysisGuard inspects the raw Analysis and FATALs it.
// ============================================================================
$forbiddenDiag = new CompileDiagnostics();
$forbiddenMerger = new OpenApiEscapeHatchMerger($forbiddenDiag, dirname(__DIR__, 3));
$forbiddenHatch = new OpenApiEscapeHatch(
    [\SpsOaTest\EscapeHatch\ForbiddenOperationCarrier::class],
    [], // no schemas requested — the scan itself must FATAL on the forbidden annotation.
);
$forbiddenMerger->merge(characterizationGraphDocument(), $forbiddenHatch);

assert_true($forbiddenDiag->hasErrors(), 'a forbidden #[OA\Get] annotation produces a FATAL even without BuildPaths');
$rendered = $forbiddenDiag->render();
assert_true(str_contains($rendered, 'Get'), 'the FATAL names the forbidden annotation type (Get)');
assert_true(
    str_contains($rendered, 'ForbiddenOperationCarrier'),
    'the FATAL names the source class (ForbiddenOperationCarrier)',
);
assert_true(
    str_contains($rendered, 'escape_hatch') || str_contains($rendered, 'ForbiddenOperationFragment'),
    'the FATAL names the source file',
);

echo "EscapeHatchPipelineCharacterization passed\n";
