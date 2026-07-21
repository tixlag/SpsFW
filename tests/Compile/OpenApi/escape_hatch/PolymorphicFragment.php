<?php

/**
 * Escape-hatch fixture: a CLEAN polymorphic fragment. Declares a `PolymorphicThing` schema using oneOf +
 * discriminator + local $refs to two concrete sibling schemas in the SAME file (so the refs resolve within the
 * partial scan). Used to prove the schema-preserving swagger-php pipeline preserves oneOf/discriminator/refs
 * into components.schemas — and as the merger happy-path (graph gains PolymorphicThing/ConcreteA/ConcreteB).
 *
 * Isolated in its own file so scanning this carrier never trips another scenario's forbidden annotation.
 */

declare(strict_types=1);

namespace SpsOaTest\EscapeHatch;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'PolymorphicThing',
    oneOf: [
        new OA\Schema(ref: '#/components/schemas/ConcreteA'),
        new OA\Schema(ref: '#/components/schemas/ConcreteB'),
    ],
    discriminator: new OA\Discriminator(
        propertyName: 'type',
        mapping: [
            'a' => '#/components/schemas/ConcreteA',
            'b' => '#/components/schemas/ConcreteB',
        ],
    ),
)]
class PolymorphicThing
{
}

#[OA\Schema(
    schema: 'ConcreteA',
    properties: [
        new OA\Property(property: 'type', type: 'string'),
        new OA\Property(property: 'a', type: 'string'),
    ],
)]
class ConcreteA
{
}

#[OA\Schema(
    schema: 'ConcreteB',
    properties: [
        new OA\Property(property: 'type', type: 'string'),
        new OA\Property(property: 'b', type: 'integer'),
    ],
)]
class ConcreteB
{
}
