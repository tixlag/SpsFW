<?php

declare(strict_types=1);

namespace SpsFW\Core\Compile\Route;

/**
 * Strict parity check for the M5 rule-graph producer switch (plan §15, Step 7).
 *
 * The route-cache `dtos[].rules` graph produced by the metadata source ({@see \SpsFW\Core\Compile\Introspection\DtoSchemaBuilder})
 * must be byte-identical to the legacy OA source ({@see \SpsFW\Core\Router\Router::extractValidationRules()}) before
 * the metadata source may publish. The DECISION is a single strict `===` comparison (PHP `===` on arrays is true iff
 * the same key/value pairs sit in the SAME order with identical types, recursively — exactly what var_export
 * byte-parity in the route cache requires). This class does NOT change that decision; it LOCALIZES the first
 * divergence so the blocking {@see \SpsFW\Core\Compile\CompileDiagnostics} error names the property path and both
 * sides, instead of a bare "graphs differ".
 *
 * Pure and stateless: given two rule-graph arrays it returns null (identical) or a descriptor of the first divergence.
 */
final class RuleGraphParity
{
    /**
     * @param array<string, array<string, mixed>> $legacy   the OA-sourced graph (Router::extractValidationRules)
     * @param array<string, array<string, mixed>> $metadata the DtoSchemaBuilder-sourced graph
     * @return ?array{path: string, kind: string, legacy: mixed, metadata: mixed} null when strictly identical;
     *         otherwise the first divergence (property path, divergence kind, both sides).
     */
    public static function compare(array $legacy, array $metadata): ?array
    {
        if ($legacy === $metadata) {
            return null;
        }

        return self::firstDifference($legacy, $metadata, '');
    }

    /**
     * Walk both structures in parallel and return the first point of divergence. Recurses into the nested rule maps
     * (a property's rule map, its `nested_rules`, …) so the reported path is precise down to the leaf.
     *
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     * @return ?array{path: string, kind: string, legacy: mixed, metadata: mixed}
     */
    private static function firstDifference(array $a, array $b, string $path): ?array
    {
        // Keys present in $a (legacy): missing in $b, or a diverging value.
        foreach ($a as $key => $aValue) {
            $here = $path === '' ? (string) $key : $path . '.' . $key;
            if (!array_key_exists($key, $b)) {
                return ['path' => $here, 'kind' => 'present-in-legacy-only', 'legacy' => $aValue, 'metadata' => null];
            }
            $bValue = $b[$key];
            if ($aValue === $bValue) {
                continue;
            }
            // Both arrays (a nested rule map or nested_rules collection) → recurse to pinpoint the leaf divergence.
            if (is_array($aValue) && is_array($bValue)) {
                $sub = self::firstDifference($aValue, $bValue, $here);
                if ($sub !== null) {
                    return $sub;
                }
                // Same keys + values but DIFFERENT ORDER — `===` is order-sensitive, so this is a real parity break.
                return ['path' => $here, 'kind' => 'order-differs', 'legacy' => array_keys($aValue), 'metadata' => array_keys($bValue)];
            }
            return ['path' => $here, 'kind' => 'value-differs', 'legacy' => $aValue, 'metadata' => $bValue];
        }

        // Keys present ONLY in $b (metadata): an extra property/field the legacy graph does not carry.
        foreach ($b as $key => $bValue) {
            if (!array_key_exists($key, $a)) {
                $here = $path === '' ? (string) $key : $path . '.' . $key;
                return ['path' => $here, 'kind' => 'present-in-metadata-only', 'legacy' => null, 'metadata' => $bValue];
            }
        }

        // Same keys + values but the TOP-LEVEL key order differs (rare at the outer graph level, but `===` catches it).
        return ['path' => $path === '' ? '(top-level)' : $path, 'kind' => 'order-differs', 'legacy' => array_keys($a), 'metadata' => array_keys($b)];
    }
}
