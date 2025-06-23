<?php

namespace SpsFW\Core\Auth\Users;

interface UsersServiceI
{
    public function login($login, $password, $remember);

    public function register(string $login, string $password, string $passportNumber, string $fio, string $birthday, ?string $email, ?string $phone);

    public function logout();

    public function changePassword($email, $password);

    public function changeEmail($email, $newEmail);

    public function changeUsername($email, $newUsername);

    public function changeAvatar($email, $newAvatar);

    public static function getById($id);
}