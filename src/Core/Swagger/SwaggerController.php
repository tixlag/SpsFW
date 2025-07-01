<?php

namespace SpsFW\Core\Swagger;

use OpenApi\Attributes as OA;
use SpsFW\Core\Attributes\Controller;
use SpsFW\Core\Attributes\NoAuthAccess;
use SpsFW\Core\Attributes\Route;
use SpsFW\Core\Http\Response;


#[OA\Info(
    version: "0.8",
    title: "Websps API"
)]
#[Controller]
class SwaggerController
{
    #[Route(path: "/swagger")]
    #[NoAuthAccess]
    public function index(): Response
    {
        return Response::html(file_get_contents('View/index.html', true));
    }

    #[Route(path: "/api/docs/openapi.yaml")]
    #[NoAuthAccess]
    public function yaml(): Response
    {
        return Response::html(file_get_contents('View/openapi.yaml', true));
    }
}