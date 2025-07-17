<?php

namespace SpsFW\Core\Middleware;

use SpsFW\Core\Http\Request;
use SpsFW\Core\Http\Response;

interface MiddlewareInterface
{
    public function handle(Request $request): Request;
    public function after(Response $response): Response;
}