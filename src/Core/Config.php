<?php

namespace SpsFW\Core;

use SpsFW\Core\Auth\AccessRules\AccessRulesService;
use SpsFW\Core\Auth\AccessRules\AccessRulesServiceI;
use SpsFW\Core\Auth\AccessRules\AccessRulesStorage;
use SpsFW\Core\Auth\AccessRules\AccessRulesStorageI;
use SpsFW\Core\Auth\AccessRules\Instances\MasterRules;
use SpsFW\Core\Auth\AccessRules\Instances\PtoRules;
use SpsFW\Core\Auth\AccessRules\Instances\SystemRules;
use SpsFW\Core\Auth\AccessRules\Util\AccessRulesRegistry;
use SpsFW\Core\Auth\AuthService;
use SpsFW\Core\Auth\AuthServiceI;
use SpsFW\Core\Auth\AuthToken\AuthTokenStorage;
use SpsFW\Core\Auth\AuthToken\AuthTokenStorageI;



class Config
{
    private static $config = [];

    public static array $bindings = [
        AuthServiceI::class => AuthService::class,
        AuthTokenStorageI::class => AuthTokenStorage::class,
        AccessRulesServiceI::class => AccessRulesService::class,
        AccessRulesStorageI::class => AccessRulesStorage::class,
        // Добавляй другие интерфейсы здесь, чтобы DI мог выдать конкретные реализации
    ];


    public static function get($key)
    {
//        if (empty(self::$config)) {
//            self::init();
//        }
        return self::$config[$key];
    }

    public static function init(array $customConfig = []): void
    {
        AccessRulesRegistry::register([
            SystemRules::class,
            MasterRules::class,
            PtoRules::class,
        ]);

        $url = sprintf("%s://%s:%u", getenv('HTTP_SCHEME'), getenv('HOST'), getenv('PORT'));
        $host = sprintf("%s:%u", getenv('HOST'), getenv('PORT'));
        $now = time();
        $baseConfig = [
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