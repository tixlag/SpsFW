<?php

declare(strict_types=1);

namespace SpsFW\Core\Phinx;

use SpsFW\Core\Bootstrap;
use SpsFW\Core\Router\ClassScanner;
use SpsFW\Core\Router\PathManager;

/**
 * Фабрика конфигурации Phinx.
 *
 * Клиентский phinx.php сводится к трём строкам:
 *
 *   <?php
 *   require_once __DIR__ . '/vendor/autoload.php';
 *   return SpsFW\Core\Phinx\PhinxConfigFactory::create(__DIR__);
 */
class PhinxConfigFactory
{
    /**
     * Создать и вернуть конфигурацию для Phinx.
     *
     * @param string $projectRoot  Абсолютный путь к корню проекта (где лежит phinx.php)
     */
    public static function create(string $projectRoot): array
    {
        // Загружаем переменные окружения
        Bootstrap::loadEnv($projectRoot . '/.env');
        Bootstrap::loadEnv($projectRoot . '/.env.' . ($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'dev'));

        // Сканируем src проекта + src фреймворка
        $migrationPaths = self::collectMigrationPaths(
            projectRoot: $projectRoot,
            srcDirs: [
                $projectRoot . '/src',
                PathManager::getLibraryRoot(),
            ]
        );

        return [
            'paths' => [
                'migrations' => $migrationPaths,
                'seeds'      => $projectRoot . '/db/seeds',
            ],
            'environments' => [
                'default_migration_table' => 'phinx_log',
                'default_environment'     => 'default',
                'default'                 => [
                    'adapter' => $_ENV['DB_ADAPTER'] ?? getenv('DB_ADAPTER'),
                    'host'    => $_ENV['DB_HOST']    ?? getenv('DB_HOST'),
                    'name'    => $_ENV['DB_NAME']    ?? getenv('DB_NAME'),
                    'user'    => $_ENV['DB_USER']    ?? getenv('DB_USER'),
                    'pass'    => $_ENV['DB_PASS']    ?? getenv('DB_PASS'),
                    'port'    => $_ENV['DB_PORT']    ?? getenv('DB_PORT') ?: 3306,
                    'charset' => $_ENV['DB_CHARSET'] ?? getenv('DB_CHARSET') ?: 'utf8mb4',
                ],
            ],
            'version_order' => 'creation',
        ];
    }

    /**
     * Собрать пути миграций.
     *
     * Всегда включает $projectRoot/db/migrations.
     * Дополнительно сканирует каждый из $srcDirs на наличие MigrationRequiredClass.php —
     * если маркер найден и рядом есть папка migrations/, она добавляется с правильным namespace.
     */
    private static function collectMigrationPaths(string $projectRoot, array $srcDirs): array
    {
        $paths = [];

        // Основные миграции проекта всегда первые
        $paths[] = $projectRoot . '/db/migrations';

        foreach ($srcDirs as $srcDir) {
            if (!is_dir($srcDir)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($srcDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $fileInfo) {
                if (!$fileInfo->isDir()) {
                    continue;
                }

                $dir = $fileInfo->getPathname();

                if (!file_exists($dir . '/MigrationRequiredClass.php')) {
                    continue;
                }

                $migrationsDir = $dir . '/migrations';
                if (!is_dir($migrationsDir)) {
                    continue;
                }

                $namespace = ClassScanner::getPathToNamespace($dir . '/MigrationRequiredClass.php', false) . '\\migrations';
                $paths[$namespace] = $migrationsDir;
            }
        }

        return $paths;
    }
}
