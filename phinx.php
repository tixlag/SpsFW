<?php

use Dotenv\Dotenv;
use SpsFW\Core\Router\ClassScanner;


if (file_exists(__DIR__ . 'vendor/autoload.php')) {
    require_once __DIR__ . 'vendor/autoload.php';
} else {
    require_once __DIR__ . '/../../../vendor/autoload.php';
}

$projectRoot = dirname(__DIR__, 3) . '/src';
$coreRoot = dirname(__DIR__ . '/src/Core/.');
$env = getenv('APP_ENV') ?: 'dev';

$envPaths = [];
$envFiles = [".env", ".env.$env"];
foreach ($envFiles as $envFile) {
    if (file_exists($projectRoot . "/$envFile")) {
        $envPaths[] = $projectRoot . "/$envFile";
    }
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
            if (!$fileInfo->isDir()) continue;

            $currentPath = $fileInfo->getPathname();

            $migrationsPath = $currentPath . '/migrations';

            if (is_dir($migrationsPath)) {
                $files = glob($migrationsPath . '/*.php');
                $firstFile = reset($files);
                $namespace = ClassScanner::getPathToNamespace($firstFile, false);

                $migrationPaths[$namespace] = $migrationsPath;
            }
        }
    }

    return $migrationPaths;
}