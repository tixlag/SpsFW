<?php

declare(strict_types=1);

namespace SpsFW\Core\Attributes\OpenApi;

use Attribute;

/**
 * Declares the element type of an `array` PHP type that the compiler cannot infer (plan §8): PHP's `array`
 * carries no element type, so a collection property/parameter — `array $phones`, `array $ids` — needs an
 * explicit item. Without it the compiler halts with a "no item type" diagnostic.
 *
 * Exactly one of `class` (a `*Dto`/entity/enum projected to a schema) or `type` (an OpenAPI scalar like
 * 'integer'|'string') must be given. `class` feeds both the schema projection and the rule graph (so
 * `array $phones` with `#[Items(PhoneDto::class)]` validates each element recursively, like the legacy
 * `#[OA\Property(items: Items(ref: …))]`).
 *
 * Targets property (incl. promoted constructor parameters) and parameter. Doc + rule-graph correctness.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final readonly class Items
{
    public function __construct(
        public ?string $class = null,
        public ?string $type = null,
    ) {
    }
}
