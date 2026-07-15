<?php

declare(strict_types=1);

namespace SpsFW\Core\Compile\Metadata;

/**
 * Compile-time description of a single DTO property — the common input for both the OpenAPI
 * SchemaMetadata projection and the ValidationRuleGraph produced from the same schema.
 *
 *  - name        : PHP property name
 *  - serialName  : JSON key this property serializes to (defaults to name); must match json_encode output
 *  - phpType     : normalized PHP type string (e.g. 'int', 'string', FQCN, 'array')
 *  - ref         : FQCN of a nested DTO the graph references; null for scalars / collections of scalars
 *  - itemType    : for arrays: the element PHP type / FQCN, else null (resolved via #[Items], Step 2/3)
 *  - format      : OpenAPI format hint (uuid, date, date-time, email, …) from #[Field(format)] or class type
 *  - constraints : minimum/maximum/minLength/maxLength/enum from #[Field]
 *  - nullable / hasDefault / defaultValue / required : optionality & presence, the source of the rule graph
 *  - readOnly / writeOnly / schemaName : direction-specific projection hints (#[Field])
 *
 * Step 1 (M1): the VO exists but is not yet populated by any reader.
 */
final readonly class PropertyMetadata
{
    /**
     * @param ?class-string $ref
     * @param ?list<mixed> $enum
     * @param array<string, mixed> $extra forward-compatible bag for rules not yet modeled explicitly
     */
    public function __construct(
        public string $name,
        public ?string $serialName = null,
        public ?string $phpType = null,
        public ?string $ref = null,
        public ?string $itemType = null,
        public ?string $format = null,
        public bool $nullable = false,
        public bool $hasDefault = false,
        public mixed $defaultValue = null,
        public bool $required = false,
        public ?array $enum = null,
        public mixed $minimum = null,
        public mixed $maximum = null,
        public ?int $minLength = null,
        public ?int $maxLength = null,
        public mixed $example = null,
        public bool $readOnly = false,
        public bool $writeOnly = false,
        public ?string $schemaName = null,
        public array $extra = [],
    ) {
    }

    /**
     * The JSON key this property serializes to (serialName if set, else the PHP name).
     */
    public function serialName(): string
    {
        return $this->serialName ?? $this->name;
    }
}
