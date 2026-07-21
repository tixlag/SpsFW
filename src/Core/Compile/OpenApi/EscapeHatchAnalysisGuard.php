<?php

declare(strict_types=1);

namespace SpsFW\Core\Compile\OpenApi;

use OpenApi\Analysis;
use OpenApi\Annotations as OA;
use SpsFW\Core\Compile\CompileDiagnostics;

/**
 * The annotation-level allowlist guard for the OA escape hatch (Step 8 / M6, plan §13).
 *
 * The escape hatch contributes `components.schemas.*` ONLY. Because the merger's swagger-php pipeline is
 * SCHEMA-PRESERVING (it deliberately drops `BuildPaths` and the path/parameter processors so an
 * unreferenced fragment schema is not cleaned), a forbidden annotation such as `#[OA\Get]` /
 * `#[OA\Parameter]` / `#[OA\RequestBody]` / `#[OA\Response]` / `#[OA\SecurityScheme]` would be SILENTLY
 * DROPPED before it ever reached the partial document — so a shape-only check on the generated doc would
 * MISS it. This guard inspects the raw {@see Analysis} (every parsed annotation, regardless of whether the
 * pipeline later drops it) and records a FATAL {@see CompileDiagnostics::error()} for ANY annotation that is
 * not on the schema-related allowlist, naming the source file/class and the forbidden annotation type.
 *
 * Implemented as a swagger-php processor ({@see __invoke(Analysis)}) so it runs INSIDE the pipeline, ahead of
 * the processors that drop/merge annotations — the FIRST pipe in the merger's pipeline. It is a strict
 * ALLOWLIST (decision: only schema/property/items/discriminator-related annotations are permitted), which is
 * more robust than enumerating forbidden types. `OpenApi\Attributes\*` subclasses extend their
 * `OpenApi\Annotations\*` counterparts, so an `instanceof` against the base annotation classes covers both
 * attribute and docblock forms.
 *
 * This is the FIRST of two guards; the second ({@see OpenApiEscapeHatchMerger}'s exact-shape check) is
 * defense-in-depth on the generated document. An annotation forbidden here already blocks publication.
 */
final class EscapeHatchAnalysisGuard
{
    /**
     * Base annotation classes that ARE schema-related (the allowlist). `instanceof` covers the matching
     * `OpenApi\Attributes\*` subclasses, which extend these. The root {@see OA\OpenApi} and the synthetic
     * {@see OA\Components} container are permitted (the pipeline synthesizes them; they carry no source
     * declaration worth policing).
     */
    private const ALLOWED_ANNOTATIONS = [
        OA\OpenApi::class,
        OA\Components::class,
        OA\Schema::class,
        OA\Property::class,
        OA\Items::class,
        OA\Discriminator::class,
        OA\AdditionalProperties::class,
        OA\ExternalDocumentation::class,
        OA\Xml::class,
    ];

    public function __construct(
        private readonly CompileDiagnostics $diagnostics,
    ) {
    }

    public function __invoke(Analysis $analysis): void
    {
        foreach ($analysis->annotations as $annotation) {
            if ($this->isAllowed($annotation)) {
                continue;
            }
            /** @var object $annotation */
            $context = $annotation instanceof OA\AbstractAnnotation ? $annotation->_context : null;
            $this->diagnostics->error(
                controller: null,
                method: null,
                dto: $this->sourceClass($context),
                field: 'escape_hatch',
                cause: sprintf(
                    'forbidden OA annotation %s in the escape-hatch scan — only schema/property/items/'
                    . 'discriminator-related annotations are allowed; the escape hatch contributes '
                    . 'components.schemas only. Source: %s.',
                    $this->shortType($annotation),
                    $this->location($context),
                ),
                fix: 'remove the annotation from the fragment, or express the construct as a '
                    . 'components.schemas fragment (oneOf/anyOf/allOf + discriminator)',
            );
        }
    }

    private function isAllowed(object $annotation): bool
    {
        foreach (self::ALLOWED_ANNOTATIONS as $allowed) {
            if ($annotation instanceof $allowed) {
                return true;
            }
        }

        return false;
    }

    private function shortType(object $annotation): string
    {
        $fqcn = $annotation::class;
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }

    private function sourceClass(?\OpenApi\Context $context): ?string
    {
        if ($context === null) {
            return null;
        }
        $class = $context->class ?? null;
        $namespace = $context->namespace ?? null;
        if (!is_string($class) || $class === '') {
            return null;
        }
        if (is_string($namespace) && $namespace !== '' && !str_starts_with($class, '\\')) {
            return $namespace . '\\' . $class;
        }

        return $class;
    }

    private function location(?\OpenApi\Context $context): string
    {
        if ($context === null) {
            return '(unknown source)';
        }
        $parts = [];
        $file = $context->filename ?? null;
        if (is_string($file) && $file !== '') {
            $parts[] = $file;
        }
        $class = $this->sourceClass($context);
        if ($class !== null) {
            $parts[] = $class;
        }
        $line = $context->lineNumber ?? $context->line ?? null;
        if (is_int($line) || (is_string($line) && $line !== '')) {
            $parts[] = 'line ' . $line;
        }

        return $parts === [] ? $context->getDebugLocation() : implode(' :: ', $parts);
    }
}
