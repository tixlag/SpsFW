<?php

namespace SpsFW\Core\Auth\AccessRule;

use OpenApi\Attributes as OA;
use SpsFW\Core\Attributes\Inject;
use SpsFW\Core\Attributes\Route;
use SpsFW\Core\Attributes\Validation\JsonBody;
use SpsFW\Core\Attributes\Validation\Validate;
use SpsFW\Core\Auth\Dto\AccessRulesArrayDto;
use SpsFW\Core\Auth\Instances\UserAbstract;
use SpsFW\Core\Route\RestController;
use SpsFW\Core\Validation\Enum\ParamsIn;

class AccessRuleController extends RestController
{

    public function __construct(
        #[Inject]
        private AccessRuleServiceI $accessRulesService,
    )
    {
        parent::__construct();
    }


    #[OA\Post(
        path: "/api/auth/add-access-rules",
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
    #[Route('/api/auth/add-access-rules', ['PATCH'])]
    #[Validate(ParamsIn::Json, AccessRulesArrayDto::class)]
    public function addAccessRules(AccessRulesArrayDto $accessRulesDto): UserAbstract
    {


        return $this->accessRulesService->addAccessRules($accessRulesDto);
    }

    #[OA\Post(
        path: "/api/auth/set-access-rules",
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
    #[Route('/api/auth/set-access-rules', ['POST'])]
    public function setAccessRules(#[JsonBody] AccessRulesArrayDto $accessRulesDto): UserAbstract
    {
        return $this->accessRulesService->setAccessRules($accessRulesDto);
    }

}