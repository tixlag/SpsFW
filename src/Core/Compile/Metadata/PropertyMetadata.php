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
 *  - refClass    : reflection-derived nested class (first non-builtin type member), the parity source for ref
 *  - itemType    : for arrays: the element PHP type / FQCN, else null (resolved via #[Items], Step 2/3)
 *  - format      : OpenAPI format hint (uuid, date, date-time, email, …) from #[Field(format)] or class type
 *  - constraints : minimum/maximum/minLength/maxLength/enum from #[Field]
 *  - nullable / hasDefault / defaultValue / required : optionality & presence, the source of the rule graph
 *  - readOnly / writeOnly / schemaName : direction-specific projection hints (#[Field])
 *  - rawArguments: PARITY-PHASE ONLY — the ordered #[OA\Property] getArguments() snapshot that
 *                  DtoSchemaBuilder::ruleGraph() replays to stay byte-compatible with
 *                  Router::extractValidationRules(); removed in M8 once the OA source is gone.
 *
 * Step 1 (M1): the VO exists but is not yet populated by any reader.
 */
final readonly class PropertyMetadata
{
    /**
     * @param ?class-string $ref
     * @param ?class-string $refClass
     * @param ?list<mixed> $enum
     * @param array<string, mixed> $extra forward-compatible bag for rules not yet modeled explicitly
     * @param ?array<string, mixed> $rawArguments parity-phase ordered #[OA\Property] getArguments()
     */
    public function __construct(
        public string $name,
        public ?string $serialName = null,
        public ?string $phpType = null,
        public ?string $ref = null,
        public ?string $refClass = null,
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
        public ?array $rawArguments = null,
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
