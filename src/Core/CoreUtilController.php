<?php

namespace SpsFW\Core;

use ReflectionException;
use SpsFW\Core\Attributes\Controller;
use SpsFW\Core\Attributes\NoAuthAccess;
use SpsFW\Core\Attributes\Route;
use SpsFW\Core\Compile\RuntimeCompileGate;
use SpsFW\Core\Exceptions\BaseException;
use SpsFW\Core\Route\RestController;
use SpsFW\Core\Router\DICacheBuilder;
use SpsFW\Core\Router\Router;
use SpsFW\Core\Attributes\OpenApi\Response as ApiResponse;

#[Controller]
class CoreUtilController extends RestController
{

    /**
     * Можно сделать так, чтобы каждый запрос сканировал все контролеры,
     * и проверял, изменился ли файл. Если да, то обновляем кеш
     * @return array
     */
    #[Route('/api/core/update', ['POST'])]
    #[ApiResponse(status: 200, description: 'Успешно обновлено')]
    public function updateRoutes(): array
    {
        RuntimeCompileGate::assertAllowed('route and OpenAPI documentation');

        new Router()
            ->loadRoutes(createCache: true);
        DocsUtil::updateDocs();

        return ['result' => 'ok'];
    }

    #[Route('/api/core/update/routes', ['POST'])]
    #[ApiResponse(status: 200, description: 'Успешно обновлено')]
    public function updateOnlyRoutes(): array
    {
        RuntimeCompileGate::assertAllowed('route');

        new Router()->loadRoutes(createCache: true);

        return ['result' => 'ok'];
    }

    #[Route(path: '/test', documented: false)]
    #[NoAuthAccess]
    public function test(): string
    {
        return 'Lumen (10.0.4) (Laravel Components ^10.0)';
    }

    /**
     * @throws BaseException
     * @throws ReflectionException
     */
    #[Route(path: '/core/update', httpMethods: ['POST'], documented: false)]
    public function coreUpdate(): string
    {
        RuntimeCompileGate::assertAllowed('route, DI and OpenAPI documentation');

        $router = new Router();
        $router->loadRoutes(createCache: true);

        DICacheBuilder::compileDI();
        DocsUtil::updateDocs();

        return 'ok';
    }


    #[Route('/swagger/update', ['POST'])]
    #[ApiResponse(status: 200, description: 'Успешно обновлено')]
    public function updateDocs(): array
    {
        RuntimeCompileGate::assertAllowed('OpenAPI documentation');

        DocsUtil::updateDocs();
        return ['result' => 'ok'];
    }





}

