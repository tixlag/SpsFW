<?php

declare(strict_types=1);

namespace SpsFW\Core\Compile\Metadata;

/**
 * A single OpenAPI operation parameter (path or query).
 *
 * pathParams are derived from the {name} placeholders in #[Route] crossed with the method signature
 * (the PHP type wins over the historical swagger-php `string`); queryParams come from the bound
 * #[QueryParams] DTO's properties. Either may be enriched, or added outright, by an explicit
 * {@see \SpsFW\Core\Attributes\OpenApi\Parameter} attribute — the only way to carry facets the signature
 * cannot express (a path param's `format: uuid` / `example:`, a query param's `enum`/`default`/bounds,
 * or a manual query parameter with no DTO property).
 *
 * This is a documentation-only projection — runtime path/query parsing is unchanged.
 */
final readonly class ParameterMetadata
{
    public const IN_PATH = 'path';
    public const IN_QUERY = 'query';

    /**
     * @param ?list<mixed> $enum explicit enum values (#[OpenApi\Parameter(enum: …)])
     * @param mixed $default explicit default value (#[OpenApi\Parameter(default: …)])
     * @param mixed $minimum explicit lower bound (#[OpenApi\Parameter(min: …)])
     * @param mixed $maximum explicit upper bound (#[OpenApi\Parameter(max: …)])
     */
    public function __construct(
        public string $name,
        public string $in,
        public bool $required = false,
        public ?string $type = null,
        public ?string $format = null,
        public ?string $description = null,
        public mixed $example = null,
        public bool $deprecated = false,
        public ?array $enum = null,
        public mixed $default = null,
        public mixed $minimum = null,
        public mixed $maximum = null,
    ) {
    }

    public function isPath(): bool
    {
        return $this->in === self::IN_PATH;
    }

    public function isQuery(): bool
    {
        return $this->in === self::IN_QUERY;
    }
}
