<?php

namespace SpsFW\Test;

use SpsFW\Core\Attributes\Controller;
use SpsFW\Core\Attributes\MemoryLimit;
use SpsFW\Core\Attributes\Route;
use SpsFW\Core\Attributes\NoAuthAccess;
use SpsFW\Core\Http\Response;

#[Controller]
class TestMemoryLimitController
{
    #[Route(path: "/test-memory-limit", httpMethods: ["GET"])]
    #[MemoryLimit("256M")]
    #[NoAuthAccess]
    public function testMemoryLimit(): Response
    {
        return Response::json([
            "memory_limit" => ini_get("memory_limit"),
            "message" => "Memory limit set to 256M"
        ]);
    }
}

