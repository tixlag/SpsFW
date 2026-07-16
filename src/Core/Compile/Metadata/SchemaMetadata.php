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
     * @param ?string $type OpenAPI scalar type for an INLINE schema (string/integer/number/boolean); null for objects
     * @param ?string $format OpenAPI format for an inline schema (date-time, uuid, email, …)
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
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->properties === [] && !$this->isEnum && $this->type === null;
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
