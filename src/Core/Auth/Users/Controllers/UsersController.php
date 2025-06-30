<?php

namespace SpsFW\Core\Auth\Users\Controllers;

use DateMalformedStringException;
use Random\RandomException;
use SpsFW\Core\AccessRules\AccessRulesService;
use SpsFW\Core\AccessRules\Attributes\AccessRulesAll;
use SpsFW\Core\AccessRules\Attributes\AccessRulesAny;
use SpsFW\Core\AccessRules\Attributes\NoAuthAccess;
use SpsFW\Core\AccessRules\Dto\AccessRulesArrayDto;
use SpsFW\Core\AccessRules\MasterRules;
use SpsFW\Core\AccessRules\PtoRules;
use SpsFW\Core\Auth\AuthToken\AuthTokenService;
use SpsFW\Core\Auth\Users\Dto\LoginUserDto;
use SpsFW\Core\Auth\Users\Dto\RegisterUserDto;
use SpsFW\Core\Auth\Users\Models\User;
use SpsFW\Core\Auth\Users\Models\UserAuthI;
use SpsFW\Core\Auth\Users\UsersService;
use SpsFW\Core\Auth\Users\UsersServiceI;
use SpsFW\Core\Exceptions\AuthorizationException;
use SpsFW\Core\Exceptions\BadPasswordException;
use SpsFW\Core\Exceptions\UserNotFoundException;
use SpsFW\Core\Http\Response;
use SpsFW\Core\Route\RestController;
use SpsFW\Core\Route\Route;
use SpsFW\Core\Validation\Attributes\Validate;
use SpsFW\Core\Validation\Enums\ParamsIn;
use OpenApi\Attributes as OA;

class UsersController extends RestController
{

    private UsersServiceI $usersService;
    private AccessRulesService $accessRulesService;
    private AuthTokenService $authTokenService;

    // Такой конструктор нужен, чтобы мы могли мокать наши сервисы
    public function __construct(?UsersServiceI $usersService = null, ?AccessRulesService $accessRulesService = null, ?AuthTokenService $authTokenService = null)
    {
        $this->usersService = $usersService ?? new UsersService();
        $this->accessRulesService = $accessRulesService ?? new AccessRulesService();
        $this->authTokenService = $authTokenService ?? new AuthTokenService();
        parent::__construct();
    }

    /**
     * @throws RandomException
     */
    #[OA\Post(
        path: "/api/users/register",
        operationId: "registerUser",
        description: "В Authorization header приходит Access Token, и, если указан remember, в cookies приходит Refresh Token",
        summary: "Регистрация пользователя",
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                ref: RegisterUserDto::class
            )
        ),
        tags: ["Users"],
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
    #[Route('/api/users/register', ["POST"])]
    #[NoAuthAccess]
    #[Validate(ParamsIn::Json, RegisterUserDto::class)]
    public function register(RegisterUserDto $dto): Response
    {
        return Response::created(
            $this->usersService->register(
                $dto->login,
                $dto->password,
                $dto->passportNumber,
                $dto->fio,
                $dto->birthday,
                $dto->email,
                $dto->phone
            )
        );
    }

    /**
     * @throws BadPasswordException
     * @throws RandomException
     * @throws UserNotFoundException
     */
    #[OA\Post(
        path: "/api/users/login",
        operationId: "loginUser",
        description: "",
        summary: "Регистрация пользователя",
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                ref: LoginUserDto::class
            )
        ),
        tags: ["Users"],
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
    #[Route('/api/users/login', ["POST"])]
    #[NoAuthAccess]
    #[Validate(ParamsIn::Json, LoginUserDto::class)]
    public function login(LoginUserDto $dto): User
    {
        return $this->usersService->login($dto->login, $dto->password, $dto->remember);
    }

    #[OA\Post(
        path: "/api/users/logout",
        operationId: "logoutUser",
        description: "",
        summary: "Выход из системы",
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                ref: LoginUserDto::class
            )
        ),
        tags: ["Users"],
        responses: [
            new OA\Response(
                response: 200,
            )
        ]
    )]
    #[Route('/api/users/logout', ["POST"])]
    #[NoAuthAccess]
    #[Validate(ParamsIn::Json, LoginUserDto::class)]
    public function logout(LoginUserDto $dto): Response
    {
         $this->accessRulesService->logout();
         return Response::ok();
    }


    /**
     * @throws DateMalformedStringException
     * @throws RandomException
     * @throws AuthorizationException
     */
    #[OA\Post(
        path: "/api/users/refresh-tokens",
        operationId: "refreshToken",
        description: "В Authorization header приходит Access Token, в cookies приходит Refresh Token",
        summary: "Получить новые токены",
        tags: ["Users"],
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
    #[Route('/api/users/refresh-tokens', ['POST'])]
    #[NoAuthAccess]
    public function refreshTokens(): Response
    {
        $refreshToken = $_COOKIE['refresh_token'] ?? null;

        return Response::json($this->authTokenService->updateTokens($refreshToken));
    }

    #[OA\Post(
        path: "/api/users/add-access-rules",
        operationId: "addAccessRules",
        description: "Неуказанные правила не будут не тронуты",
        summary: "Добавить правила доступа пользователю",
        requestBody: new OA\RequestBody(
            description: "Access rules",
            required: true,
            content: new OA\MediaType(
                mediaType: "application/json",
                schema: new OA\Schema(
                    ref: AccessRulesArrayDto::class
                )
            )
        ),
        tags: ["Users"],
        responses: [
            new OA\Response(
                response: 200,
                headers: [
                    new OA\Header(
                        header: "Authorization",
                        description: "Bearer token с новыми ролями пользователя",
                        schema: new OA\Schema(
                            type: "string"
                        )
                    )
                ]
            )
        ]
    )]
    #[Route('/api/users/add-access-rules', ['POST'])]
    #[Validate(ParamsIn::Json, AccessRulesArrayDto::class)]
    public function addAccessRules(AccessRulesArrayDto $accessRulesDto): UserAuthI
    {
        $accessRules = [];
        foreach ($accessRulesDto->rules as $rule) {
            $accessRules += $rule->toArray();
        }

        return $this->accessRulesService->addAccessRules($accessRulesDto->userId, $accessRules);
    }

    #[OA\Post(
        path: "/api/users/set-access-rules",
        operationId: "setAccessRules",
        description: "Перезапишет существующие правила доступа",
        summary: "Назначить правила доступа пользователю",
        requestBody: new OA\RequestBody(
            description: "Access rules",
            required: true,
            content: new OA\MediaType(
                mediaType: "application/json",
                schema: new OA\Schema(
                    ref: AccessRulesArrayDto::class
                )
            )
        ),
        tags: ["Users"],
        responses: [
            new OA\Response(
                response: 200,
                headers: [
                    new OA\Header(
                        header: "Authorization",
                        description: "Bearer token с новыми ролями пользователя",
                        schema: new OA\Schema(
                            type: "string"
                        )
                    )
                ]
            )
        ]
    )]
    #[Route('/api/users/set-access-rules', ['POST'])]
    #[Validate(ParamsIn::Json, AccessRulesArrayDto::class)]
    public function setAccessRules(AccessRulesArrayDto $accessRulesDto): Response
    {
        $accessRules = [];
        foreach ($accessRulesDto->rules as $rule) {
            $accessRules += $rule->toArray();
        }

        return Response::json($this->accessRulesService->setAccessRules($accessRulesDto->userId, $accessRules));
    }




    #[Route('/tester', ["POST"])]
    #[AccessRulesAny([
        200,
        MasterRules::DIGITAL_LINK_MASTER_ALLOW_FILTER,
        MasterRules::DIGITAL_LINK_MASTER_ALLOW_MANAGE_TASKS
    ])]
    #[AccessRulesAll([PtoRules::DIGITAL_LINK_PTO_ACCESS, PtoRules::DIGITAL_LINK_PTO_CREATE_TASKS])]
    public function tester(): Response
    {
        return Response::json(["ok" => 'tester']);
    }


}