<?php

namespace SpsFW\Core\Auth;

use DateMalformedIntervalStringException;
use DateMalformedStringException;
use Random\RandomException;
use SpsFW\Core\Auth\AccessRules\Models\UserAbstract;
use SpsFW\Core\Exceptions\AuthorizationException;
use SpsFW\Core\Exceptions\BadPasswordException;
use SpsFW\Core\Exceptions\UserNotFoundException;
use SpsNew\Users\Models\User;

interface AuthServiceI
{
    /**
     * @param \SpsNew\Users\Models\User $user
     * @return void
     * @throws RandomException
     * @throws DateMalformedIntervalStringException
     * @warning вернет токен в Cookies!
     */
    public function addNewRefreshToken(UserAbstract $user): void;

    public function getAndDeleteRefreshToken(string $selector): array;

    /**
     * @param UserAbstract $user
     * @return void
     */
    public function sendNewJwtToken(UserAbstract $user): void;

    /**
     * @throws RandomException
     * @throws DateMalformedStringException
     * @throws AuthorizationException
     * @throws DateMalformedIntervalStringException
     */
    public function updateTokens(string $refreshToken): ?UserAbstract;

    /**
     * @throws BadPasswordException
     * @throws UserNotFoundException
     * @throws RandomException
     * @throws DateMalformedIntervalStringException
     */
    public function login($login, $password, $remember): UserAbstract;

    /**
     * @throws RandomException
     * @throws DateMalformedIntervalStringException
     */
    public function register(
        string $login,
        string $password,
        string $passportNumber,
        string $fio,
        string $birthday,
        ?string $email,
        ?string $phone
    ): UserAbstract;

    public function logout(): void;
}