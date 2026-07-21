<?php

/**
 * Escape-hatch fixture (collision, side A): declares the component name `ClashingName`. Paired with
 * CollisionFragmentB (same name, different file). Scanning BOTH as separate targets must produce a FATAL
 * duplicate-key error — a requested schema must have exactly one source (no silent fragment last-wins).
 */

declare(strict_types=1);

namespace SpsOaTest\EscapeHatch;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ClashingName',
    properties: [
        new OA\Property(property: 'fromA', type: 'string'),
    ],
)]
class CollisionFragmentA
{
}
