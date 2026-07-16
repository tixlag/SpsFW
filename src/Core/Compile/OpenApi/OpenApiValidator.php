<?php

declare(strict_types=1);

namespace SpsFW\Core\Compile\OpenApi;

use SpsFW\Core\Compile\CompileDiagnostics;

/**
 * Structural validator for an already-built OpenAPI 3.1 document array (Step 4 correctness fix).
 *
 * A YAML round-trip only proves the document serializes — it does NOT prove the spec is sound. The emitter
 * detects structural problems it encounters while building (duplicate METHOD:path, schema-name collisions,
 * cyclic DTO graphs); this validator closes the gap by checking the finished array INDEPENDENTLY, so a broken
 * artifact is caught before publication. Every breach becomes a FATAL {@see CompileDiagnostics::error()}, so
 * {@see CompileDiagnostics::throwOnErrors()} blocks the publish (plan Шаг 4 / §11 — validate before publish).
 *
 * Checks:
 *  - top-level shape — `openapi`, `info.{title,version}`, `paths`;
 *  - every operation (path × HTTP method) declares a non-empty `responses`;
 *  - every HTTP-method key under a path item is a recognized verb (get/post/…/trace);
 *  - every internal `$ref` resolves to a node that exists in the document;
 *  - every `security` scheme referenced by an operation exists in `components.securitySchemes`.
 *
 * Pure read of the array — it never recompiles — so it composes with the array-first emitter:
 * `emit() → validate($doc) → publish (gated by throwOnErrors)`.
 */
final class OpenApiValidator
{
    /** Recognized OpenAPI HTTP method verbs (path-item operation keys). */
    private const HTTP_METHODS = ['get', 'post', 'put', 'patch', 'delete', 'head', 'options', 'trace'];

    /** Path-item object keys that are NOT operations. */
    private const PATH_ITEM_META_KEYS = ['summary', 'description', 'servers', 'parameters'];

    public function __construct(
        private readonly CompileDiagnostics $diagnostics,
    ) {
    }

    /**
     * Validate the document, recording a FATAL diagnostic per structural breach.
     *
     * @param array<string, mixed> $document
     */
    public function validate(array $document): void
    {
        $this->assertTopLevel($document);
        $this->validateRefs($document);
        $this->validatePaths($document, $this->securitySchemes($document));
    }

    /**
     * @param array<string, mixed> $d
     */
    private function assertTopLevel(array $d): void
    {
        if (!isset($d['openapi']) || !is_string($d['openapi'])) {
            $this->diag(null, null, 'document', 'document is missing the required `openapi` version string');
        }
        $info = $d['info'] ?? null;
        if (!is_array($info) || !array_key_exists('title', $info) || !array_key_exists('version', $info)) {
            $this->diag(null, null, 'info', 'document is missing required info.title / info.version');
        }
        if (!isset($d['paths']) || !is_array($d['paths'])) {
            $this->diag(null, null, 'paths', 'document is missing the required `paths` object');
        }
    }

    /**
     * @param array<string, mixed> $d
     * @param array<string, mixed> $schemes
     */
    private function validatePaths(array $d, array $schemes): void
    {
        foreach (($d['paths'] ?? []) as $path => $item) {
            if (!is_array($item)) {
                continue;
            }
            foreach ($item as $method => $op) {
                $methodLower = strtolower((string) $method);
                if (in_array($methodLower, self::PATH_ITEM_META_KEYS, true) || $method === '$ref') {
                    continue;
                }
                if (!in_array($methodLower, self::HTTP_METHODS, true)) {
                    $this->diag(null, null, 'paths', sprintf('unrecognized HTTP method %s under path %s', $method, $path));
                    continue;
                }
                if (!is_array($op)) {
                    continue;
                }
                if (!isset($op['responses']) || !is_array($op['responses']) || $op['responses'] === []) {
                    $this->diag(null, $method, 'responses', sprintf('operation %s %s declares no responses', strtoupper($methodLower), $path));
                }
                $this->validateSecurity($op, $schemes, strtoupper($methodLower) . ' ' . $path);
            }
        }
    }

    /**
     * @param array<string, mixed> $op
     * @param array<string, mixed> $schemes
     */
    private function validateSecurity(array $op, array $schemes, string $label): void
    {
        $security = $op['security'] ?? null;
        if (!is_array($security)) {
            return;
        }
        foreach ($security as $requirement) {
            if (!is_array($requirement)) {
                continue;
            }
            foreach (array_keys($requirement) as $scheme) {
                if (!array_key_exists((string) $scheme, $schemes)) {
                    $this->diag(null, null, 'security', sprintf('operation %s references undefined securityScheme %s', $label, $scheme));
                }
            }
        }
    }

    /**
     * Collect every `$ref` in the document and assert each internal one resolves.
     *
     * @param array<string, mixed> $d
     */
    private function validateRefs(array $d): void
    {
        $refs = [];
        $this->collectRefs($d, $refs);
        foreach (array_unique($refs) as $ref) {
            if (!$this->refResolves($d, $ref)) {
                $this->diag(null, null, '$ref', sprintf('unresolvable $ref %s', $ref));
            }
        }
    }

    /**
     * @param mixed $node
     * @param list<string> $refs
     */
    private function collectRefs(mixed $node, array &$refs): void
    {
        if (!is_array($node)) {
            return;
        }
        if (isset($node['$ref']) && is_string($node['$ref'])) {
            $refs[] = $node['$ref'];
        }
        foreach ($node as $v) {
            $this->collectRefs($v, $refs);
        }
    }

    /**
     * Resolve an internal JSON pointer (`#/a/b/c`) against the document; external/URL refs are out of scope.
     *
     * @param array<string, mixed> $d
     */
    private function refResolves(array $d, string $ref): bool
    {
        if ($ref !== '#' && !str_starts_with($ref, '#/')) {
            return true; // external/URL ref — not a structural concern for the local artifact.
        }
        $pointer = $ref === '#' ? '' : substr($ref, 2);
        if ($pointer === '') {
            return true;
        }
        $current = $d;
        foreach (explode('/', $pointer) as $segment) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return false;
            }
            /** @var mixed $current */
            $current = $current[$segment];
        }
        return true;
    }

    /**
     * @param array<string, mixed> $d
     * @return array<string, mixed>
     */
    private function securitySchemes(array $d): array
    {
        $schemes = $d['components']['securitySchemes'] ?? null;

        return is_array($schemes) ? $schemes : [];
    }

    private function diag(?string $controller, ?string $method, string $field, string $cause): void
    {
        $this->diagnostics->error(
            controller: $controller,
            method: $method,
            dto: null,
            field: $field,
            cause: $cause,
            fix: null,
        );
    }
}
