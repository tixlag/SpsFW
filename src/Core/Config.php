<?php

namespace SpsFW\Core;

class Config
{
    private static $config = [];

    public static function get($key)
    {
        if (empty(self::$config)) {
            self::init();
        }
        return self::$config[$key];
    }

    public static function init(): void
    {
        $host = sprintf("https://%s:%u", getenv('SPS_HOST'), getenv('SPS_PORT'));
        $now = time();
        self::$config = [
            'db' => [
                'host' => getenv('DB_HOST'),
                'user' => getenv('DB_USER'),
                'password' => getenv('DB_PASS'),
                'dbname' => getenv('DB_NAME'),
                'debugMode' => getenv('DEBUG_MODE')
            ],
            'jwt' => [
                'secret' => getenv('JWT_SECRET'),
                'exp' => $now + getenv('JWT_EXP_SECONDS'),
                'iss' => $host,
                'aud' => $host,
                'sub' => $host,
                'nbf' => $now,
                'iat' => $now,
                'jti' => uniqid(),
                'alg' => getenv('JWT_ALG')
            ],
            'MASTER_PASSWORD' => getenv('MASTER_PASSWORD')
        ];
    }

}