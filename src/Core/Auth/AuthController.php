<?php

namespace SpsFW\Core\Auth;

use DateMalformedIntervalStringException;
use DateMalformedStringException;
use OpenApi\Attributes as OA;
use Random\RandomException;
use SpsFW\Core\Attributes\Inject;
use SpsFW\Core\Attributes\NoAuthAccess;
use SpsFW\Core\Attributes\Route;
use SpsFW\Core\Attributes\Validate;
use SpsFW\Core\Auth\AccessRules\Models\UserAbstract;
use SpsFW\Core\Exceptions\AuthorizationException;
use SpsFW\Core\Exceptions\BadPasswordException;
use SpsFW\Core\Exceptions\UserNotFoundException;
use SpsFW\Core\Http\Response;
use SpsFW\Core\Route\RestController;
use SpsFW\Core\Validation\Enums\ParamsIn;

class AuthController extends RestController
{

    public function __construct(
        #[Inject]
        private AuthServiceI $authService,
    )
    {
        parent::__construct();
    }


    #[Route('/api/auth/refresh-tokens', ['POST'])]
    #[NoAuthAccess]
    public function refreshTokens(): Response
    {
        $refreshToken = $_COOKIE['refresh_token'] ?? null;

        return Response::json($this->authService->updateTokens($refreshToken));
    }




}