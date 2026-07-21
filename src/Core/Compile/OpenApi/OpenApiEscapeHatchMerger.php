<?php

declare(strict_types=1);

namespace SpsFW\Core\Compile\OpenApi;

use OpenApi\Analysis;
use OpenApi\Generator;
use OpenApi\Loggers\DefaultLogger;
use SpsFW\Core\Compile\CompileDiagnostics;
use Symfony\Component\Yaml\Yaml;

/**
 * Merges the narrow OA escape hatch into the metadata-graph OpenAPI document (Step 8 / M6, plan §13).
 *
 * Invoked by the {@see \SpsFW\Core\Compile\Coordinator} ONLY under {@see OpenApiSource::Metadata}: the graph
 * document (built ONCE by {@see OpenApiEmitter}) is merged with `components.schemas.*` fragments extracted from
 * an EXPLICIT swagger-php whitelist ({@see OpenApiEscapeHatch}). The hatch is deliberately narrow — it may
 * contribute SCHEMA COMPONENTS ONLY — so genuinely underivable schemas (polymorphism oneOf/anyOf/allOf +
 * discriminator, exotic formats) can be declared with real `#[OA\Schema]` on an explicit carrier without
 * re-introducing OA as a source of truth for routing/security/request-validation.
 *
 * SCANNING STRATEGY — provenance preserved (no silent fragment↔fragment last-wins): each RESOLVED scan target
 * file is scanned SEPARATELY (the same file reached via a class-string AND a path scans once — resolved files
 * are deduplicated and deterministically sorted, the same sorted list the Fingerprinter hashes). A schema key
 * produced by more than one target → FATAL (ambiguous source). This per-target scan is chosen over a combined
 * swagger-php run precisely because a combined run can silently merge/dedupe schemas and lose provenance.
 *
 * TWO GUARDS enforce "schemas only":
 *  1. {@see EscapeHatchAnalysisGuard} — an annotation-level allowlist processor inside the schema-preserving
 *     pipeline. It inspects the raw {@see Analysis} and FATALs any non-schema annotation (Operation/PathItem/
 *     Get/Post/…/Parameter/RequestBody/Response/SecurityScheme/…) — naming the file/class + type. This is
 *     essential because the pipeline drops path processors, so a forbidden `#[OA\Get]` would vanish from the
 *     generated doc and be missed by a shape-only check.
 *  2. An exact-shape check on each partial doc — only `openapi`/`info`/`components.schemas` are permitted;
 *     `paths` must be absent or empty; any other root section (`webhooks`/`security`/`tags`/`servers`/…), and
 *     ANY `components` key other than `schemas`, is a FATAL.
 *
 * Plus: a requested schema key missing from the partial → FATAL; ONLY the declared `schemaKeys` are published —
 * any other #[OA\Schema] the fragments declare is dropped before provenance/merge; a graph↔fragment key
 * collision → FATAL (graph wins); an EXTERNAL `$ref` (http/https/file/relative) in a fragment → FATAL (the
 * OpenApiValidator treats
 * external refs as resolved, so this narrow hatch forbids them — only local `#/components/schemas/…` is
 * allowed). A scan target that is unresolvable, or a file OUTSIDE projectRoot, is a FATAL (no absolute
 * deploy-path fallback). Diagnostics flow into the shared collector that blocks publication on any ERROR.
 */
final class OpenApiEscapeHatchMerger
{
    /** Root sections a fragment partial is allowed to carry. */
    private const ALLOWED_ROOT_KEYS = ['openapi', 'info', 'components', 'paths'];

    private const ALLOWED_COMPONENT_KEYS = ['schemas'];

    private ?Generator $generator = null;

    public function __construct(
        private readonly CompileDiagnostics $diagnostics,
        private readonly string $projectRoot,
    ) {
    }

    /**
     * Merge the hatch's schema fragments into a COPY of the graph document. On any FATAL the document is
     * returned UNCHANGED (errors already block publication upstream); the merge never mutates the input.
     *
     * @param array<string, mixed> $document the graph document from {@see OpenApiEmitter::emit()}
     * @return array<string, mixed> the merged document (or the input on any error)
     */
    public function merge(array $document, OpenApiEscapeHatch $hatch): array
    {
        if ($hatch->isEmpty()) {
            return $document; // no-op — the common N case.
        }

        $fragmentSchemas = $this->extractFragmentSchemas($hatch);

        $graphSchemas = $document['components']['schemas'] ?? [];
        foreach ($hatch->schemaKeys as $key) {
            if (!isset($fragmentSchemas[$key])) {
                continue; // "requested schema missing" already recorded in extract().
            }
            if (array_key_exists($key, $graphSchemas)) {
                $this->diagnostics->error(
                    controller: null,
                    method: null,
                    dto: null,
                    field: 'escape_hatch',
                    cause: sprintf(
                        'escape-hatch schema %s collides with a schema the metadata graph already defines; '
                        . 'the graph wins and the fragment is rejected. Remove the fragment or rename it.',
                        $key,
                    ),
                    fix: 'rename the fragment schema (#[OA\Schema(schema: …)]) so it does not collide with a graph DTO',
                );
            }
        }

        if ($this->diagnostics->hasErrors()) {
            return $document; // do not mutate on error.
        }

        $this->assertNoExternalRefs($fragmentSchemas);
        if ($this->diagnostics->hasErrors()) {
            return $document;
        }

        $merged = $document;
        foreach ($fragmentSchemas as $key => $schema) {
            // Skip any key the graph already owns (defensive — collision already fatal above, so this is a
            // no-op in practice, but it keeps the merge idempotent if diagnostics are ever tolerated).
            if (array_key_exists($key, $merged['components']['schemas'] ?? [])) {
                continue;
            }
            $merged['components']['schemas'][$key] = $schema;
        }
        ksort($merged['components']['schemas']); // deterministic — matches the emitter's own sort.

        return $merged;
    }

    /**
     * Resolve + dedup + sort the scan-target files, scan each separately, enforce the two shape guards, and
     * return the surviving fragment schemas keyed by component name. Only keys in {@see OpenApiEscapeHatch::
     * $schemaKeys} survive — every other #[OA\Schema] the fragments declare is dropped before provenance/merge
     * (a whitelisted file may legitimately carry undeclared schemas; they are simply not published). Records
     * FATATs for unresolvable targets, out-of-root files, forbidden annotations (via the guard), bad partial
     * shape, cross-target key duplication (of REQUESTED keys), and requested-but-missing keys.
     *
     * @return array<string, array<string, mixed>>
     */
    private function extractFragmentSchemas(OpenApiEscapeHatch $hatch): array
    {
        $files = $this->resolveFiles($hatch);

        // Only the declared schema keys ($hatch->schemaKeys) are EVER published. Filter the partial schemas
        // down to those keys BEFORE provenance/merge so an undeclared #[OA\Schema] in a whitelisted file is
        // dropped silently rather than leaked into the merged document.
        $requested = array_fill_keys($hatch->schemaKeys, true);

        $keyToSource = [];
        $fragmentSchemas = [];
        foreach ($files as $absoluteFile) {
            $partial = $this->scanFile($absoluteFile);
            if ($this->diagnostics->hasErrors()) {
                // The guard/scan already recorded the fatal(s) for this file; its schemas are not trusted.
                continue;
            }
            $this->assertPartialShape($partial, $absoluteFile);
            $schemas = $partial['components']['schemas'] ?? [];
            if (!is_array($schemas) || $schemas === []) {
                $this->diagnostics->error(
                    controller: null,
                    method: null,
                    dto: null,
                    field: 'escape_hatch',
                    cause: sprintf('escape-hatch scan target %s produced no components.schemas', $absoluteFile),
                    fix: 'add a #[OA\Schema] declaration to the fragment, or remove the target from config/openapi_escape_hatch.php',
                );
                continue;
            }
            foreach ($schemas as $key => $schema) {
                if (!isset($requested[$key])) {
                    continue; // undeclared schema — fragments publish only requested keys.
                }
                if (isset($keyToSource[$key])) {
                    $this->diagnostics->error(
                        controller: null,
                        method: null,
                        dto: null,
                        field: 'escape_hatch',
                        cause: sprintf(
                            'duplicate escape-hatch schema key %s is produced by two scan targets (%s and %s); '
                            . 'a requested schema must have exactly one source.',
                            $key,
                            $keyToSource[$key],
                            $absoluteFile,
                        ),
                        fix: 'disambiguate the fragment schema names so each is declared in exactly one target',
                    );
                    continue;
                }
                $keyToSource[$key] = $absoluteFile;
                $fragmentSchemas[$key] = $schema;
            }
        }

        foreach ($hatch->schemaKeys as $key) {
            if (!isset($fragmentSchemas[$key])) {
                $this->diagnostics->error(
                    controller: null,
                    method: null,
                    dto: null,
                    field: 'escape_hatch',
                    cause: sprintf(
                        'requested escape-hatch schema %s is not produced by the fragment scan',
                        $key,
                    ),
                    fix: 'declare the schema with #[OA\Schema(schema: …)] on a whitelisted carrier, or drop it from config/openapi_escape_hatch.php schemas',
                );
            }
        }

        return $fragmentSchemas;
    }

    /**
     * Resolve scan targets to concrete files, dedup (same file via FQCN and via path scans once), sort
     * deterministically, and forbid files outside projectRoot. Unresolvable / out-of-root → FATAL.
     *
     * @return list<string> absolute, deduped, sorted
     */
    private function resolveFiles(OpenApiEscapeHatch $hatch): array
    {
        $files = [];
        foreach ($hatch->scanTargets as $target) {
            $file = OpenApiEscapeHatch::resolveScanFile($target, $this->projectRoot);
            if ($file === null) {
                $this->diagnostics->error(
                    controller: null,
                    method: null,
                    dto: null,
                    field: 'escape_hatch',
                    cause: sprintf('escape-hatch scan target %s resolves to no autoloadable class and no existing file', $target),
                    fix: 'point the scan target at a real #[OA\Schema] carrier class or an existing fragment file under the project root',
                );
                continue;
            }
            $files[$file] = true; // dedup by resolved absolute path.
        }
        $files = array_keys($files);
        sort($files); // deterministic — identical order used by the Fingerprinter.

        $root = $this->projectRootReal();
        foreach ($files as $file) {
            if ($root !== '' && !str_starts_with($this->normalizeSlashes($file), $root)) {
                $this->diagnostics->error(
                    controller: null,
                    method: null,
                    dto: null,
                    field: 'escape_hatch',
                    cause: sprintf(
                        'escape-hatch scan target %s is outside the project root %s; fragments must live under the project (no absolute deploy-path fallback)',
                        $file,
                        $this->projectRoot,
                    ),
                    fix: 'move the fragment file under the project root, or reference it via a class-string whose source lives in the project',
                );
            }
        }

        return $files;
    }

    /**
     * Scan ONE file with the schema-preserving pipeline (+ the annotation guard). Returns the parsed partial
     * doc array (possibly empty). FATATs from the guard flow into the shared diagnostics.
     *
     * @return array<string, mixed>
     */
    private function scanFile(string $absoluteFile): array
    {
        $generator = $this->generator();
        $analysis = new Analysis([], new \OpenApi\Context());
        $openapi = $generator->generate([$absoluteFile], $analysis, false);
        if ($openapi === null) {
            return [];
        }
        $yaml = $openapi->toYaml();
        $parsed = Yaml::parse($yaml);

        return is_array($parsed) ? $parsed : [];
    }

    /**
     * Exact-shape guard on a partial doc: only openapi/info/components(+ schemas) are permitted; paths must be
     * absent or empty; any other root section or any non-schemas component key → FATAL.
     *
     * @param array<string, mixed> $partial
     */
    private function assertPartialShape(array $partial, string $absoluteFile): void
    {
        foreach ($partial as $key => $value) {
            if (!in_array($key, self::ALLOWED_ROOT_KEYS, true)) {
                $this->diagnostics->error(
                    controller: null,
                    method: null,
                    dto: null,
                    field: 'escape_hatch',
                    cause: sprintf('escape-hatch fragment %s carries a forbidden root section %s; only openapi/info/components are allowed', $absoluteFile, $key),
                    fix: 'remove the section from the fragment — the escape hatch contributes components.schemas only',
                );
                continue;
            }
            if ($key === 'paths' && !empty($value)) {
                $this->diagnostics->error(
                    controller: null,
                    method: null,
                    dto: null,
                    field: 'escape_hatch',
                    cause: sprintf('escape-hatch fragment %s declares operations (non-empty paths); operations are forbidden in the escape hatch', $absoluteFile),
                    fix: 'remove the #[OA\Get/Post/…] / paths declaration from the fragment',
                );
            }
        }

        $components = $partial['components'] ?? [];
        if (is_array($components)) {
            foreach ($components as $componentKey => $_) {
                if (!in_array($componentKey, self::ALLOWED_COMPONENT_KEYS, true)) {
                    $this->diagnostics->error(
                        controller: null,
                        method: null,
                        dto: null,
                        field: 'escape_hatch',
                        cause: sprintf('escape-hatch fragment %s carries a forbidden component %s; only components.schemas is allowed', $absoluteFile, $componentKey),
                        fix: 'remove the component from the fragment — the escape hatch contributes schemas only',
                    );
                }
            }
        }
    }

    /**
     * Reject EXTERNAL $ref (any ref not starting with #/ — http/https/file/relative). The OpenApiValidator
     * treats external refs as resolved, so this narrow hatch forbids them explicitly; only local
     * #/components/schemas/… refs are permitted (resolved by the validator on the merged doc).
     *
     * @param array<string, array<string, mixed>> $fragmentSchemas
     */
    private function assertNoExternalRefs(array $fragmentSchemas): void
    {
        $external = [];
        array_walk_recursive($fragmentSchemas, static function (mixed $value, mixed $key) use (&$external): void {
            if ($key === '$ref' && is_string($value) && $value !== '#' && !str_starts_with($value, '#/')) {
                $external[] = $value;
            }
        });
        if ($external !== []) {
            $this->diagnostics->error(
                controller: null,
                method: null,
                dto: null,
                field: 'escape_hatch',
                cause: sprintf(
                    'escape-hatch fragments contain external $ref(s) (%s); only local #/components/schemas/… refs are allowed',
                    implode(', ', array_slice(array_unique($external), 0, 5)),
                ),
                fix: 'make the ref local (point at a schema declared in the graph or another fragment), or inline the referenced schema',
            );
        }
    }

    /**
     * The schema-preserving swagger-php generator: the annotation guard FIRST, then the components/expand/
     * augment processors. CleanUnusedComponents, BuildPaths and the path/parameter processors are deliberately
     * OMITTED so an unreferenced fragment schema is preserved (and a forbidden operation annotation is caught
     * by the guard rather than built into a path). The suppressing logger mirrors the legacy producer.
     */
    private function generator(): Generator
    {
        if ($this->generator instanceof Generator) {
            return $this->generator;
        }
        $openapi = new Generator(new class extends DefaultLogger {
            public function warning($message, array $context = []): void
            {
                return; // suppress swagger-php warnings, as the legacy producer does.
            }
        });
        $openapi->setProcessorPipeline(new \OpenApi\Pipeline([
            new EscapeHatchAnalysisGuard($this->diagnostics),
            new \OpenApi\Processors\DocBlockDescriptions(),
            new \OpenApi\Processors\MergeIntoOpenApi(),
            new \OpenApi\Processors\MergeIntoComponents(),
            new \OpenApi\Processors\ExpandClasses(),
            new \OpenApi\Processors\ExpandInterfaces(),
            new \OpenApi\Processors\ExpandTraits(),
            new \OpenApi\Processors\ExpandEnums(),
            new \OpenApi\Processors\AugmentSchemas(),
            new \OpenApi\Processors\AugmentProperties(),
            new \OpenApi\Processors\AugmentDiscriminators(),
            new \OpenApi\Processors\AugmentRefs(),
        ]));
        $openapi->setConfig(['operationId.hash' => false]);
        $openapi->setVersion(\OpenApi\Annotations\OpenApi::VERSION_3_1_0);
        $this->generator = $openapi;

        return $openapi;
    }

    private function projectRootReal(): string
    {
        $real = realpath($this->projectRoot);
        $base = $real !== false ? $real : $this->projectRoot;

        return $this->normalizeSlashes(rtrim($base, '/') . '/');
    }

    private function normalizeSlashes(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
