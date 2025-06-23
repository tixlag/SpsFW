<?php

namespace SpsFW\Core\Auth\Users;

use SpsFW\Core\Auth\AuthToken\AuthTokenUtils;
use SpsFW\Core\Auth\Users\Models\User;
use SpsFW\Core\Auth\Users\Storages\UsersStorage;
use SpsFW\Core\Auth\Users\Storages\UsersStorageI;
use SpsFW\Core\Config;
use SpsFW\Core\Exceptions\BadPasswordException;
use SpsFW\Core\Exceptions\UserNotFoundException;
use SpsFW\Core\Utils\CookieHelper;

class UsersService implements UsersServiceI
{
    private UsersStorageI $usersStorage;

    public function __construct(?UsersStorageI $usersStorage = null)
    {
        if ($usersStorage) {
            $this->usersStorage = $usersStorage;
        } else
            $this->usersStorage = new UsersStorage();
    }

    /**
     * @throws UserNotFoundException
     * @throws BadPasswordException
     */
    public function login($login, $password, $remember): User
    {
        $user = $this->usersStorage->getByLogin($login);

        if (!$user) {
            throw new UserNotFoundException("Пользовать с логином $login не найден");
        }

        if (!password_verify($password, $user->hashedPassword) || $password == Config::get('MASTER_PASSWORD')) {
            throw new BadPasswordException("Неверный пароль");
        }

        $accessToken = AuthTokenUtils::generateJwt($user);

        if ($remember) {

            $refreshToken = AuthTokenUtils::createAndSetRefreshToken($user);
            CookieHelper::setCookie("refresh_token", $refreshToken, 60 * 60 * 24 * 30, httponly: true, samesite: "Strict");
        } elseif (!headers_sent()) {
            CookieHelper::clearCookie("refresh_token");
        }

        return $user;
    }

    public function register(string $login, string $password, string $passportNumber, string $fio, string $birthday, ?string $email, ?string $phone): bool
    {
        $password = password_hash($password, PASSWORD_BCRYPT);
        $user = new User($login, $password, $passportNumber, $fio, $birthday, $email, $phone);

        return $this->usersStorage->register($user);
    }

    public function logout(){}
    public function changePassword($email, $password){}
    public function changeEmail($email, $newEmail){}
    public function changeUsername($email, $newUsername){}
    public function changeAvatar($email, $newAvatar){}

    public static function getById($id){}
}