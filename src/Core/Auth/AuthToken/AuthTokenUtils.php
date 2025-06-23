<?php

namespace SpsFW\Core\Auth\AuthToken;

use SpsFW\Core\Auth\Users\Models\User;
use SpsFW\Core\Config;

class AuthTokenUtils
{


    public static function getRefreshToken($refreshToken)
    {

    }

    public static function createAndSetRefreshToken(User $user): string
    {
        $refreshToken = self::generateRefreshToken();
        AuthTokenStorage::setRefreshToken($user, $refreshToken);
        $user->setRefreshToken($refreshToken);

        return $refreshToken;
    }

    private static function generateRefreshToken(): string
    {
        return md5(uniqid());
    }

    public static function generateJwt(User $user): string
    {
        $payload = Config::get('jwt');
        $payload['id'] = $user->id;
        $payload['accessRules'] = $user->accessRules;

        return JWT::encode($payload, Config::get('jwt.secret'));

    }

}