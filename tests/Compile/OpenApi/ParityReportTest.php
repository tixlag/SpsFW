<?php

declare(strict_types=1);

use SpsFW\Core\Compile\OpenApi\ParityReport;

require_once dirname(__DIR__, 2) . '/bootstrap.php';

/**
 * Шаг 4 (M3): ParityReport compares the generated spec vs the legacy swagger-php openapi.yml AFTER round-trip
 * parse + normalization — never by raw text. Normalization drops cosmetic auxiliaries (x-fqcn, nullable,
 * empty containers) and sorts keys; what survives is the parity contract.
 */

$report = new ParityReport();

// --- normalize drops x-fqcn + nullable, sorts keys, drops empty containers ---
$raw = [
    'b' => ['nullable' => true, 'type' => 'string', 'x-fqcn' => 'Foo'],
    'a' => 1,
    'c' => [],
];
$norm = $report->normalize($raw);
assert_same(['a', 'b'], array_keys($norm), 'normalize sorts keys');
assert_true(!array_key_exists('nullable', $norm['b']), 'normalize drops nullable (3.0/3.1 cosmetic)');
assert_true(!array_key_exists('x-fqcn', $norm['b']), 'normalize drops x-fqcn (emitter auxiliary)');
assert_true(!array_key_exists('c', $norm), 'normalize drops empty containers');

// --- two docs that differ ONLY in dropped keys + key order ⇒ 0 divergences (normalization equivalence) ---
$docA = [
    'openapi' => '3.1.0',
    'paths' => [
        '/x' => [
            'get' => [
                'operationId' => 'getX',
                'responses' => ['200' => ['description' => 'ok', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/X', 'nullable' => true]]]]],
            ],
        ],
    ],
    'components' => ['schemas' => ['X' => ['type' => 'object', 'properties' => ['n' => ['type' => 'string']], 'x-fqcn' => 'XDto']]],
];
// docB: same contract, different key order, cosmetic nullable present/absent, x-fqcn absent.
$docB = [
    'components' => ['schemas' => ['X' => ['x-fqcn' => 'XDto', 'type' => 'object', 'properties' => ['n' => ['type' => 'string']]]]],
    'paths' => ['/x' => ['get' => ['responses' => ['200' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/X']]], 'description' => 'ok']], 'operationId' => 'getX']]],
    'openapi' => '3.1.0',
];
$result = $report->compare($docA, $docB);
assert_same(0, $result['divergence_count'], 'docs differing only in order/cosmetic keys have ZERO parity divergences');

// --- a real divergence surfaces: legacy declares a 401 the generated spec lacks ⇒ missing-in-generated ---
$generated = [
    'openapi' => '3.1.0',
    'paths' => ['/a' => ['get' => ['operationId' => 'getA', 'responses' => ['200' => ['description' => 'ok']]]]],
    'components' => ['schemas' => []],
];
$legacy = [
    'openapi' => '3.1.0',
    'paths' => ['/a' => ['get' => ['operationId' => 'getA', 'responses' => [
        '200' => ['description' => 'ok'],
        '401' => ['description' => 'Unauthorized'],
    ]]]],
    'components' => ['schemas' => []],
];
$res2 = $report->compare($generated, $legacy);
assert_true($res2['divergence_count'] >= 1, 'a missing 401 response is a real divergence');
assert_same(1, $res2['generated']['operations'], 'operation count reads from the paths');
$has401Gap = false;
foreach ($res2['divergences_sample'] as $d) {
    if ($d['category'] === 'missing-in-generated' && str_contains($d['path'], '401')) {
        $has401Gap = true;
    }
}
assert_true($has401Gap, 'the missing 401 is categorized as missing-in-generated at the right pointer');

// --- value-mismatch: different operationId ---
$res3 = $report->compare(
    ['paths' => ['/a' => ['get' => ['operationId' => 'A']]], 'components' => ['schemas' => []]],
    ['paths' => ['/a' => ['get' => ['operationId' => 'B']]], 'components' => ['schemas' => []]],
);
$mismatch = false;
foreach ($res3['divergences_sample'] as $d) {
    if ($d['category'] === 'value-mismatch' && str_contains($d['path'], 'operationId')) {
        $mismatch = true;
    }
}
assert_true($mismatch, 'a different operationId is a value-mismatch divergence');

// --- schema-only-in-legacy surfaces non-promoted ctor field divergences (prereq 4) ---
$res4 = $report->compare(
    ['paths' => [], 'components' => ['schemas' => ['A' => ['type' => 'object']]]],
    ['paths' => [], 'components' => ['schemas' => ['A' => ['type' => 'object'], 'B' => ['type' => 'object']]]],
);
assert_same(['B'], $res4['schemas_only_in_legacy'], 'a schema present only in legacy is reported for M7 follow-up');

echo "ParityReport passed\n";
