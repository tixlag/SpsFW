<?php

namespace SpsFW\Core;

use SpsFW\Core\Auth\AccessRule\AccessRuleService;
use SpsFW\Core\Auth\AccessRule\AccessRuleServiceI;
use SpsFW\Core\Auth\AccessRule\AccessRuleStorage;
use SpsFW\Core\Auth\AccessRule\AccessRuleStorageI;
use SpsFW\Core\Auth\AuthToken\AuthTokenStorage;
use SpsFW\Core\Auth\AuthToken\AuthTokenStorageI;

class Config
{
    private static array $config = [];

    public static array $bindings = [
        AuthTokenStorageI::class => AuthTokenStorage::class,
        AccessRuleServiceI::class => AccessRuleService::class,
        AccessRuleStorageI::class => AccessRuleStorage::class,
    ];

    public static function get($key)
    {
        return self::$config[$key];
    }

    public static function init(array $customConfig = []): void
    {
        $url = sprintf("%s://%s:%u", $_ENV['HTTP_SCHEME'], $_ENV['HOST'], $_ENV['PORT']);
        $host = !empty($_ENV['PORT']) ? sprintf("%s:%u", $_ENV['HOST'], $_ENV['PORT']) : $_ENV['HOST'];
        $now = time();
        $baseConfig = [
            'app' => [
                'name' => $_ENV['APP_NAME'],
                'version' => $_ENV['APP_VERSION'],
                'env' => $_ENV['APP_ENV'],
                'host' => $host,
                'url' => $url,
                'debugMode' => $_ENV['DEBUG_MODE'],
                'masterPassword' => $_ENV['MASTER_PASSWORD']
            ],
            'db' => [
                'adapter' => $_ENV['DB_ADAPTER'],
                'host' => $_ENV['DB_HOST'],
                'port' => $_ENV['DB_PORT'],
                'user' => $_ENV['DB_USER'],
                'password' => $_ENV['DB_PASS'],
                'dbname' => $_ENV['DB_NAME'],
                'debugMode' => $_ENV['DEBUG_MODE']
            ],
            'auth' => [
                'refreshTokenExpiresIn' => $_ENV['REFRESH_TOKEN_EXPIRES_IN'],
                'jwt' => [
                    'secret' => $_ENV['JWT_SECRET'],
                    'header' => [
                        "alg" => $_ENV['JWT_ALG'],
                        'typ' => 'JWT'
                    ],
                    'payload' => [
                        'exp' => $now + $_ENV['JWT_EXP_SECONDS'],
                    ],
                ],
            ],
        ];

        self::$config = array_merge_recursive($baseConfig, $customConfig);
    }

    public static function setDIBindings(array $bindings)
    {
        self::$bindings = self::$bindings + $bindings;
    }
}