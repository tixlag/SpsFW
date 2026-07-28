<?php

declare(strict_types=1);

namespace SpsFW\Core\Compile\Metadata;

/**
 * Compile-time description of a schema fragment — a DTO/object schema, a scalar/DateTime inline schema, or an
 * enum schema. One SchemaMetadata feeds both the OpenAPI `components.schemas.<name>` projection and, via
 * DtoSchemaBuilder::ruleGraph(), the ValidationRuleGraph — closing today's double read of #[OA\Property].
 *
 * Object schemas carry className + properties; scalar/DateTime inline schemas carry type (+ format); enum
 * schemas carry isEnum + enumType + enumCases. The three shapes are mutually exclusive on a single fragment.
 *
 * Memoized per FQCN by the builder (plan §17).
 *
 * Step 1 (M1): the VO exists but DtoSchemaBuilder is added in Step 2.
 */
final readonly class SchemaMetadata
{
    /**
     * @param ?class-string $className
     * @param list<PropertyMetadata> $properties
     * @param ?list<mixed> $enumCases backed-enum values when isEnum is true
     * @param ?string $type OpenAPI scalar/array/object type for an INLINE schema fragment
     * @param ?string $format OpenAPI format for an inline schema (date-time, uuid, email, …)
     * @param ?SchemaMetadata $items array element (a full fragment, so an inline item carries its own
     *     type/format/enum/example/default/constraints — e.g. `items: {type: string, example: active}`); null
     *     for non-arrays. Replaces the old "arrayItem is only an itemType string" loss (D3).
     * @param ?SchemaMetadata $additionalProperties a typed map value (an object whose values share one schema),
     *     e.g. `additionalProperties: {type: array, items: {type: string}}`; null = absent (default allow).
     * @param ?list<mixed> $enum non-backed-enum facet enum values (distinct from isEnum/enumCases, the
     *     backed-enum projection); set from an inline-shape facet `enum: [...]`.
     * @param mixed $example facet example value
     */
    public function __construct(
        public ?string $className = null,
        public string $name = '',
        public array $properties = [],
        public string $description = '',
        public bool $isEnum = false,
        public ?string $enumType = null,
        public ?array $enumCases = null,
        public ?string $type = null,
        public ?string $format = null,
        public ?SchemaMetadata $items = null,
        public ?SchemaMetadata $additionalProperties = null,
        public bool $additionalPropertiesFalse = false,
        public ?array $enum = null,
        public mixed $example = null,
        public bool $hasDefault = false,
        public mixed $defaultValue = null,
        public bool $nullable = false,
        public mixed $minimum = null,
        public mixed $maximum = null,
        public ?int $minLength = null,
        public ?int $maxLength = null,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->properties === []
            && !$this->isEnum
            && $this->type === null
            && $this->items === null
            && $this->additionalProperties === null
            && !$this->additionalPropertiesFalse;
    }

    /**
     * Look up a property by its PHP name, or null when absent.
     */
    public function property(string $name): ?PropertyMetadata
    {
        foreach ($this->properties as $property) {
            if ($property->name === $name) {
                return $property;
            }
        }
        return null;
    }
}
