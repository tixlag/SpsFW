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
            if (!isset($dbConfigs[$configId])) {
                throw new \InvalidArgumentException("Database configuration '{$configId}' not found.");
            }
            $config = $dbConfigs[$configId];

            $username = $config['user'];
            $password = $config['password'];
            $debugMode = $config['debugMode'];

            static $dsns = [];
            if (!isset($dsns[$configId])) {
                $dsns[$configId] = sprintf("%s:host=%s;port=%u;dbname=%s;charset=UTF8",
                    $config['adapter'],
                    $config['host'],
                    $config['port'],
                    $config['dbname']);
            }

            self::$pdo[$configId] = new PDO(
                $dsns[$configId],
                $username,
                $password,
                array(
                    PDO::MYSQL_ATTR_INIT_COMMAND => "set names utf8",
                    PDO::ATTR_EMULATE_PREPARES => $debugMode && ini_get("xdebug.mode") !== "off"
                )
            );

            self::$pdo[$configId]->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            if ($debugMode) {
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