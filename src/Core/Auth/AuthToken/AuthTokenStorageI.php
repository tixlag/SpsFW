<?php

namespace SpsFW\Core\Auth\AuthToken;

use DateMalformedIntervalStringException;
use SpsFW\Core\Auth\Instances\UserAbstract;

interface AuthTokenStorageI
{
    /**
     * @param \SpsFW\Core\Auth\Instances\UserAbstract $user
     * @param string $selector
     * @param string $hashedToken
     * @param int $expiresIn
     * @return \SpsFW\Core\Auth\\SpsFW\Core\Auth\Instances\UserAbstract
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