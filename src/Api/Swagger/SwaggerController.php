<?php

namespace SpsFW\Api\Swagger;

use OpenApi\Generator;
use OpenApi\Attributes as OA;
use SpsFW\Core\DocsUtil;
use SpsFW\Core\Http\Response;
use SpsFW\Core\Route\Controller;
use SpsFW\Core\Route\Route;


#[OA\Info(
    version: "0.8",
    title: "Websps API"
)]
#[Controller]
class SwaggerController
{
    #[Route(path: "/swagger")]
    public function index(): Response
    {
        return Response::html(file_get_contents('View/index.html', true));
    }

    #[Route(path: "/api/v3/docs/openapi.yaml")]
    public function yaml(): Response
    {
        return Response::html(file_get_contents('View/openapi.yaml', true));
    }
}