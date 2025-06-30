<?php

use Dotenv\Dotenv;

// Загрузите автозагрузчик Composer
require_once 'vendor/autoload.php';

$projectRoot = dirname(__DIR__, 2) . '/src';
$coreRoot = dirname(__DIR__ . '/src/Core/.');
$env = getenv('APP_ENV') ?: 'dev';

$envPaths = [];
$envFile = ".env.$env";
if (file_exists($projectRoot . "/$envFile")) {
    $envPaths[] = $projectRoot . "/$envFile";
}
if (file_exists($projectRoot . "/$envFile")) {
    $envPaths[] = $projectRoot . '/.env';
}

$dotenv = Dotenv::createUnsafeImmutable(empty($envPaths) ? ['./'] : $envPaths);
$dotenv->load();

$migrationPaths = findDomainMigrationPaths([$projectRoot, $coreRoot]);

return array(
    'paths' => array(
        'migrations' => $migrationPaths,
        'seeds' => '%%PHINX_CONFIG_DIR%%/db/seeds'
    ),
    'environments' => array(
        'default_migration_table' => 'phinx_log',
        'default_environment' => 'default',
        'default' => array(
            'adapter' => getenv('DB_ADAPTER'),
            'host' => getenv('DB_HOST'),
            'name' => getenv('DB_NAME'),
            'user' => getenv('DB_USER'),
            'pass' => getenv('DB_PASS'),
            'port' => getenv('DB_PORT') ?: 3306,
            'charset' => getenv('DB_CHARSET') ?: 'utf8',
        )
    ),
    'version_order' => 'creation'
);


function findDomainMigrationPaths($dirs): array {
    $migrationPaths = [];

    // Основной путь для миграций
    $migrationPaths[] = '%%PHINX_CONFIG_DIR%%/db/migrations';

    // Поиск доменных областей

    foreach ($dirs as $domainsPath) {
        if (!is_dir($domainsPath)) {
            continue;
        }

        // Создаем рекурсивный итератор для обхода директории
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($domainsPath, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $fileInfo) {
            // Пропускаем всё, что не является директорией
            if (!$fileInfo->isDir()) {
                continue;
            }

            $currentPath = $fileInfo->getPathname();

            $migrationsPath = $currentPath . '/migrations';

            if (is_dir($migrationsPath)) {
                $migrationPaths[] = $migrationsPath;
            }
        }
    }

    return $migrationPaths;
}