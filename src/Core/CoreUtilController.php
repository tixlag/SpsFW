<?php

namespace SpsFW\Core;

use OpenApi\Attributes as OA;
use ReflectionException;
use SpsFW\Core\Attributes\Controller;
use SpsFW\Core\Attributes\NoAuthAccess;
use SpsFW\Core\Attributes\Route;
use SpsFW\Core\Compile\RuntimeCompileGate;
use SpsFW\Core\Exceptions\BaseException;
use SpsFW\Core\Route\RestController;
use SpsFW\Core\Router\DICacheBuilder;
use SpsFW\Core\Router\Router;
use SpsFW\Core\Attributes\OpenApi\Operation;

#[Controller]
class CoreUtilController extends RestController
{

    /**
     * Можно сделать так, чтобы каждый запрос сканировал все контролеры,
     * и проверял, изменился ли файл. Если да, то обновляем кеш
     * @return array
     */
    #[OA\Post(path: '/api/core/update', summary: 'Обновляет роуты и документацию', tags: ['Core'])]
    #[OA\Response(response: 200, description: "Успешно обновлено")]
    #[Route('/api/core/update', ['POST'])]
    #[Operation(exclude: true)]
    public function updateRoutes(): array
    {
        RuntimeCompileGate::assertAllowed('route and OpenAPI documentation');

        new Router()
            ->loadRoutes(createCache: true);
        DocsUtil::updateDocs();

        return ['result' => 'ok'];
    }

    #[OA\Post(path: '/api/core/update/routes', summary: 'Обновляет только роуты', tags: ['Core'])]
    #[OA\Response(response: 200, description: "Успешно обновлено")]
    #[Route('/api/core/update/routes', ['POST'])]
    #[Operation(exclude: true)]
    public function updateOnlyRoutes(): array
    {
        RuntimeCompileGate::assertAllowed('route');

        new Router()->loadRoutes(createCache: true);

        return ['result' => 'ok'];
    }

    #[Route(path: '/test')]
    #[NoAuthAccess]
    #[Operation(exclude: true)]
    public function test(): string
    {
        return 'Lumen (10.0.4) (Laravel Components ^10.0)';
    }

    /**
     * @throws BaseException
     * @throws ReflectionException
     */
    #[Route(path: '/core/update', httpMethods: ['POST'])]
    #[Operation(exclude: true)]
    public function coreUpdate(): string
    {
        RuntimeCompileGate::assertAllowed('route, DI and OpenAPI documentation');

        $router = new Router();
        $router->loadRoutes(createCache: true);

        DICacheBuilder::compileDI();
        DocsUtil::updateDocs();

        return 'ok';
    }


    #[OA\Post(path: '/swagger/update', summary: 'Обновляет роуты и документацию', tags: ['Core'])]
    #[OA\Response(response: 200, description: "Успешно обновлено")]
    #[Route('/swagger/update', ['POST'])]
    #[Operation(exclude: true)]
    public function updateDocs(): array
    {
        RuntimeCompileGate::assertAllowed('OpenAPI documentation');

        DocsUtil::updateDocs();
        return ['result' => 'ok'];
    }





}

