<?php

namespace SpsFW\Core\Auth\AccessRules;

use OpenApi\Attributes as OA;
use SpsFW\Core\Attributes\Inject;
use SpsFW\Core\Attributes\Route;
use SpsFW\Core\Attributes\Validate;
use SpsFW\Core\Auth\AccessRules\Dto\AccessRulesArrayDto;
use SpsFW\Core\Auth\AccessRules\Models\UserAbstract;
use SpsFW\Core\Http\Response;
use SpsFW\Core\Route\RestController;
use SpsFW\Core\Validation\Enums\ParamsIn;

class AccessRulesController extends RestController
{

    public function __construct(
        #[Inject]
        private AccessRulesServiceI $accessRulesService,
    )
    {
        parent::__construct();
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
                description: "OK",
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
    #[Route('/api/users/add-access-rules', ['PATCH'])]
    #[Validate(ParamsIn::Json, AccessRulesArrayDto::class)]
    public function addAccessRules(AccessRulesArrayDto $accessRulesDto): UserAbstract
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
                description: "OK",
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

}