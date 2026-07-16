<?php

declare(strict_types=1);

namespace SpsFWTest\CompileFixtures\Error;

use SpsFW\Core\Attributes\Route;
use SpsFW\Core\Http\HttpMethod;

/**
 * Step 5 Coordinator fixture — a DUPLICATE route key: two methods register the same METHOD:path. Today Router
 * silently overwrites (last-wins); the compile engine surfaces a structural ERROR that must BLOCK publication.
 * (Mirrors the real N shadowed-route fatals recorded in AUDIT §4.11.)
 */
final class FlowDupController
{
    #[Route('/dup/x', [HttpMethod::GET])]
    public function first(): void
    {
    }

    #[Route('/dup/x', [HttpMethod::GET])]
    public function second(): void
    {
    }
}
