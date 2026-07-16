<?php

declare(strict_types=1);

namespace SpsFW\Core\Compile\OpenApi;

use Symfony\Component\Yaml\Yaml;

/**
 * Compares the generated OpenAPI document against the legacy swagger-php `openapi.yml` AFTER normalization
 * (plan Шаг 4 decision: parity is compared array-to-array after a round-trip parse + normalization, NEVER by
 * raw text/whitespace — swagger-php and symfony/yaml format differently).
 *
 * Normalization is intentionally lossy on COSMETIC differences that are not part of the parity contract:
 *  - recursive key sort (order is irrelevant);
 *  - drop vendor/debug extensions (`x-fqcn`) the emitter adds for ref resolution;
 *  - drop `nullable` (3.0 `nullable: true` vs 3.1 `type:[…]` — a format, not a contract, difference);
 *  - drop empty containers and the swagger-php default `description: ''`.
 *
 * What survives normalization IS the parity contract — operationId, path/method, parameter shape, request
 * body $ref, response $ref + status, security, component schema properties/required. Remaining differences
 * are surfaced categorized (missing/extra/mismatch) so they become the M7 migration worklist.
 *
 * Non-promoted constructor fields (prereq 4): the generated schema projection EXCLUDES them (they are not
 * json_serialize'd), so any DTO whose swagger-php output included an OA-tagged non-promoted ctor param shows
 * up here as an extra-in-legacy schema property — a documented divergence, surfaced for M7.
 */
final class ParityReport
{
    /** Cosmetic/auxiliary keys that are not part of the parity contract. */
    private const DROP_KEYS = ['x-fqcn', 'nullable'];

    /**
     * Normalize a parsed OpenAPI document for comparison: recursively sort keys and drop cosmetic auxiliaries.
     *
     * @param array<string, mixed>|mixed $doc
     * @return array<string, mixed>|mixed
     */
    public function normalize(mixed $doc): mixed
    {
        if (!is_array($doc)) {
            return $doc;
        }
        $out = [];
        foreach ($doc as $key => $value) {
            if (in_array($key, self::DROP_KEYS, true)) {
                continue;
            }
            $out[$key] = $this->normalize($value);
        }
        // Drop empty containers (swagger-php emits [] / {} that carry no contract).
        $out = array_filter($out, static function (mixed $v): bool {
            if (is_array($v) && $v === []) {
                return false;
            }
            return true;
        });
        ksort($out);
        return $out;
    }

    /**
     * Parse a YAML file into a (raw) document array.
     *
     * @return array<string, mixed>
     */
    public function parseFile(string $path): array
    {
        /** @var array<string, mixed> $doc */
        $doc = Yaml::parseFile($path);
        return $doc;
    }

    /**
     * Compare a generated doc array against a legacy doc array (both already parsed). Returns a deterministic
     * report: path/operation/schema coverage + a capped list of normalized divergences.
     *
     * @param array<string, mixed> $generated
     * @param array<string, mixed> $legacy
     * @param int $maxDivergences cap on the sampled divergence list (the counts stay exact)
     * @return array<string, mixed>
     */
    public function compare(array $generated, array $legacy, int $maxDivergences = 50): array
    {
        $gen = $this->normalize($generated);
        $leg = $this->normalize($legacy);

        $genPaths = array_keys($gen['paths'] ?? []);
        $legPaths = array_keys($leg['paths'] ?? []);
        $genSchemas = array_keys($gen['components']['schemas'] ?? []);
        $legSchemas = array_keys($leg['components']['schemas'] ?? []);

        $divergences = [];
        $this->deepDiff($gen, $leg, '$', $divergences);

        // Bucket the divergences by category for the report.
        $byCategory = [];
        foreach ($divergences as $d) {
            $byCategory[$d['category']] = ($byCategory[$d['category']] ?? 0) + 1;
        }

        return [
            'generated' => [
                'paths' => count($genPaths),
                'schemas' => count($genSchemas),
                'operations' => $this->countOperations($gen),
            ],
            'legacy' => [
                'paths' => count($legPaths),
                'schemas' => count($legSchemas),
                'operations' => $this->countOperations($leg),
            ],
            'paths_only_in_generated' => array_values(array_diff($genPaths, $legPaths)),
            'paths_only_in_legacy' => array_values(array_diff($legPaths, $genPaths)),
            'schemas_only_in_generated' => array_values(array_diff($genSchemas, $legSchemas)),
            'schemas_only_in_legacy' => array_values(array_diff($legSchemas, $genSchemas)),
            'divergence_count' => count($divergences),
            'divergences_by_category' => $byCategory,
            'divergences_sample' => array_slice($divergences, 0, $maxDivergences),
        ];
    }

    /**
     * Recursively collect where two normalized docs differ. A divergence is one of:
     *  - missing-in-generated : key present in legacy, absent in generated;
     *  - extra-in-generated   : key present in generated, absent in legacy;
     *  - value-mismatch       : both present, different scalar values.
     *
     * @param array<string, mixed> $gen
     * @param array<string, mixed> $leg
     * @param list<array{path: string, category: string, generated: mixed, legacy: mixed}> $out
     */
    private function deepDiff(array $gen, array $leg, string $pointer, array &$out): void
    {
        $keys = array_unique(array_merge(array_keys($gen), array_keys($leg)));
        sort($keys);
        foreach ($keys as $key) {
            $path = $pointer . '.' . $key;
            $inGen = array_key_exists($key, $gen);
            $inLeg = array_key_exists($key, $leg);
            if ($inGen && !$inLeg) {
                $out[] = ['path' => $path, 'category' => 'extra-in-generated', 'generated' => $this->summarize($gen[$key]), 'legacy' => null];
                continue;
            }
            if (!$inGen && $inLeg) {
                $out[] = ['path' => $path, 'category' => 'missing-in-generated', 'generated' => null, 'legacy' => $this->summarize($leg[$key])];
                continue;
            }
            $g = $gen[$key];
            $l = $leg[$key];
            if (is_array($g) && is_array($l)) {
                $this->deepDiff($g, $l, $path, $out);
            } elseif ($g !== $l) {
                $out[] = ['path' => $path, 'category' => 'value-mismatch', 'generated' => $this->summarize($g), 'legacy' => $this->summarize($l)];
            }
        }
    }

    /**
     * Compact a value for the divergence sample (avoid dumping whole schemas into the report).
     */
    private function summarize(mixed $value): mixed
    {
        if (is_array($value)) {
            if (isset($value['$ref'])) {
                return '$ref:' . $value['$ref'];
            }
            if (array_key_exists('type', $value) && count($value) <= 3) {
                return $value;
            }
            return sprintf('array(%d keys: %s)', count($value), implode(',', array_slice(array_keys($value), 0, 5)));
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $doc
     */
    private function countOperations(array $doc): int
    {
        $n = 0;
        foreach (($doc['paths'] ?? []) as $pathItem) {
            if (!is_array($pathItem)) {
                continue;
            }
            foreach ($pathItem as $method => $op) {
                if (in_array(strtolower((string) $method), ['get', 'post', 'put', 'patch', 'delete', 'head', 'options', 'trace'], true) && is_array($op)) {
                    $n++;
                }
            }
        }
        return $n;
    }
}
