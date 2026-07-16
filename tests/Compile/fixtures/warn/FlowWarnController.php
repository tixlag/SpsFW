<?php

declare(strict_types=1);

namespace SpsFWTest\CompileFixtures\Warn;

use SpsFW\Core\Attributes\Route;
use SpsFW\Core\Http\HttpMethod;

/**
 * Step 5 Coordinator fixture — a MIGRATION WARNING (not an error): a bare `array` return type has no derivable
 * item type, so the success-response schema cannot be inferred without an explicit #[Response]. Under the PARITY
 * policy this WARNING is tolerated (publication proceeds); under STRICT it blocks publication.
 */
final class FlowWarnController
{
    #[Route('/warn/list', [HttpMethod::GET])]
    public function list(): array
    {
        return [];
    }
}
