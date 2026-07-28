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
use SpsFW\Core\Attributes\OpenApi\Response as ApiResponse;

#[OA\Info(
    version: "0.8",
    title: "Websps API"
)]
#[Controller]
class SwaggerController extends RestController
{

    #[Route(path: "/swagger")]
    #[NoAuthAccess]
    #[ApiResponse(status: 200, description: 'Swagger UI')]
    public function index(): Response
    {
        return Response::html(file_get_contents('View/index.html', true));
    }

    #[Route(path: "/swagger/openapi.yaml")]
    #[ApiResponse(status: 200, description: 'OpenAPI YAML')]
    public function yaml(): Response
    {
        return Response::html(file_get_contents(PathManager::getProjectRoot() . '/.cache/swagger/openapi.yml', true));


    }
}