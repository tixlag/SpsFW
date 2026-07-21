<?php

declare(strict_types=1);

use SpsFW\Core\Compile\CompileDiagnostics;
use SpsFW\Core\Compile\OpenApi\OpenApiEscapeHatch;
use SpsFW\Core\Compile\OpenApi\OpenApiEscapeHatchMerger;
use SpsFW\Core\Compile\OpenApi\OpenApiValidator;

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once __DIR__ . '/escape_hatch/PolymorphicFragment.php';
require_once __DIR__ . '/escape_hatch/ForbiddenOperationFragment.php';
require_once __DIR__ . '/escape_hatch/ExternalRefFragment.php';
require_once __DIR__ . '/escape_hatch/CollisionFragmentA.php';
require_once __DIR__ . '/escape_hatch/CollisionFragmentB.php';

/**
 * Step 8 (M6) — {@see OpenApiEscapeHatchMerger}: the narrow OA escape hatch merges ONLY components.schemas.*
 * fragments (swagger-php whitelist) into the graph document, with strict provenance + two shape guards +
 * external-ref rejection. Every breach is a FATAL CompileDiagnostics error; on any error the document is
 * returned UNCHANGED. The pipeline itself (polymorphic survival + forbidden-annotation detection) is proven
 * separately in EscapeHatchPipelineCharacterizationTest; this test exercises the full merge contract.
 */

function escapeHatchGraphDocument(): array
{
    return [
        'openapi' => '3.1.0',
        'info' => ['title' => 'T', 'version' => '1'],
        'paths' => [],
        'components' => ['schemas' => [], 'securitySchemes' => []],
    ];
}

$root = dirname(__DIR__, 3);

// ============================================================================
// Empty hatch ⇒ no-op: the document is returned unchanged, zero diagnostics.
// ============================================================================
$emptyDiag = new CompileDiagnostics();
$emptyMerger = new OpenApiEscapeHatchMerger($emptyDiag, $root);
$emptyDoc = escapeHatchGraphDocument();
assert_same($emptyDoc, $emptyMerger->merge($emptyDoc, OpenApiEscapeHatch::empty()), 'empty hatch ⇒ no-op (document unchanged)');
assert_true(!$emptyDiag->hasErrors() && !$emptyDiag->hasWarnings(), 'empty hatch ⇒ no diagnostics');

// ============================================================================
// Whitelist scan extracts ONLY the declared schemas (no extras leak in).
// ============================================================================
$okDiag = new CompileDiagnostics();
$okMerger = new OpenApiEscapeHatchMerger($okDiag, $root);
$okMerged = $okMerger->merge(
    escapeHatchGraphDocument(),
    new OpenApiEscapeHatch(
        [\SpsOaTest\EscapeHatch\PolymorphicThing::class],
        ['PolymorphicThing', 'ConcreteA', 'ConcreteB'],
    ),
);
assert_true(!$okDiag->hasErrors(), 'whitelist scan: no errors');
assert_same(
    ['ConcreteA', 'ConcreteB', 'PolymorphicThing'],
    array_keys($okMerged['components']['schemas']),
    'exactly the declared fragment schemas are extracted (sorted, nothing extra)',
);

// ============================================================================
// Requested schema missing from the partial scan ⇒ FATAL.
// ============================================================================
$missingDiag = new CompileDiagnostics();
$missingMerger = new OpenApiEscapeHatchMerger($missingDiag, $root);
$missingMerger->merge(
    escapeHatchGraphDocument(),
    new OpenApiEscapeHatch([\SpsOaTest\EscapeHatch\PolymorphicThing::class], ['NonexistentSchema']),
);
assert_true($missingDiag->hasErrors(), 'a requested schema absent from the fragment scan ⇒ FATAL');
assert_true(str_contains($missingDiag->render(), 'NonexistentSchema'), 'the missing schema key is named');

// ============================================================================
// Graph ↔ fragment collision ⇒ FATAL (graph wins; fragment rejected).
// ============================================================================
$collideGraph = escapeHatchGraphDocument();
$collideGraph['components']['schemas']['ConcreteA'] = ['type' => 'object', 'properties' => ['graph' => ['type' => 'string']]];
$collideDiag = new CompileDiagnostics();
$collideMerger = new OpenApiEscapeHatchMerger($collideDiag, $root);
$collideMerged = $collideMerger->merge(
    $collideGraph,
    new OpenApiEscapeHatch([\SpsOaTest\EscapeHatch\PolymorphicThing::class], ['ConcreteA']),
);
assert_true($collideDiag->hasErrors(), 'a fragment schema colliding with a graph schema ⇒ FATAL');
assert_true(str_contains($collideDiag->render(), 'ConcreteA'), 'the colliding key is named');
// graph wins: the merged ConcreteA is the GRAPH's, not the fragment's.
assert_same(['type' => 'object', 'properties' => ['graph' => ['type' => 'string']]], $collideMerged['components']['schemas']['ConcreteA'], 'on collision the graph schema wins');

// ============================================================================
// Forbidden operation annotation (#[OA\Get]) ⇒ FATAL (annotation guard, shape guard as backstop).
// ============================================================================
$forbiddenDiag = new CompileDiagnostics();
$forbiddenMerger = new OpenApiEscapeHatchMerger($forbiddenDiag, $root);
$forbiddenMerger->merge(
    escapeHatchGraphDocument(),
    new OpenApiEscapeHatch([\SpsOaTest\EscapeHatch\ForbiddenOperationCarrier::class], []),
);
assert_true($forbiddenDiag->hasErrors(), 'a fragment with a forbidden operation annotation ⇒ FATAL');

// ============================================================================
// Duplicate fragment schema key across two targets ⇒ FATAL (no silent last-wins).
// ============================================================================
$dupDiag = new CompileDiagnostics();
$dupMerger = new OpenApiEscapeHatchMerger($dupDiag, $root);
$dupMerger->merge(
    escapeHatchGraphDocument(),
    new OpenApiEscapeHatch(
        [\SpsOaTest\EscapeHatch\CollisionFragmentA::class, \SpsOaTest\EscapeHatch\CollisionFragmentB::class],
        ['ClashingName'],
    ),
);
assert_true($dupDiag->hasErrors(), 'the same schema key from two scan targets ⇒ FATAL (provenance)');
assert_true(str_contains($dupDiag->render(), 'ClashingName'), 'the duplicated key is named');

// ============================================================================
// Same file reached via a class-string AND a path scans ONCE (resolved-file dedup).
// ============================================================================
$polyFile = (new \ReflectionClass(\SpsOaTest\EscapeHatch\PolymorphicThing::class))->getFileName();
$dedupDiag = new CompileDiagnostics();
$dedupMerger = new OpenApiEscapeHatchMerger($dedupDiag, $root);
$dedupMerged = $dedupMerger->merge(
    escapeHatchGraphDocument(),
    new OpenApiEscapeHatch(
        [\SpsOaTest\EscapeHatch\PolymorphicThing::class, $polyFile],
        ['PolymorphicThing', 'ConcreteA', 'ConcreteB'],
    ),
);
assert_true(!$dedupDiag->hasErrors(), 'class-string + path resolving to the same file ⇒ no duplicate-key error');
assert_same(
    ['ConcreteA', 'ConcreteB', 'PolymorphicThing'],
    array_keys($dedupMerged['components']['schemas']),
    'the shared file is scanned once (each schema present exactly once)',
);

// ============================================================================
// Allowed graph↔fragment refs resolve (the merged doc passes structural validation).
// ============================================================================
$validDiag = new CompileDiagnostics();
$validMerger = new OpenApiEscapeHatchMerger($validDiag, $root);
$validMerged = $validMerger->merge(
    escapeHatchGraphDocument(),
    new OpenApiEscapeHatch(
        [\SpsOaTest\EscapeHatch\PolymorphicThing::class],
        ['PolymorphicThing', 'ConcreteA', 'ConcreteB'],
    ),
);
$validatorDiag = new CompileDiagnostics();
(new OpenApiValidator($validatorDiag))->validate($validMerged);
assert_true(!$validatorDiag->hasErrors(), 'the merged fragment (local refs) passes OpenApiValidator');

// ============================================================================
// EXTERNAL $ref ⇒ FATAL (the validator would accept it, so the merger forbids it explicitly).
// ============================================================================
$extDiag = new CompileDiagnostics();
$extMerger = new OpenApiEscapeHatchMerger($extDiag, $root);
$extMerger->merge(
    escapeHatchGraphDocument(),
    new OpenApiEscapeHatch([\SpsOaTest\EscapeHatch\ExternalRefThing::class], ['ExternalRefThing']),
);
assert_true($extDiag->hasErrors(), 'an external $ref in a fragment ⇒ FATAL');
assert_true(str_contains($extDiag->render(), 'external'), 'the external-ref error is explicit');

// ============================================================================
// Deterministic merge: two identical runs produce identical documents.
// ============================================================================
$det1 = (new OpenApiEscapeHatchMerger(new CompileDiagnostics(), $root))->merge(
    escapeHatchGraphDocument(),
    new OpenApiEscapeHatch([\SpsOaTest\EscapeHatch\PolymorphicThing::class], ['PolymorphicThing', 'ConcreteA', 'ConcreteB']),
);
$det2 = (new OpenApiEscapeHatchMerger(new CompileDiagnostics(), $root))->merge(
    escapeHatchGraphDocument(),
    new OpenApiEscapeHatch([\SpsOaTest\EscapeHatch\PolymorphicThing::class], ['PolymorphicThing', 'ConcreteA', 'ConcreteB']),
);
assert_same($det1, $det2, 'the merge is deterministic (two runs produce identical documents)');

// ============================================================================
// No x-fqcn vendor key and no nullable:true anywhere in the merged document.
// ============================================================================
$hasXfqcn = false;
$hasNullableTrue = false;
array_walk_recursive($okMerged, static function (mixed $v, mixed $k) use (&$hasXfqcn, &$hasNullableTrue): void {
    if ($k === 'x-fqcn') {
        $hasXfqcn = true;
    }
    if ($k === 'nullable' && $v === true) {
        $hasNullableTrue = true;
    }
});
assert_true(!$hasXfqcn, 'no x-fqcn vendor key is published by the merge');
assert_true(!$hasNullableTrue, 'no legacy nullable:true is introduced by the merge');

// ============================================================================
// Whitelist FILTER: a fragment file may declare MORE schemas than requested. Only the declared
// schemaKeys are published — undeclared fragment schemas are dropped, not leaked. Regression for the
// latent bug where the merger added every #[OA\Schema] from a whitelisted file. PolymorphicFragment
// carries PolymorphicThing + ConcreteA + ConcreteB; we request ONLY ConcreteA (a leaf, no inbound refs)
// → the merged document must contain ConcreteA and neither of the other two.
// ============================================================================
$filterDiag = new CompileDiagnostics();
$filterMerger = new OpenApiEscapeHatchMerger($filterDiag, $root);
$filterMerged = $filterMerger->merge(
    escapeHatchGraphDocument(),
    new OpenApiEscapeHatch([\SpsOaTest\EscapeHatch\PolymorphicThing::class], ['ConcreteA']),
);
assert_true(!$filterDiag->hasErrors(), 'whitelist filter: a subset request is valid (no errors)');
assert_same(
    ['ConcreteA'],
    array_keys($filterMerged['components']['schemas']),
    'only the requested schema is published (undeclared fragment schemas are dropped, not leaked)',
);

echo "OpenApiEscapeHatchMerger passed\n";
