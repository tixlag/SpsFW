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

    //todo вынести в модуль аутентификации
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





    public function getById(string $userId): UserAuthI
    {
        return $this->usersStorage->getById($userId);
    }


}