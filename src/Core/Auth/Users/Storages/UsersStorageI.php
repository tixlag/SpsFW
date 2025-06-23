<?php

namespace SpsFW\Core\Auth\Users\Storages;

use SpsFW\Core\Auth\Users\Models\User;

interface UsersStorageI
{
    public function register(User $user): bool;

    public function login(string $login, string $hashedPassword);

    public function getByLogin(string $login): User;

    public function logout();
}