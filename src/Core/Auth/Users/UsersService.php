<?php

namespace SpsFW\Core\Auth\Users;

use DateMalformedStringException;
use Random\RandomException;
use SpsFW\Core\AccessRules\AccessRulesService;
use SpsFW\Core\Auth\AuthToken\AuthTokenService;
use SpsFW\Core\Auth\AuthToken\AuthTokenStorage;
use SpsFW\Core\Auth\Users\Models\Auth;
use SpsFW\Core\Auth\Users\Models\User;
use SpsFW\Core\Auth\Users\Models\UserAuthI;
use SpsFW\Core\Auth\Users\Storages\UsersStorage;
use SpsFW\Core\Auth\Users\Storages\UsersStorageI;
use SpsFW\Core\Config;
use SpsFW\Core\Exceptions\AuthorizationException;
use SpsFW\Core\Exceptions\BadPasswordException;
use SpsFW\Core\Exceptions\UserNotFoundException;
use SpsFW\Core\Utils\CookieHelper;

class UsersService implements UsersServiceI
{
    private UsersStorageI $usersStorage;

    private AuthTokenService $authTokenService;

    private AccessRulesService $accessRulesService;

    public function __construct(?UsersStorageI $usersStorage = null, ?AuthTokenService $authTokenService = null, ?AccessRulesService $accessRulesService = null)
    {
            $this->usersStorage = $usersStorage ?? new UsersStorage();
            $this->authTokenService = $authTokenService ?? new AuthTokenService();
            $this->accessRulesService = $accessRulesService ?? new AccessRulesService();
    }

    /**
     * @throws UserNotFoundException
     * @throws BadPasswordException
     * @throws RandomException
     */
    public function login($login, $password, $remember): User
    {
        $user = $this->usersStorage->getByLogin($login);

        if (!$user) {
            throw new UserNotFoundException("Пользовать с логином $login не найден");
        }

        if (!password_verify($password, $user->hashedPassword) || $password == Config::get('app')['masterPassword']) {
            throw new BadPasswordException("Неверный пароль");
        }

        $accessRules = $this->accessRulesService->extractAccessRules($user->id);

        $user->setAccessRules($accessRules);

        if ($remember) {
            $this->authTokenService->addNewRefreshToken($user);
        } elseif (!headers_sent()) {
            CookieHelper::clearCookie("refresh_token");
        }

        $this->authTokenService->sendNewJwtToken($user);

        return $user;
    }

    /**
     * @throws RandomException
     */
    public function register(
        string $login,
        string $password,
        string $passportNumber,
        string $fio,
        string $birthday,
        ?string $email,
        ?string $phone
    ): User {
        $password = password_hash($password, PASSWORD_BCRYPT);
        $user = new User(login: $login, code_1c: null, hashedPassword: $password, passport:  $passportNumber, fio: $fio, birthday:  $birthday, email:  $email, phone:  $phone);

        $user = $this->usersStorage->register($user);

        $this->authTokenService->addNewRefreshToken($user);
        $this->authTokenService->sendNewJwtToken($user);

        return $user;
    }



    public function changePassword($email, $password)
    {
    }

    public function changeEmail($email, $newEmail)
    {
    }

    public function changeUsername($email, $newUsername)
    {
    }

    public function changeAvatar($email, $newAvatar)
    {
    }


    /**
     * @throws RandomException
     * @throws DateMalformedStringException
     * @throws AuthorizationException
     */
    public function updateTokens(string $refreshToken): ?UserAuthI
    {

        list($selectorHex, $verifier) = explode('.', $refreshToken, 2);
        $selectorBinary = hex2bin($selectorHex);

        $refreshTokenInfo = $this->authTokenService->getAndDeleteRefreshToken($selectorBinary);

        if (AuthTokenService::checkRefreshToken($refreshTokenInfo, $verifier)) {
            $userId = $refreshTokenInfo['user_id'];

            // если в header нет AccessToken, то получаем пользователя из базы
            $user = Auth::getUnsafe() ?? $this->usersStorage->getById($userId);

            $this->authTokenService->addNewRefreshToken($user);
            $this->authTokenService->sendNewJwtToken($user);

        }

        return $user ?? null;
    }


    public function addAccessRules(string $userId, array $accessRules): ?UserAuthI
    {
        $user = $this->usersStorage->getById($userId);
        $isAdded = $this->usersStorage->addAccessRules($user->id, $accessRules);
        if ($isAdded) {
            $user->addAccessRules($accessRules);
        }
        $this->authTokenService->sendNewJwtToken($user);
        return $user;
    }

    public function setAccessRules(string $userId, array $accessRules): ?UserAuthI
    {
        $user = $this->usersStorage->getById($userId);
        $isSet = $this->usersStorage->setAccessRules($user->id, $accessRules);
        if ($isSet) {
            $user->setAccessRules($accessRules);
            $this->authTokenService->sendNewJwtToken($user);
        }
        return $user;
    }

    public function getById(string $userId): UserAuthI
    {
        return $this->usersStorage->getById($userId);
    }


}