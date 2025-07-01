<?php

namespace SpsFW\Core\Auth\Users\Storages;

use SpsFW\Core\Auth\Users\Models\User;

interface UsersStorageI
{
    /**
     * Возвращает пользователя с назначенным uuid
     * @param User $user
     * @return User
     */
    public function register(User $user): User;

    public function getByLogin(string $login): ?User;

    public function getById(string $id): ?User;

    public function addAccessRules(string $userId, array $accessRules): bool;

    public function setAccessRules(string $userId, array $accessRules): bool;
}