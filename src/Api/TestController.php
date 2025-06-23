<?php

namespace SpsFW\Api;

use SpsFW\Core\Middleware\Infrastructure\Middleware;
use SpsFW\Core\Middleware\PerformanceMiddleware;
use SpsFW\Core\Route\Controller;
use SpsFW\Core\Route\Route;

#[Controller]
class TestController
{
    #[Route(path: '/api/v3/test/{id}/{category}')]
    #[Middleware(middlewares: [PerformanceMiddleware::class])]
    public function test(string $id, $category): mixed
    {

        return ["id"=>$id, "fds"=>$category];
    }

}