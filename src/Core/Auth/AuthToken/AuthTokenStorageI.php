<?php

namespace SpsFW\Core\Auth\AuthToken;

use DateMalformedIntervalStringException;
use SpsFW\Core\Auth\AccessRules\Models\UserAbstract;

interface AuthTokenStorageI
{
    /**
     * @param UserAbstract $user
     * @param string $selector
     * @param string $hashedToken
     * @param int $expiresIn
     * @return UserAbstract
     * @throws DateMalformedIntervalStringException
     */
    public function addRefreshToken(
        UserAbstract $user,
        string $selector,
        string $hashedToken,
        int $expiresIn
    ): UserAbstract;

    public function getAndDeleteRefreshToken(string $selector);
}