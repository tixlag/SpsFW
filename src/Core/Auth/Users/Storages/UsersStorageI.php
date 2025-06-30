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

    public function logout();

    public function login(string $login, string $hashedPassword): User;

    /**
     * @param string $userId
     * @return array<int, mixed>
     */
    public function extractAccessRules(string $userId): array;

    public function addAccessRules(string $userId, array $accessRules): bool;

    public function setAccessRules(string $userId, array $accessRules): bool;
}