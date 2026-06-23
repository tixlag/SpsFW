<?php

namespace SpsFW\Core\Db;

use PDO;
use SpsFW\Core\Config;

class Db
{
    /**
     * Объекты PDO для разных конфигураций
     * @var PDO[]
     */
    protected static array $pdo = [];
    /**
     * Возвращает объект PDO для дефолтной конфигурации
     * @return PDO
     */
    public static function get(): PDO
    {
        return self::getByConfig('db');
    }

    /**
     * Возвращает объект PDO по ID конфигурации
     * @param string $configId
     * @return PDO
     */
    public static function getByConfig(string $configId): PDO
    {
        if (!isset(self::$pdo[$configId])) {
            $dbConfigs = Config::get($configId);
            if (!isset($dbConfigs)) {
                throw new \InvalidArgumentException("Database configuration '{$configId}' not found.");
            }
            $config = $dbConfigs;

            $adapter   = $config['adapter'];
            $username  = $config['user'];
            $password  = $config['password'];
            $debugMode = $config['debugMode'];

            static $dsns = [];
            if (!isset($dsns[$configId])) {
                $dsns[$configId] = match ($adapter) {
                    'pgsql' => sprintf(
                        'pgsql:host=%s;port=%u;dbname=%s',
                        $config['host'], $config['port'], $config['dbname']
                    ),
                    'mysql', 'mariadb' => sprintf(
                        'mysql:host=%s;port=%u;dbname=%s;charset=utf8mb4',
                        $config['host'], $config['port'], $config['dbname']
                    ),
                    default => throw new \InvalidArgumentException("Unsupported DB adapter: {$adapter}"),
                };
            }

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];

            if (in_array($adapter, ['mysql', 'mariadb'], true)) {
                $options[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES utf8mb4";
                // Профилирование только для MySQL/MariaDB
                if ($debugMode) {
                    $options[PDO::ATTR_EMULATE_PREPARES] = ini_get('xdebug.mode') !== 'off';
                }
            }

            self::$pdo[$configId] = $adapter === 'pgsql' && class_exists(\Pdo\Pgsql::class)
                ? new \Pdo\Pgsql($dsns[$configId], $username, $password, $options)
                : new PDO($dsns[$configId], $username, $password, $options);

            if ($debugMode && in_array($adapter, ['mysql', 'mariadb'], true)) {
                self::$pdo[$configId]->query("SET profiling = 1;");
                self::$pdo[$configId]->query("SET @@profiling_history_size = 100;");
            }
        }

        return self::$pdo[$configId];
    }

    /**
     * Singleton - отключение возможности создавать объект
     */
    private function __construct()
    {
    }

    /**
     * Singleton - отключение возможности клонировать объект
     * @return void
     */
    private function __clone(): void
    {
    }
}
