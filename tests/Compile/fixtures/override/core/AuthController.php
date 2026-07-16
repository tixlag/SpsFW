<?php

declare(strict_types=1);

namespace SpsFWTest\CompileFixtures\Override\Core;

use SpsFW\Core\Attributes\Route;
use SpsFW\Core\Http\HttpMethod;

/**
 * Step 5 fix-pass fixture: the framework TEMPLATE controller. It registers the SAME route as the app controller
 * (tests/Compile/fixtures/override/app/AuthController.php), so it is shadowed by the app side via a compile-time
 * routeOverrideMap. Its controllerShort ("Auth") + method ("login") yield the SAME convention operationId as the app
 * side ("AuthLogin") — so if it were NOT shadowed, the two would collide. Shadowing must exclude it from the
 * operationId uniqueness check and from OpenAPI.
 */
final class AuthController
{
    #[Route('/override/login', [HttpMethod::POST])]
    public function login(): void
    {
    }
}
