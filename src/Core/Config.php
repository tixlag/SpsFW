<?php

namespace SpsFW\Core;

use SpsFW\Core\Auth\Users\Storages\UsersStorage;
use SpsFW\Core\Auth\Users\Storages\UsersStorageI;
use SpsFW\Core\Auth\Users\UsersService;
use SpsFW\Core\Auth\Users\UsersServiceI;

class Config
{
    private static $config = [];

    public static array $bindings = [
        UsersServiceI::class => UsersService::class,
        UsersStorageI::class => UsersStorage::class,
        // Добавляй другие интерфейсы здесь
    ];


    public static function get($key)
    {
        if (empty(self::$config)) {
            self::init();
        }
        return self::$config[$key];
    }

    public static function init(): void
    {
        $url = sprintf("%s://%s:%u", getenv('HTTP_SCHEME'), getenv('HOST'), getenv('PORT'));
        $host = sprintf("%s:%u", getenv('HOST'), getenv('PORT'));
        $now = time();
        self::$config = [
            'app' => [
                'name' => getenv('APP_NAME'),
                'version' => getenv('APP_VERSION'),
                'env' => getenv('APP_ENV'),
                'host' => $host,
                'url' => $url,
                'debugMode' => getenv('DEBUG_MODE'),
                'masterPassword' => getenv('MASTER_PASSWORD')
            ],
            'db' => [
                'adapter' => getenv('DB_ADAPTER'),
                'host' => getenv('DB_HOST'),
                'port' => getenv('DB_PORT'),
                'user' => getenv('DB_USER'),
                'password' => getenv('DB_PASS'),
                'dbname' => getenv('DB_NAME'),
                'debugMode' => getenv('DEBUG_MODE')
            ],
            'auth' => [
                'refreshTokenExpiresIn' => getenv('REFRESH_TOKEN_EXPIRES_IN'),
                'jwt' => [
                    'secret' => getenv('JWT_SECRET'),
                    'header' => [
                        "alg" => getenv('JWT_ALG'),
                        'typ' => 'JWT'
                    ],
                    'payload' => [
                        'exp' => $now + getenv('JWT_EXP_SECONDS'),
//                    'iss' => $url,
//                    'aud' => $url,
//                    'sub' => $url,
//                    'nbf' => $now,
//                    'iat' => $now,
//                    'jti' => uniqid(),
                    ],

                ],
            ],

        ];
    }

}