<?php

/**
 * Escape-hatch fixture (collision, side B): the other declarer of `ClashingName` (see CollisionFragmentA).
 */

declare(strict_types=1);

namespace SpsOaTest\EscapeHatch;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ClashingName',
    properties: [
        new OA\Property(property: 'fromB', type: 'integer'),
    ],
)]
class CollisionFragmentB
{
}
