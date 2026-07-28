<?php

declare(strict_types=1);

namespace SpsFW\Core\Attributes\OpenApi;

use Attribute;

/**
 * Declares (or enriches) an operation parameter's OpenAPI facets that the method signature CANNOT express —
 * the lossless carry-over of the legacy inline `#[OA\Parameter]` markup that the metadata graph does not read.
 *
 * Two roles, decided by whether a parameter of the same `(in, name)` was already inferred:
 *  - ENRICH an inferred parameter: a path param's `format: 'uuid'` / `example:`, a query param's
 *    `enum` / `default` / `minimum` / `maximum` (these facets live on the legacy `#[OA\Property]` of the
 *    request DTO, which the graph intentionally does not read). Facets the attribute omits are left untouched.
 *  - DECLARE a manual parameter: a query parameter with NO DTO property at all (added outright).
 *
 * `in` is `'path'` or `'query'` (default `'query'`). A path parameter is always required; `required` is
 * honored only for query parameters. Repeatable (one per parameter).
 *
 * Doc-only — the runtime Router reads only the `#[Route]` path/method; this attribute never changes dispatch
 * or validation. Maps into the existing {@see \SpsFW\Core\Compile\Metadata\ParameterMetadata}.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class Parameter
{
    /**
     * @param ?list<mixed> $enum explicit enum values
     * @param ?string $in 'path' | 'query' (default 'query')
     */
    public function __construct(
        public string $name,
        public ?string $in = null,
        public ?string $type = null,
        public ?bool $required = null,
        public ?string $format = null,
        public ?array $enum = null,
        public mixed $example = null,
        public mixed $default = null,
        public mixed $min = null,
        public mixed $max = null,
        public ?string $description = null,
        public bool $deprecated = false,
    ) {
    }
}
