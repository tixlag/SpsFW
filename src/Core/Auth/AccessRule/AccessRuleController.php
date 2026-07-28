<?php

namespace SpsFW\Core\Auth\AccessRule;

use SpsFW\Core\Attributes\Inject;
use SpsFW\Core\Attributes\Route;
use SpsFW\Core\Attributes\Validation\JsonBody;
use SpsFW\Core\Attributes\Validation\Validate;
use SpsFW\Core\Auth\Dto\AccessRulesArrayDto;
use SpsFW\Core\Auth\Instances\UserAbstract;
use SpsFW\Core\Route\RestController;
use SpsFW\Core\Validation\Enum\ParamsIn;
use SpsFW\Core\Attributes\OpenApi\Response as ApiResponse;

class AccessRuleController extends RestController
{

    public function __construct(
        #[Inject]
        private AccessRuleServiceI $accessRulesService,
    )
    {
        parent::__construct();
    }


    #[Route('/api/auth/add-access-rules', ['PATCH'])]
    #[Validate(ParamsIn::Json, AccessRulesArrayDto::class)]
    #[ApiResponse(status: 200, description: 'OK')]
    public function addAccessRules(AccessRulesArrayDto $accessRulesDto): UserAbstract
    {
        return $this->accessRulesService->addAccessRules($accessRulesDto);
    }

    #[Route('/api/auth/set-access-rules', ['POST'])]
    #[ApiResponse(status: 200, description: 'OK')]
    public function setAccessRules(#[JsonBody] AccessRulesArrayDto $accessRulesDto): UserAbstract
    {
        return $this->accessRulesService->setAccessRules($accessRulesDto);
    }

}