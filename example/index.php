<?php

use SpsFW\Core\Router\Router;

date_default_timezone_set('UTC');
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');

require_once __DIR__ . '/bootstrap.php';



$router = new Router();

$response = $router->dispatch();

$response->send();



