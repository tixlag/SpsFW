<?php

declare(strict_types=1);

use SpsFW\Core\Compile\OpenApi\ParityReport;

require_once dirname(__DIR__, 2) . '/bootstrap.php';

/**
 * Шаг 4 (M3): ParityReport compares the generated spec vs the legacy swagger-php openapi.yml AFTER round-trip
 * parse + normalization — never by raw text. Normalization CANONICALIZES nullable⇒3.1 union on both sides (it
 * is NOT dropped — that would hide a real contract difference), strips x-fqcn, sorts keys, drops empty
 * containers BUT keeps `security: []` (anonymous). What survives is the parity contract.
 */

$report = new ParityReport();

// --- normalize canonicalizes nullable⇒3.1 union (scalar), strips x-fqcn, sorts keys, drops empty containers ---
$raw = [
    'b' => ['nullable' => true, 'type' => 'string', 'x-fqcn' => 'Foo'],
    'a' => 1,
    'c' => [],
];
$norm = $report->normalize($raw);
assert_same(['a', 'b'], array_keys($norm), 'normalize sorts keys');
assert_true(!array_key_exists('nullable', $norm['b']), 'normalize consumes nullable (3.0 marker folded into the 3.1 type union)');
assert_same(['string', 'null'], $norm['b']['type'], 'nullable scalar is CANONICALIZED to type:[<type>,"null"], not dropped');
assert_true(!array_key_exists('x-fqcn', $norm['b']), 'normalize strips x-fqcn (emitter auxiliary)');
assert_true(!array_key_exists('c', $norm), 'normalize drops empty containers');

// --- nullable $ref ⇒ anyOf:[{$ref},{type:"null"}] (canonical 3.1 form, not dropped) ---
$refNorm = $report->normalize(['schema' => ['$ref' => '#/components/schemas/X', 'nullable' => true]]);
assert_same(['anyOf' => [['$ref' => '#/components/schemas/X'], ['type' => 'null']]], $refNorm['schema'], 'nullable $ref canonicalizes to anyOf (not silently dropped)');

// --- security:[] is PRESERVED (anonymous) — emptiness IS the contract, never collapsed to "absent/inherit" ---
$secNorm = $report->normalize(['security' => [], 'required' => []]);
assert_true(array_key_exists('security', $secNorm), 'security:[] is kept (anonymous = a real contract signal)');
assert_same([], $secNorm['security'], 'security stays an empty array');
assert_true(!array_key_exists('required', $secNorm), 'a non-significant empty container (required:[]) is still dropped');

// --- two docs that differ ONLY in dropped keys + key order + nullable REPRESENTATION ⇒ 0 divergences ---
// docA expresses nullability the swagger-php 3.0 way (nullable:true on a $ref); docB the 3.1 way (anyOf).
// Canonicalization makes them speak the same language ⇒ no spurious divergence.
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
$docB = [
    'components' => ['schemas' => ['X' => ['x-fqcn' => 'XDto', 'type' => 'object', 'properties' => ['n' => ['type' => 'string']]]]],
    'paths' => ['/x' => ['get' => ['responses' => ['200' => ['content' => ['application/json' => ['schema' => ['anyOf' => [['$ref' => '#/components/schemas/X'], ['type' => 'null']]]]], 'description' => 'ok']], 'operationId' => 'getX']]],
    'openapi' => '3.1.0',
];
$result = $report->compare($docA, $docB);
assert_same(0, $result['divergence_count'], 'docs differing only in order/cosmetic keys + nullable representation have ZERO parity divergences');

// --- a REAL nullability gap now SURFACES (generated nullable vs legacy non-nullable) — no longer hidden ---
$resNull = $report->compare(
    ['paths' => ['/a' => ['get' => ['responses' => ['200' => ['description' => 'ok', 'content' => ['application/json' => ['schema' => ['type' => ['string', 'null']]]]]]]]], 'components' => ['schemas' => []]],
    ['paths' => ['/a' => ['get' => ['responses' => ['200' => ['description' => 'ok', 'content' => ['application/json' => ['schema' => ['type' => 'string']]]]]]]], 'components' => ['schemas' => []]],
);
$nullGap = false;
foreach ($resNull['divergences_sample'] as $d) {
    if (str_contains($d['path'], 'type')) {
        $nullGap = true;
    }
}
assert_true($nullGap, 'a nullable-vs-non-nullable type difference is a real divergence (not hidden by dropping nullable)');

// --- a security gap now SURFACES (generated anonymous vs legacy authenticated) ---
$resSec = $report->compare(
    ['paths' => ['/a' => ['get' => ['security' => [], 'responses' => ['200' => ['description' => 'ok']]]]], 'components' => ['schemas' => []]],
    ['paths' => ['/a' => ['get' => ['security' => [['bearerAuth' => []]], 'responses' => ['200' => ['description' => 'ok']]]]], 'components' => ['schemas' => []]],
);
$secGap = false;
foreach ($resSec['divergences_sample'] as $d) {
    if (str_contains($d['path'], 'security')) {
        $secGap = true;
    }
}
assert_true($secGap, 'an anonymous-vs-authenticated security difference is a real divergence (security:[] is preserved, not dropped)');

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
