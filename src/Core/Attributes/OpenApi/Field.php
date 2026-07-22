<?php

declare(strict_types=1);

namespace SpsFW\Core\Attributes\OpenApi;

use Attribute;

/**
 * Declares the schema facets of a single DTO property that are invisible to the PHP type (plan §8): format,
 * enum, numeric/string bounds, example, the JSON serial name, the component schema name, and read/write-only
 * direction. Feeds both the OpenAPI projection and the rule graph (so `#[Field(min: 1)]` validates like the
 * legacy `#[OA\Property(minimum: 1)]`).
 *
 * Constraints use short names (`min`/`max`) on the attribute; the compiler maps them to the `minimum`/
 * `maximum` rule keys the Validator expects. `name` overrides the JSON key ONLY when it matches what
 * `json_encode` actually emits (serialization contract, plan §6); otherwise it lies and must be avoided.
 *
 * Deliberately carries NO `oneOf/anyOf` — polymorphism lives in the OA escape hatch (plan §13). Doc +
 * rule-graph correctness; targets property (incl. promoted constructor parameters). When applied to a DTO
 * CLASS, only `schema` is meaningful — it overrides the component name {@see \SpsFW\Core\Compile\OpenApi\SchemaNameResolver}
 * assigns the class (the disambiguation lever for short-name collisions).
 *
 * `objectMap` is the ONLY explicit way to tell the compiler a PHP `array` property serializes as a free-form
 * OBJECT/map (a JSON object, not a sequence) when nothing else proves it. The compiler NEVER infers object-map
 * shape from the absence of a list signal — a bare PHP `array` is ambiguous (it may be a list OR a map) — so
 * the shape must be declared explicitly here, or carried by a legacy OA `type:object` / `additionalProperties`
 * / inline-`properties` declaration. A `#[Field(objectMap: true)]` array that ALSO carries a resolvable
 * `#[OA\Property(ref: X::class)]` renders as the typed single object `{$ref: X}` (the explicit flag confirms
 * cardinality; the OA ref supplies the type); otherwise it renders as free-form `{type: object}`.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_CLASS)]
final readonly class Field
{
    /**
     * @param ?list<mixed> $enum
     */
    public function __construct(
        public ?string $format = null,
        public ?array $enum = null,
        public mixed $min = null,
        public mixed $max = null,
        public ?int $minLength = null,
        public ?int $maxLength = null,
        public mixed $example = null,
        public ?string $name = null,
        public ?string $schema = null,
        public bool $readOnly = false,
        public bool $writeOnly = false,
        public bool $objectMap = false,
    ) {
    }
}
