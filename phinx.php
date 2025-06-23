<?php

use Dotenv\Dotenv;

// Загрузите автозагрузчик Composer
require_once 'vendor/autoload.php';

$projectRoot = dirname(__DIR__, 2);

// Определите текущее окружение (по умолчанию 'dev')
$env = getenv('APP_ENV') ?: 'dev';

// Загрузите основной .env (если он есть)
$dotenv = Dotenv::createImmutable($projectRoot);
$dotenv->load();

// Загрузите специфичный для окружения .env (например, .env.prod)
$envFile = ".env.$env";
if (file_exists($projectRoot . "/$envFile")) {
    $dotenv->setPath($projectRoot . "/$envFile")->load();
}

return
    array(
        'paths' => array(
            'migrations' => '%%PHINX_CONFIG_DIR%%/db/migrations',
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
