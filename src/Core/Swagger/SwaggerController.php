<?php

namespace SpsFW\Core\Swagger;

use OpenApi\Attributes as OA;
use SpsFW\Core\Attributes\Controller;
use SpsFW\Core\Attributes\NoAuthAccess;
use SpsFW\Core\Attributes\Route;
use SpsFW\Core\DocsUtil;
use SpsFW\Core\Http\Response;
use SpsFW\Core\Route\RestController;


#[OA\Info(
    version: "0.8",
    title: "Websps API"
)]
#[Controller]
class SwaggerController extends RestController
{
    #[Route(path: "/swagger")]
    public function index(): Response
    {
        return Response::html(file_get_contents('View/index.html', true));
    }

    #[Route(path: "/api/docs/openapi.yaml")]
    #[NoAuthAccess]
    public function yaml(): Response
    {
        return Response::html(file_get_contents(DocsUtil::FILE_PATH, true));
    }
}