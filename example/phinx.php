78<?php

//use Dotenv\Dotenv;
use SpsFW\Core\Router\ClassScanner;


if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    require_once __DIR__ . '/../../../vendor/autoload.php';
}
require_once
\SpsFW\Core\Router\PathManager::getVendorPath() .'/autoload.php';

easyEnv('.env');
easyEnv('.env'.".$_ENV[ENV]");

//$projectRoot = dirname(__DIR__, 1) . '/src';
$projectRoot = __DIR__ . '/src';
echo "DEBUG: " . print_r($projectRoot, true) . "\n";

$env = $_ENV['ENV'] ?: 'dev';

$envPaths = [];
$envFiles = [".env", ".env.$env"];
foreach ($envFiles as $envFile) {
    if (file_exists($projectRoot . "/$envFile")) {
        $envPaths[] = $projectRoot . "/$envFile";
    }
}

//$dotenv = Dotenv::createUnsafeImmutable(empty($envPaths) ? ['./'] : $envPaths);
//$dotenv->load();

$migrationPaths = array_merge(findDomainMigrationPaths([$projectRoot]), findDomainMigrationPaths(\SpsFW\Core\Router\PathManager::getLibraryRoot()));

return array(
    'paths' => array(
        'migrations' => $migrationPaths,
        'seeds' => '%%PHINX_CONFIG_DIR%%/db/seeds'
    ),
    'environments' => array(
        'default_migration_table' => 'phinx_log',
        'default_environment' => 'default',
        'default' => array(
            'adapter' => $_ENV['DB_ADAPTER'],
            'host' => $_ENV['DB_HOST'],
            'name' => $_ENV['DB_NAME'],
            'user' => $_ENV['DB_USER'],
            'pass' => $_ENV['DB_PASS'],
            'port' => $_ENV['DB_PORT'] ?: 3306,
            'charset' => $_ENV['DB_CHARSET'] ?: 'utf8',
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

            $migrationRequiredClass = $currentPath . '/MigrationRequiredClass.php';
            if (file_exists($migrationRequiredClass)) {

                $namespace = ClassScanner::getPathToNamespace($migrationRequiredClass, false) . '\\migrations';
                $migrationsPath = $currentPath . '/migrations';
                $migrationPaths[$namespace] = $migrationsPath;
            }
        }
    }

    return $migrationPaths;
}

function easyEnv($filename): void
{
    if (file_exists($filename)) {
        foreach (file($filename) as $line) {
            if(str_starts_with($line, '#') || !str_contains($line, '=')) continue;
            [$key, $value] = explode('=', trim($line), 2);
//            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }

}