<?php

namespace SpsFW\Core\Middleware;

use SpsFW\Core\Http\Request;
use SpsFW\Core\Http\Response;
use SpsFW\Core\Middleware\Infrastructure\MiddlewareInterface;

class PerformanceMiddleware implements MiddlewareInterface
{
    private float $startTime;

    public function handle(Request $request): Request
    {
        $this->startTime = hrtime(true);
        return $request;
    }

    public function after(Response $response): Response
    {
        $endTime = hrtime(true);
        $duration = ($endTime - $this->startTime) / 1e6; // В миллисекундах
        $response->addHeader('X-Request-Time', number_format($duration, 2));
        return $response;
    }
}