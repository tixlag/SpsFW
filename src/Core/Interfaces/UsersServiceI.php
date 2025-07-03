<?php

namespace SpsFW\Core\Interfaces;

use SpsFW\Core\Auth\AccessRules\Models\UserAbstract;

interface UsersServiceI
{
    public function login($login, $password, $remember);

    public function register(UserAbstract $user): UserAbstract;

    public function changePassword($email, $password);

    public function changeEmail($email, $newEmail);

    public function changeUsername($email, $newUsername);

    public function changeAvatar($email, $newAvatar);

    public function getById(string $userId): UserAbstract;

    public function getByLogin(string $login): UserAbstract;

}