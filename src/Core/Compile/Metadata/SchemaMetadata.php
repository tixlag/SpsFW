<?php

declare(strict_types=1);

namespace SpsFW\Core\Compile\Metadata;

/**
 * Compile-time description of a DTO / response-object schema: its FQCN, OpenAPI component key, and
 * properties. One SchemaMetadata feeds both the OpenAPI `components.schemas.<name>` projection and, via
 * DtoSchemaBuilder::ruleGraph(), the ValidationRuleGraph — closing today's double read of #[OA\Property].
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
     */
    public function __construct(
        public ?string $className = null,
        public string $name = '',
        public array $properties = [],
        public string $description = '',
        public bool $isEnum = false,
        public ?string $enumType = null,
        public ?array $enumCases = null,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->properties === [] && !$this->isEnum;
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
