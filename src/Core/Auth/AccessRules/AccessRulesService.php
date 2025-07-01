<?php

namespace SpsFW\Core\Auth\AccessRules;

use SpsFW\Core\Attributes\Inject;
use SpsFW\Core\Auth\AuthToken\AuthTokenService;
use SpsFW\Core\Auth\Users\Models\UserAuthI;
use SpsFW\Core\Auth\Users\UsersService;
use SpsFW\Core\Auth\Users\UsersServiceI;
use SpsFW\Core\Utils\CookieHelper;

class AccessRulesService
{

    public function __construct(
        #[Inject]
        private ?AccessRulesStorage $accessRulesStorage = null,
        #[Inject]
        private ?UsersServiceI $usersService = null,
        #[Inject]
        private ?AuthTokenService $authTokenService = null)
    {
    }

    public function logout(): void
    {
        $refreshToken = $_COOKIE['refresh_token'];
        $this->authTokenService->getAndDeleteRefreshToken($refreshToken);
        CookieHelper::clearCookie('refresh_token');
    }

    public function extractAccessRules(string $userId): array
    {
        return $this->accessRulesStorage->extractAccessRules($userId);
    }

    public function addAccessRules(string $userId, array $accessRules): ?UserAuthI
    {
        $user = $this->usersService->getById($userId);
        $isAdded = $this->accessRulesStorage->addAccessRules($user->id, $accessRules);
        if ($isAdded) {
            $user->addAccessRules($accessRules);
        }
        $this->authTokenService->sendNewJwtToken($user);
        return $user;
    }


    public function setAccessRules(string $userId, array $accessRules): ?UserAuthI
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