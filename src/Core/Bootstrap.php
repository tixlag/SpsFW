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
}