<?php

use SpsFW\Core\Config;
use SpsFW\Core\DI\DIContainer;
use SpsFW\Core\DocsUtil;
use SpsFW\Core\Router\DICacheBuilder;
use SpsFW\Core\Router\Router;

require 'vendor/autoload.php';

function easyEnv($filename): void
{
    $filepath = __DIR__ . '/' . $filename;
    if (file_exists($filepath)) {
        foreach (file($filepath) as $line) {
            if (str_starts_with($line, '#') || !str_contains($line, '=')) continue;
            [$key, $value] = explode('=', trim($line), 2);
//            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }

}

// Preload essential classes for better performance
foreach ([
             Router::class,
             DIContainer::class,
         ] as $class) {
    class_exists($class);
}

// Load environment variables
easyEnv('.env');
easyEnv('.env' . ".$_ENV[ENV]");

// Generate dynamic configurations cache
echo "Generating dynamic configurations cache...\n";
require_once __DIR__ . '/config/cache_helpers.php';
cacheDynamicConfigs();

// Initialize configuration
Config::init([
        'db_old' => [
            'adapter' => $_ENV['OLD_DB_ADAPTER'],
            'host' => $_ENV['OLD_DB_HOST'],
            'port' => $_ENV['OLD_DB_PORT'],
            'user' => $_ENV['OLD_DB_USER'],
            'password' => $_ENV['OLD_DB_PASS'],
            'dbname' => $_ENV['OLD_DB_NAME'],
            'debugMode' => $_ENV['DEBUG_MODE']
        ],
        'db' => [
            'adapter' => $_ENV['DB_ADAPTER'],
            'host' => $_ENV['DB_HOST'],
            'port' => $_ENV['DB_PORT'],
            'user' => $_ENV['DB_USER'],
            'password' => $_ENV['DB_PASS'],
            'dbname' => $_ENV['DB_NAME'],
            'debugMode' => $_ENV['DEBUG_MODE']
        ]
    ]
);

// Load DI configuration
$diBindings = require_once __DIR__ . '/config/di_config.php';
Config::setDIBindings($diBindings);

// Удаляем старый кеш, чтобы построить новый
if (file_exists(__DIR__.'/.cache/compiled_di.php'))
    unlink(__DIR__.'/.cache/compiled_di.php');
if (file_exists(__DIR__.'/.cache/compiled_routes.php'))
    unlink(__DIR__.'/.cache/compiled_routes.php');
    
$router = new Router();
DICacheBuilder::compileDI($router->container);

//require __DIR__.'/.cache/compiled_routes.php';
//require __DIR__.'/.cache/compiled_di.php';

opcache_compile_file(__DIR__.'/.cache/compiled_routes.php');
opcache_compile_file(__DIR__.'/.cache/compiled_di.php');

DocsUtil::updateDocs();