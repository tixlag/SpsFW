<?php


use SpsFW\Core\Config;
use SpsNext\Utils\ProfilerService;
use SpsNext\Workers\Errors\GlobalErrorHandler;

if (!function_exists('easyEnv')) {
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
}

require_once 'vendor/autoload.php';
new ProfilerService('next')->init();

easyEnv('.env');
easyEnv('.env' . ".$_ENV[ENV]");

if (!isset($_ENV['DEBUG_MODE']) || $_ENV['DEBUG_MODE'] !== 'true') {
    define("allowedOrigins", [
        'lk.sps38.pro',
        'www.sps38.pro',
        'sps38.ru',
        'https://sps38.ru',
        'https://market.sps38.pro',
        'https://dev.sps38.pro'
    ]);
} else {
    define("allowedOrigins", [
        'http://localhost:5173',
        'https://localhost:5173',
        'https://localhost:5174',
        'https://next.localhost:12443',
        'https://next.sps38.pro',
        'https://lk.sps38.pro',
        'https://www.lk.sps38.pro',
        'http://localhost:3000',
        'https://market.sps38.pro',
        'https://dev.sps38.pro',
        'sps38.ru',
        'https://sps38.ru',
    ]);
}
$origin = isset($_SERVER['HTTP_X_ORIGIN']) ?
    ($_SERVER['HTTP_X_ORIGIN'] != "" ? $_SERVER['HTTP_X_ORIGIN'] : $_SERVER['HTTP_ORIGIN'])
    :  $_SERVER['HTTP_ORIGIN'] ?? null;
if ($origin && in_array($origin, allowedOrigins)) {
    header("Access-Control-Allow-Origin: " . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Expose-Headers: Content-Disposition');
}


if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === "OPTIONS") {
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS");
    header('Access-Control-Allow-Credentials: true');
    header("Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept, Authorization, WithCredentials, Set-Cookie, X-Origin, Content-Disposition");
    header("Access-Control-Max-Age: 3600");
    exit();
}


// Initialize configuration
Config::init([
        'db' => [
            'adapter' => $_ENV['DB_ADAPTER'],
            'host' => $_ENV['DB_HOST'],
            'port' => $_ENV['DB_PORT'],
            'user' => $_ENV['DB_USER'],
            'password' => $_ENV['DB_PASS'],
            'dbname' => $_ENV['DB_NAME'],
            'debugMode' => $_ENV['DEBUG_MODE']
        ],
        'db_old' => [
            'adapter' => $_ENV['OLD_DB_ADAPTER'],
            'host' => $_ENV['OLD_DB_HOST'],
            'port' => $_ENV['OLD_DB_PORT'],
            'user' => $_ENV['OLD_DB_USER'],
            'password' => $_ENV['OLD_DB_PASS'],
            'dbname' => $_ENV['OLD_DB_NAME'],
            'debugMode' => $_ENV['DEBUG_MODE']
        ],
        'db_test' => [
            'adapter' => $_ENV['DB_ADAPTER'],
            'host' => $_ENV['DB_HOST'],
            'port' => $_ENV['DB_PORT'],
            'user' => $_ENV['DB_USER'],
            'password' => $_ENV['DB_PASS'],
            'dbname' => $_ENV['DB_TEST_NAME'],
            'debugMode' => $_ENV['DEBUG_MODE']
        ],
    ]
);


$diBindings = require_once __DIR__ . '/config/di_config.php';
Config::setDIBindings($diBindings);

// Register global error and exception handlers
$container = \SpsFW\Core\DI\DIContainer::getInstance();
$globalErrorHandler = $container->get(GlobalErrorHandler::class);

set_exception_handler(function ($exception) use ($globalErrorHandler) {
    $globalErrorHandler->handleException($exception);

    // Log to standard error log as well
//    error_log("Uncaught exception: " . $exception->getMessage() . " in " . $exception->getFile() . " on line " . $exception->getLine() . "\nTrace:" . $exception->getTraceAsString(). "\n\n");
});

set_error_handler(function ($errno, $errstr, $errfile, $errline) use ($globalErrorHandler) {
    $result = $globalErrorHandler->handleError($errno, $errstr, $errfile, $errline);

    // For fatal errors, we might want to exit
    if ($errno === E_ERROR || $errno === E_CORE_ERROR || $errno === E_COMPILE_ERROR || $errno === E_USER_ERROR) {
        error_log("Fatal error: $errstr in $errfile on line $errline");
        exit(1);
    }

    return $result;
});

