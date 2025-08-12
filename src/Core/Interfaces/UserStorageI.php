<?php

namespace SpsFW\Core\Interfaces;

use SpsFW\Core\Auth\Instances\UserAbstract;

/**
 *
 */
interface UserStorageI
{

    /**
     * @param string $login
     * @return UserAbstract|null
     */
    public function getByLogin(string $login): ?UserAbstract;

    /**
     * @param string $id
     * @return UserAbstract|null
     */
    public function getById(string $id): ?UserAbstract;

    /**
     * @param string $userUuid
     * @return mixed
     */
    public function getByUuid(string $userUuid): ?UserAbstract;

    /**
     * @param string $userId
     * @param array $accessRules
     * @return bool
     */
    public function addAccessRules(string $userId, array $accessRules): bool;

    /**
     * @param string $userId
     * @param array $accessRules
     * @return bool
     */
    public function setAccessRules(string $userId, array $accessRules): bool;

    /**
     * Возвращает пользователя с назначенным uuid
     * @param UserAbstract $user
     * @return UserAbstract
     */
    public function create(UserAbstract $user): UserAbstract;
}