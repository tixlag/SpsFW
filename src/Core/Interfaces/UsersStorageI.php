<?php

namespace SpsFW\Core\Interfaces;

use SpsFW\Core\Auth\AccessRules\Models\UserAbstract;
use SpsNew\Users\Models\User;

interface UsersStorageI
{
    /**
     * Возвращает пользователя с назначенным uuid
     * @param \SpsNew\Users\Models\User $user
     * @return User
     */
    public function register(UserAbstract $user): UserAbstract;

    public function getByLogin(string $login): ?UserAbstract;

    public function getById(string $id): ?UserAbstract;

    public function addAccessRules(string $userId, array $accessRules): bool;

    public function setAccessRules(string $userId, array $accessRules): bool;
}