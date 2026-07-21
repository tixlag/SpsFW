<?php

/**
 * Escape-hatch fixture: a schema carrying an EXTERNAL $ref (a URL). The OpenApiValidator treats external refs
 * as resolved, so this narrow hatch forbids them explicitly (only local #/components/schemas/… is allowed).
 * The merger's assertNoExternalRefs() walks the fragment and records a FATAL.
 */

declare(strict_types=1);

namespace SpsOaTest\EscapeHatch;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ExternalRefThing',
    properties: [
        new OA\Property(property: 'ext', ref: 'https://example.com/schemas/external.json'),
    ],
)]
class ExternalRefThing
{
}
