<?php

namespace SpsFW\Core;

use SpsFW\Core\Router\Router;

class Bootstrap
{
    private static ?Router $router = null;

    public static function getRouter(): Router
    {
        if (self::$router === null) {
            self::$router = new Router();
            \SpsFW\Core\Router\DICacheBuilder::compileDI(self::$router->container);
        }

        return self::$router;
    }

    /**
     * Загружает переменные окружения из .env файла в $_ENV.
     * Игнорирует строки-комментарии (#) и строки без '='.
     * Не перезаписывает уже установленные переменные.
     *
     * Пример использования в bootstrap.php проекта:
     *   Bootstrap::loadEnv(__DIR__ . '/.env');
     *   Bootstrap::loadEnv(__DIR__ . '/.env.' . ($_ENV['APP_ENV'] ?? 'dev'));
     */
    public static function loadEnv(string $filePath): void
    {
        if (!file_exists($filePath)) {
            return;
        }

        foreach (file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if ($key !== '' && !isset($_ENV[$key])) {
                $_ENV[$key] = $value;
            }
        }
    }
}