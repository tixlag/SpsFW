<?php

namespace SpsFW\Core\Auth;

use DateMalformedIntervalStringException;
use DateMalformedStringException;
use Random\RandomException;
use SpsFW\Core\Attributes\Inject;
use SpsFW\Core\Attributes\NoAuthAccess;
use SpsFW\Core\Attributes\Route;
use SpsFW\Core\Exceptions\AuthorizationException;
use SpsFW\Core\Http\Response;
use SpsFW\Core\Route\RestController;
use SpsFW\Core\Attributes\OpenApi\Response as ApiResponse;


class AuthController extends RestController
{

    public function __construct(
        #[Inject]
        private AuthServiceI $authService,
    )
    {
        parent::__construct();
    }


    /**
     * @throws DateMalformedStringException
     * @throws RandomException
     * @throws DateMalformedIntervalStringException
     * @throws AuthorizationException
     */
    #[Route('/api/auth/refresh-tokens', ['POST'])]
    #[NoAuthAccess]
    #[ApiResponse(status: 200, description: 'Bearer token с ролями пользователя')]
    public function refreshTokens(): Response
    {
        $refreshToken = $_COOKIE['refresh_token'] ?? null;

        return Response::json($this->authService->updateTokens($refreshToken));
    }



    /**
     */

    #[Route('/api/auth/register', ["POST"])]
    #[NoAuthAccess]
    #[ApiResponse(status: 200, description: 'Bearer token с ролями пользователя')]
    public function register(): Response
    {
        return Response::error(message: "Необходимо реализовать свои AuthController");
    }

    #[Route('/api/auth/login', ["POST"])]
    #[NoAuthAccess]
    #[ApiResponse(status: 200, description: 'Bearer token с ролями пользователя')]
    public function login(): Response
    {
        return Response::error(message: "Необходимо реализовать свои AuthController");
    }

    #[Route('/api/auth/logout', ["POST"])]
    #[NoAuthAccess]
    #[ApiResponse(status: 200, description: 'Logout')]
    public function logout(): Response
    {
        return Response::error(message: "Необходимо реализовать свои AuthController");
    }




}