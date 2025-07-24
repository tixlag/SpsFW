<?php

namespace SpsFW\Core\Auth;

use DateMalformedIntervalStringException;
use DateMalformedStringException;
use OpenApi\Attributes as OA;
use Random\RandomException;
use SpsFW\Core\Attributes\AccessRulesAll;
use SpsFW\Core\Attributes\AccessRulesAny;
use SpsFW\Core\Attributes\Inject;
use SpsFW\Core\Attributes\JsonBody;
use SpsFW\Core\Attributes\NoAuthAccess;
use SpsFW\Core\Attributes\Route;
use SpsFW\Core\Attributes\Validate;
use SpsFW\Core\Auth\Util\AccessRuleRegistry;
use SpsFW\Core\Exceptions\AuthorizationException;
use SpsFW\Core\Http\Response;
use SpsFW\Core\Route\RestController;
use SpsFW\Core\Validation\Enum\ParamsIn;


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

    public function register(): Response
    {
        return Response::error(message: "Необходимо реализовать свои AuthController");    }

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
    public function logout(): Response
    {
        return Response::error(message: "Необходимо реализовать свои AuthController");
    }




}