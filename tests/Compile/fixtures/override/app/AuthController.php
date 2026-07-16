<?php

declare(strict_types=1);

namespace SpsFWTest\CompileFixtures\Override\App;

use SpsFW\Core\Attributes\Route;
use SpsFW\Core\Http\HttpMethod;

/**
 * Step 5 fix-pass fixture: the APP controller that OVERRIDES the framework template on the same route — the
 * declared override WINNER. Same METHOD:path and same convention operationId ("AuthLogin") as the Core template, so
 * the override's shadowing is what prevents an operationId collision.
 */
final class AuthController
{
    #[Route('/override/login', [HttpMethod::POST])]
    public function login(): void
    {
    }
}
