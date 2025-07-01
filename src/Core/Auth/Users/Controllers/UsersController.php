<?php

namespace SpsFW\Core\Auth\Users\Controllers;

use DateMalformedStringException;
use OpenApi\Attributes as OA;
use Random\RandomException;
use SpsFW\Core\Attributes\AccessRulesAll;
use SpsFW\Core\Attributes\AccessRulesAny;
use SpsFW\Core\Attributes\Inject;
use SpsFW\Core\Attributes\NoAuthAccess;
use SpsFW\Core\Attributes\Route;
use SpsFW\Core\Attributes\Validate;
use SpsFW\Core\Auth\AccessRules\AccessRulesService;
use SpsFW\Core\Auth\AccessRules\Dto\AccessRulesArrayDto;
use SpsFW\Core\Auth\AccessRules\MasterRules;
use SpsFW\Core\Auth\AccessRules\PtoRules;
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
use SpsFW\Core\Validation\Enums\ParamsIn;

// todo разнести контроллеры по соответсвующим областям и грамотно продумать интерфейсы пользователя для самостоятельной работы фреймворка
class UsersController extends RestController
{


    // Такой конструктор нужен, чтобы мы могли мокать наши сервисы
    public function __construct(
        #[Inject]
        private ?UsersServiceI $usersService,
        #[Inject]
        private ?AccessRulesService $accessRulesService,
        #[Inject]
        private ?AuthTokenService $authTokenService)
    {

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