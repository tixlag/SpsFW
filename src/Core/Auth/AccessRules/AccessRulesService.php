<?php

namespace SpsFW\Core\Auth\AccessRules;

use SpsFW\Core\Attributes\Inject;
use SpsFW\Core\Auth\AccessRules\Models\UserAbstract;
use SpsFW\Core\Auth\AuthServiceI;
use SpsFW\Core\Interfaces\UsersServiceI;

class AccessRulesService implements AccessRulesServiceI
{

    public function __construct(
        #[Inject]
        private AccessRulesStorageI $accessRulesStorage,
        #[Inject]
        private UsersServiceI $usersService,
        #[Inject]
        private AuthServiceI $authTokenService)
    {
    }



    public function extractAccessRules(string $userId): array
    {
        return $this->accessRulesStorage->extractAccessRules($userId);
    }

    public function addAccessRules(string $userId, array $accessRules): ?UserAbstract
    {
        $user = $this->usersService->getById($userId);
        $isAdded = $this->accessRulesStorage->addAccessRules($user->id, $accessRules);
        if ($isAdded) {
            $user->addAccessRules($accessRules);
        }
        $this->authTokenService->sendNewJwtToken($user);
        return $user;
    }


    public function setAccessRules(string $userId, array $accessRules): ?UserAbstract
    {
        $user = $this->usersService->getById($userId);
        $isSet = $this->accessRulesStorage->setAccessRules($user->id, $accessRules);
        if ($isSet) {
            $user->setAccessRules($accessRules);
            $this->authTokenService->sendNewJwtToken($user);
        }
        return $user;
    }





}