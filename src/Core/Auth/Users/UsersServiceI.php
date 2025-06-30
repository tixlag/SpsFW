<?php

namespace SpsFW\Core\Auth\Users;

use SpsFW\Core\Auth\Users\Models\User;
use SpsFW\Core\Auth\Users\Models\UserAuthI;

interface UsersServiceI
{
    public function login($login, $password, $remember);

    public function register(string $login, string $password, string $passportNumber, string $fio, string $birthday, ?string $email, ?string $phone);
    public function updateTokens(string $refreshToken);

    public function logout();

    public function changePassword($email, $password);

    public function changeEmail($email, $newEmail);

    public function changeUsername($email, $newUsername);

    public function changeAvatar($email, $newAvatar);

    public function getById(string $userId): UserAuthI;

    public function addAccessRules(string $userId, array $accessRules): ?UserAuthI;
    public function setAccessRules(string $userId, array $accessRules): ?UserAuthI;

}