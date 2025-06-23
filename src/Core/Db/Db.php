<?php

namespace SpsFW\Core\Db;

use PDO;
use SpsFW\Core\Config;

class Db
{
    /**
     * Объект содержит объект PDO
     * @var PDO|null
     */
    protected static PDO|null $pdo = null;
    /**
     * Возвращает объект PDO
     * @return PDO
     */
    public static function get(): PDO
    {
        if (!isset(self::$pdo)) {
            $config = Config::get('db');
            $dbHost = $config['host'];
            $dbPort = $config['port'];
            $username = $config['username'];
            $password = $config['password'];
            $dbName = $config['dbname'];
            $debugMode = $config['debugMode'];

            self::$pdo = new PDO(
                sprintf("mysql:host=%s;port=%u;dbname=%s;charset=UTF8", $dbHost, $dbPort, $dbName),
                $username,
                $password,
                array(
                    PDO::MYSQL_ATTR_INIT_COMMAND => "set names utf8",
                    PDO::ATTR_EMULATE_PREPARES => $debugMode && ini_get("xdebug.mode") !== "off"
                )
            );

            self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            if ($debugMode) {
                self::$pdo->query("SET profiling = 1;");
                self::$pdo->query("SET @@profiling_history_size = 100;");
            }
        }

        return self::$pdo;
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