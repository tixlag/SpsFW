<?php

namespace SpsFW\Core\Swagger;

use OpenApi\Attributes as OA;
use SpsFW\Core\Attributes\Controller;
use SpsFW\Core\Attributes\NoAuthAccess;
use SpsFW\Core\Attributes\Route;
use SpsFW\Core\DocsUtil;
use SpsFW\Core\Http\Response;
use SpsFW\Core\Route\RestController;
use SpsFW\Core\Router\PathManager;

#[OA\Info(
    version: "0.8",
    title: "Websps API"
)]
#[Controller]
class SwaggerController extends RestController
{

    #[OA\Get(
        path: "/swagger",
        description: "Swagger UI",
        summary: "Swagger UI",
        tags: ["Swagger"]
    )]
    #[Route(path: "/swagger")]
    #[NoAuthAccess]
    public function index(): Response
    {
        return Response::html(file_get_contents('View/index.html', true));
    }

    #[OA\Get(
        path: "/swagger/openapi.yaml",
        description: "OpenAPI YAML",
        summary: "OpenAPI YAML",
        tags: ["Swagger"]
    )]
    #[Route(path: "/swagger/openapi.yaml")]
    public function yaml(): Response
    {
        return Response::html(file_get_contents(PathManager::getProjectRoot() . '/.cache/swagger/openapi.yml', true));


    }
}