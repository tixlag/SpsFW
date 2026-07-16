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
 *  - strip vendor/debug extensions (`x-fqcn`) — the emitter's FQCN⇒name registry is internal and never
 *    published, but a stray legacy `x-fqcn` is still removed so it cannot perturb the comparison;
 *  - CANONICALIZE nullability to OpenAPI 3.1 / JSON Schema 2020-12 semantics: a 3.0 `nullable: true` is folded
 *    into a `type: […, "null"]` union (scalar) or `anyOf: [{$ref}, {type: "null"}]` (ref) on BOTH sides. The
 *    marker is NOT dropped silently — that would hide a real contract difference (one side nullable, the other
 *    not). After canonicalization both sides speak 3.1, so a nullability gap surfaces as a divergence.
 *  - drop empty containers EXCEPT semantically significant ones (`security: []` = anonymous) and the
 *    swagger-php default `description: ''`.
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
    private const DROP_KEYS = ['x-fqcn'];

    /** Empty containers whose EMPTINESS is the contract (kept; never collapsed to "absent / inherit"). */
    private const KEEP_EMPTY_KEYS = ['security'];

    /**
     * Normalize a parsed OpenAPI document for comparison: canonicalize nullability, recursively sort keys, drop
     * cosmetic auxiliaries, and drop empty containers (except the semantically significant ones).
     *
     * The `$inSecurity` flag tracks descent into a `security` subtree. Inside it, empty containers are NEVER
     * dropped: `security: []` (anonymous) and a requirement's empty scopes (`bearerAuth: []`) are both part of
     * the contract — dropping the scopes would collapse `[[bearerAuth:[]]]` (authenticated) down to `[]`
     * (anonymous) and hide the difference. Everywhere else, cosmetic empties (`required: []`, `properties: {}` …)
     * are dropped as before.
     *
     * @param array<string, mixed>|mixed $doc
     * @return array<string, mixed>|mixed
     */
    public function normalize(mixed $doc, bool $inSecurity = false): mixed
    {
        if (!is_array($doc)) {
            return $doc;
        }
        // Canonicalize this node's own nullability (3.0 nullable:true ⇒ 3.1 type union) BEFORE recursing, so the
        // nullable marker is consumed (not dropped) and a real nullability gap stays visible downstream.
        $doc = $this->canonicalizeNullability($doc);

        $out = [];
        foreach ($doc as $key => $value) {
            if (in_array($key, self::DROP_KEYS, true)) {
                continue;
            }
            // Descending into (or through) a security subtree preserves its empty containers.
            $childInSecurity = $inSecurity || $key === 'security';
            $out[$key] = $this->normalize($value, $childInSecurity);
        }
        // Drop empty containers (swagger-php emits [] / {} that carry no contract), but KEEP the ones whose
        // emptiness IS the contract — `security: []` (anonymous) and any empty container inside a security
        // subtree (e.g. `bearerAuth: []` scopes) must not collapse into "absent / inherit".
        $out = array_filter($out, static function (mixed $v, mixed $k) use ($inSecurity): bool {
            if (is_array($v) && $v === [] && !$inSecurity && !in_array($k, self::KEEP_EMPTY_KEYS, true)) {
                return false;
            }
            return true;
        }, ARRAY_FILTER_USE_BOTH);
        ksort($out);
        return $out;
    }

    /**
     * Fold a 3.0 `nullable: true` into OpenAPI 3.1 / JSON Schema 2020-12 nullability, matching what the
     * emitter now produces: scalar ⇒ `type: [<type>, "null"]`; `$ref` ⇒ `anyOf: [{$ref}, {type: "null"}]`;
     * typeless ⇒ null already permitted, so the marker is a no-op and just removed. `nullable: false` is removed
     * (null simply not in the type). Applied symmetrically to both docs, this makes a nullability CONTRACT
     * difference (nullable on one side, not the other) surface as a real divergence instead of vanishing.
     *
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function canonicalizeNullability(array $node): array
    {
        if (!array_key_exists('nullable', $node)) {
            return $node;
        }
        $nullable = $node['nullable'];
        unset($node['nullable']);
        if ($nullable !== true) {
            return $node; // nullable:false ⇒ null not in the type; nothing to add.
        }
        if (array_key_exists('type', $node)) {
            $type = $node['type'];
            $types = is_array($type) ? $type : [$type];
            if (!in_array('null', $types, true)) {
                $types[] = 'null';
            }
            $node['type'] = array_values($types);
            return $node;
        }
        if (array_key_exists('$ref', $node)) {
            $ref = ['$ref' => $node['$ref']];
            unset($node['$ref']);
            $variant = $node === [] ? $ref : ($ref + $node);

            return ['anyOf' => [$variant, ['type' => 'null']]];
        }
        // Typeless schema ⇒ null is already permitted (no type constraint); nullable:true is a no-op.
        return $node;
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
