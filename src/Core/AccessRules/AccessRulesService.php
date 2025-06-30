<?php

namespace SpsFW\Core\AccessRules;

use SpsFW\Core\Auth\AuthToken\AuthTokenService;
use SpsFW\Core\Auth\Users\Models\UserAuthI;
use SpsFW\Core\Auth\Users\UsersService;
use SpsFW\Core\Auth\Users\UsersServiceI;
use SpsFW\Core\Utils\CookieHelper;

class AccessRulesService
{

    private ?AccessRulesStorage $accessRulesStorage;

    private UsersServiceI $usersService;
    private AuthTokenService $authTokenService;


    public function __construct(?AccessRulesStorage $accessRulesStorage = null, ?UsersServiceI $usersService = null, ?AuthTokenService $authTokenService = null)
    {
        $this->accessRulesStorage = $accessRulesStorage ?? new AccessRulesStorage();
        $this->usersService = $usersService ?? new UsersService();
        $this->authTokenService = $authTokenService ?? new AuthTokenService();

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