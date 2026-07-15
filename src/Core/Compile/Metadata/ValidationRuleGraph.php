<?php

declare(strict_types=1);

namespace SpsFW\Core\Compile\Metadata;

/**
 * The validation rule graph — the compile-time source of truth for runtime DTO validation.
 *
 * One graph per DTO binding. From M2 on it is produced by DtoSchemaBuilder::ruleGraph() and, after the
 * M5 producer switch, written into the route cache under `dtos[].rules`, where it is consumed verbatim by
 * Validator::validateDtoWithCachedRules() at runtime (see the validation compatibility matrix, plan §14).
 *
 * The outer shape mirrors exactly what Router::extractValidationRules() and the Validator expect:
 * property name => rule map. `required` is stored as the array `[true]`, matching the Validator contract.
 * Nested DTOs and collections live *inside* each property's rule map (`ref`, `nested_rules`, collection
 * item), so this VO only wraps the outer map and stays structurally identical to today's route-cache IR.
 *
 * Step 1 (M1): the VO exists but no builder populates it yet.
 */
final readonly class ValidationRuleGraph
{
    /**
     * @param array<string, array<string, mixed>> $rules property name => rule map
     */
    public function __construct(
        public array $rules = [],
    ) {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    public function isEmpty(): bool
    {
        return $this->rules === [];
    }
}
