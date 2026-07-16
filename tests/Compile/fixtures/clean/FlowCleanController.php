<?php

declare(strict_types=1);

namespace SpsFWTest\CompileFixtures\Clean;

use SpsFW\Core\Attributes\Route;
use SpsFW\Core\Attributes\Validation\JsonBody;
use SpsFW\Core\Http\HttpMethod;

/**
 * Step 5 Coordinator fixture — a CLEAN controller (no errors, no warnings): publishes successfully. A void-return
 * GET (no response inference) and a JsonBody POST exercising the DTO rule-graph + DI analysis paths.
 */
final class FlowCleanController
{
    #[Route('/flow/health', [HttpMethod::GET])]
    public function health(): void
    {
    }

    #[Route('/flow/create', [HttpMethod::POST])]
    public function create(#[JsonBody] FlowCreateDto $dto): void
    {
    }
}
