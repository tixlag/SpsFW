<?php

declare(strict_types=1);

namespace SpsFW\Core\Compile\Metadata;

/**
 * A single OpenAPI operation parameter (path or query).
 *
 * pathParams are derived from the {name} placeholders in #[Route] crossed with the method signature
 * (the PHP type wins over the historical swagger-php `string`); queryParams come from the bound
 * #[QueryParams] DTO's properties.
 *
 * This is a documentation-only projection — runtime path/query parsing is unchanged.
 */
final readonly class ParameterMetadata
{
    public const IN_PATH = 'path';
    public const IN_QUERY = 'query';

    public function __construct(
        public string $name,
        public string $in,
        public bool $required = false,
        public ?string $type = null,
        public ?string $format = null,
        public ?string $description = null,
        public mixed $example = null,
        public bool $deprecated = false,
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
