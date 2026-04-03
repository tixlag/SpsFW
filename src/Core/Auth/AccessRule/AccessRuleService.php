<?php

namespace SpsFW\Core\Auth\AccessRule;

use SpsFW\Core\Attributes\Inject;
use SpsFW\Core\Auth\AuthServiceI;
use SpsFW\Core\Auth\Dto\AccessRulesArrayDto;
use SpsFW\Core\Auth\Instances\UserAbstract;
use SpsFW\Core\Interfaces\UserServiceI;

class AccessRuleService implements AccessRuleServiceI
{

    public function __construct(
        #[Inject]
        private AccessRuleStorageI $accessRulesStorage,
        #[Inject]
        private UserServiceI $usersService,
        #[Inject]
        private AuthServiceI $authTokenService)
    {
    }



    public function extractAccessRules(string $userUuid): array
    {
        return $this->accessRulesStorage->extractAccessRules($userUuid);
    }

    /**
     * @param AccessRulesArrayDto $accessRulesDto
     * @return UserAbstract|null
     */
    public function addAccessRules($accessRulesDto): ?UserAbstract
    {
        $accessRules = [];
        foreach ($accessRulesDto->rules as $rule) {
            $accessRules += $rule->toArray();
        }
        $user = $this->usersService->getByUuid($accessRulesDto->userUuid);
        $isAdded = $this->accessRulesStorage->addAccessRules($user->uuid, $accessRules);
        if ($isAdded) {
            $accessRules = $this->extractAccessRules($user->uuid);
            $user->addAccessRules($accessRules);
        }

        $this->authTokenService->sendNewJwtToken($user);
        return $user;
    }


    /**
     * @param AccessRulesArrayDto $accessRulesDto
     * @return UserAbstract|null
     */
    public function setAccessRules($accessRulesDto): ?UserAbstract
    {
        $accessRules = [];
        foreach ($accessRulesDto->rules as $rule) {
            $accessRules += $rule->toArray();
        }
        $user = $this->usersService->getByUuid($accessRulesDto->userUuid);
        $isSet = $this->accessRulesStorage->setAccessRules($user->uuid, $accessRules);
        if ($isSet) {
            $user->setAccessRules($accessRules);
            $this->authTokenService->sendNewJwtToken($user);
        }
        return $user;
    }





}