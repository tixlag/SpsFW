<?php

namespace SpsFW\Core\Router;

class PathManager
{
    private static ?string $projectRoot = null;
    private static ?string $libraryRoot = null;

    /**
     * Получить корень проекта (где находится composer.json)
     */
    public static function getProjectRoot(): string
    {
        if (static::$projectRoot === null) {
            static::$projectRoot = self::findProjectRoot(__DIR__);

            // Debug информация
            if (defined('DEBUG_PATHS')) {
                error_log("PathManager: Library root = " . self::getLibraryRoot());
                error_log("PathManager: Project root = " . self::$projectRoot);
            }
        }
        return static::$projectRoot;
    }

    /**
     * Получить корень библиотеки
     */
    public static function getLibraryRoot(): string
    {
        if (self::$libraryRoot === null) {
            self::$libraryRoot = dirname(__DIR__);
        }
        return self::$libraryRoot;
    }

    /**
     * Получить путь к src проекта
     */
    public static function getSrcPath(): string
    {
        return self::getProjectRoot() . '/src';
    }

    /**
     * Получить путь к кешу проекта
     */
    public static function getCachePath(): string
    {
        return self::getProjectRoot() . '/.cache';
    }

    /**
     * Получить путь к var директории
     */
    public static function getVarPath(): string
    {
        return self::getProjectRoot() . '/var';
    }

    /**
     * Получить путь к config директории
     */
    public static function getConfigPath(): string
    {
        return self::getProjectRoot() . '/config';
    }

    /**
     * Получить путь к public директории
     */
    public static function getPublicPath(): string
    {
        return self::getProjectRoot() . '/public';
    }

    /**
     * Получить путь к vendor директории
     */
    public static function getVendorPath(): string
    {
        return self::getProjectRoot() . '/vendor';
    }

    /**
     * Получить путь к контроллерам библиотеки
     */
    public static function getLibraryControllersPath(): string
    {
        return self::getLibraryRoot();
    }

    /**
     * Получить все директории с контроллерами (только существующие)
     */
    public static function getControllersDirs(): array
    {
        $dirs = [];

        // Добавляем директорию контроллеров библиотеки если существует
        $libraryControllers = self::getLibraryControllersPath();
        if (is_dir($libraryControllers)) {
            $dirs[] = $libraryControllers;
        }

        // Добавляем src проекта если существует
        $srcPath = self::getSrcPath();
        if (is_dir($srcPath)) {
            $dirs[] = $srcPath;
        }

        return array_reverse($dirs);
    }

    /**
     * Найти корень проекта по composer.json
     */
    private static function findProjectRoot(string $startPath): string
    {
        $path = realpath($startPath);

        // Сначала проверяем, находимся ли мы в vendor (через symlink)
        $vendorPath = self::findVendorPath($startPath);
        if ($vendorPath) {
            return dirname($vendorPath);
        }

        // Если библиотека в dev-режиме, определяем по структуре
        // Библиотека лежит в /var/www/SpsFW, проект в /var/www/html
        $libraryRoot = realpath(__DIR__ . '/../../..');

        // Если мы в /var/www/SpsFW, то проект должен быть в /var/www/html
        if (basename($libraryRoot) === 'SpsFW') {
            $parentDir = dirname($libraryRoot);
            $htmlDir = $parentDir . '/html';

            if (is_dir($htmlDir) && file_exists($htmlDir . '/composer.json')) {
                return realpath($htmlDir);
            }
        }

        // Более общий поиск в соседних директориях
        $parentDir = dirname($libraryRoot);
        if (is_dir($parentDir)) {
            $dirs = scandir($parentDir);
            foreach ($dirs as $dir) {
                if ($dir === '.' || $dir === '..' || $dir === basename($libraryRoot)) {
                    continue;
                }

                $possibleProjectRoot = $parentDir . '/' . $dir;
                if (is_dir($possibleProjectRoot) &&
                    file_exists($possibleProjectRoot . '/composer.json')) {
                    return realpath($possibleProjectRoot);
                }
            }
        }

        // Fallback - стандартный поиск вверх
        $path = realpath($startPath);
        while ($path !== '/' && $path !== false) {
            if (file_exists($path . '/composer.json')) {
                return $path;
            }
            $path = dirname($path);
        }

        throw new \RuntimeException('Не удалось найти корень проекта (composer.json не найден)');
    }

    /**
     * Найти vendor директорию
     */
    private static function findVendorPath(string $startPath): ?string
    {
        $path = $startPath;
        while ($path !== '/' && $path !== false) {
            if (basename($path) === 'vendor') {
                return $path;
            }
            $path = dirname($path);
        }
        return null;
    }

    /**
     * Создать директорию если она не существует
     */
    public static function ensureDirectoryExists(string $path): bool
    {
        if (is_dir($path)) {
            return true;
        }

        // Проверяем права на запись в родительской директории
        $parentDir = dirname($path);
        if (!is_writable($parentDir)) {
            error_log("PathManager: Нет прав на запись в директорию: $parentDir");
            return false;
        }

        try {
            return mkdir($path, 0755, true);
        } catch (\Exception $e) {
            error_log("PathManager: Ошибка создания директории $path: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Получить относительный путь от корня проекта
     */
    public static function getRelativePath(string $absolutePath): string
    {
        $projectRoot = self::getProjectRoot();
        if (strpos($absolutePath, $projectRoot) === 0) {
            return substr($absolutePath, strlen($projectRoot) + 1);
        }
        return $absolutePath;
    }
}