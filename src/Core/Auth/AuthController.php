<?php

namespace SpsFW\Core\Auth;

use DateMalformedIntervalStringException;
use DateMalformedStringException;
use OpenApi\Attributes as OA;
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
    #[OA\Post(
        path: "/api/auth/refresh-tokens",
        operationId: "refreshTokens",
        description: "В Authorization header приходит Access Token, в cookies приходит Refresh Token",
        summary: "Обновление токенов авторизации",
        tags: ["Auth"],
        responses: [
            new OA\Response(
                response: 200,
                headers: [
                    new OA\Header(
                        header: "Authorization",
                        description: "Bearer token с ролями пользователя",
                        schema: new OA\Schema(
                            type: "string"
                        )
                    )
                ]
            )
        ]
    )]
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

    #[OA\Post(
        path: "/api/auth/register",
        operationId: "registerUser",
        description: "Необходимо реализовать свой AuthController! В Authorization header приходит Access Token, и, если указан remember, в cookies приходит Refresh Token",
        summary: "Регистрация пользователя",
        tags: ["Auth"],
        responses: [
            new OA\Response(
                response: 200,
                headers: [
                    new OA\Header(
                        header: "Authorization",
                        description: "Bearer token с ролями пользователя",
                        schema: new OA\Schema(
                            type: "string"
                        )
                    )
                ]
            )
        ]
    )]
    #[Route('/api/auth/register', ["POST"])]
    #[NoAuthAccess]
    #[ApiResponse(status: 200, description: 'Bearer token с ролями пользователя')]
    public function register(): Response
    {
        return Response::error(message: "Необходимо реализовать свои AuthController");
    }

    #[OA\Post(
        path: "/api/auth/login",
        operationId: "loginUser",
        description: "Необходимо реализовать свой AuthController",
        summary: "Регистрация пользователя",
        tags: ["Auth"],
        responses: [
            new OA\Response(
                response: 200,
                headers: [
                    new OA\Header(
                        header: "Authorization",
                        description: "Bearer token с ролями пользователя",
                        schema: new OA\Schema(
                            type: "string"
                        )
                    )
                ]
            )
        ]
    )]
    #[Route('/api/auth/login', ["POST"])]
    #[NoAuthAccess]
    #[ApiResponse(status: 200, description: 'Bearer token с ролями пользователя')]
    public function login(): Response
    {
        return Response::error(message: "Необходимо реализовать свои AuthController");
    }

    #[OA\Post(
        path: "/api/auth/logout",
        operationId: "logoutUser",
        description: "",
        summary: "Выход из системы",
        tags: ["Auth"],
        responses: [
            new OA\Response(
                response: 200,
            )
        ]
    )]
    #[Route('/api/auth/logout', ["POST"])]
    #[NoAuthAccess]
    #[ApiResponse(status: 200, description: 'Logout')]
    public function logout(): Response
    {
        return Response::error(message: "Необходимо реализовать свои AuthController");
    }




}