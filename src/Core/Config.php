<?php

namespace SpsFW\Core;

use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use SpsFW\Core\Auth\AccessRule\AccessRuleService;
use SpsFW\Core\Auth\AccessRule\AccessRuleServiceI;
use SpsFW\Core\Auth\AccessRule\AccessRuleStorage;
use SpsFW\Core\Auth\AccessRule\AccessRuleStorageI;
use SpsFW\Core\Auth\AuthToken\AuthTokenStorage;
use SpsFW\Core\Auth\AuthToken\AuthTokenStorageI;
use SpsFW\Core\Psr\Cache\FileCache;
use SpsFW\Core\Psr\MonologLogger;
use SpsFW\Core\Redis\RedisClient;

class Config
{
    private static array $config = [];

    public static array $bindings = [
        AuthTokenStorageI::class => AuthTokenStorage::class,
        AccessRuleServiceI::class => AccessRuleService::class,
        AccessRuleStorageI::class => AccessRuleStorage::class,
        CacheInterface::class => FileCache::class,
        LoggerInterface::class => MonologLogger::class,
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
            'redis' => [
                'host'     => $_ENV['REDIS_HOST']     ?? '127.0.0.1',
                'port'     => (int) ($_ENV['REDIS_PORT'] ?? 6379),
                'password' => $_ENV['REDIS_PASSWORD'] ?? null,
                'database' => (int) ($_ENV['REDIS_DB'] ?? 0),
                'timeout'  => (float) ($_ENV['REDIS_TIMEOUT'] ?? 2.0),
            ],
        ];

        self::$config = array_merge($baseConfig, $customConfig);

        // Lazy binding: RedisClient available via #[Inject] without manual di_config.php entry.
        // Connection is established only on first actual use, not at container build time.
        self::$bindings[RedisClient::class] = fn() => RedisClient::getInstance();
    }

    /**
     * Устанавливает привязки зависимостей.
     *
     * @param array<string, string|object|array|\Closure> $bindings
     *   - 'Interface::class' => 'Concrete::class'          → стандартная привязка
     *   - 'Interface::class' => new Concrete()             → инстанс
     *   - 'Interface::class' => ['class' => ..., 'args' => [...]] → с аргументами
     *   - 'Interface::class' => fn() => Concrete::getInstance() → lazy callable (singleton)
     */
    public static function setDIBindings(array $bindings): void
    {
        self::$bindings = array_merge(self::$bindings, $bindings);
    }

    /**
     * Получает привязку по ключу
     */
    public static function getDIBinding(string $abstract): string|object|array|null
    {
        return self::$bindings[$abstract] ?? null;
    }
}
