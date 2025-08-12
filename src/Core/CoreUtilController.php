<?php

namespace SpsFW\Core;

use OpenApi\Attributes as OA;
use ReflectionException;
use SpsFW\Core\Attributes\Controller;
use SpsFW\Core\Attributes\NoAuthAccess;
use SpsFW\Core\Attributes\Route;
use SpsFW\Core\Exceptions\BaseException;
use SpsFW\Core\Route\RestController;
use SpsFW\Core\Router\DICacheBuilder;
use SpsFW\Core\Router\Router;

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
    public function updateRoutes(): array
    {
        new Router()
            ->loadRoutes(createCache: true);
        DocsUtil::updateDocs();

        return ['result' => 'ok'];
    }

    #[OA\Post(path: '/api/core/update/routes', summary: 'Обновляет только роуты', tags: ['Core'])]
    #[OA\Response(response: 200, description: "Успешно обновлено")]
    #[Route('/api/core/update/routes', ['POST'])]
    public function updateOnlyRoutes(): array
    {
        new Router()->loadRoutes(createCache: true);

        return ['result' => 'ok'];
    }

    #[Route(path: '/test')]
    #[NoAuthAccess]
    public function test(): string
    {
        return 'Lumen (10.0.4) (Laravel Components ^10.0)';
    }

    /**
     * @throws BaseException
     * @throws ReflectionException
     */
    #[Route(path: '/core/update', httpMethods: ['POST'])]
    public function coreUpdate(): string
    {
        $router = new Router();
        $router->loadRoutes(createCache: true);

        DICacheBuilder::compileDI();
        DocsUtil::updateDocs();

        return 'ok';
    }


    #[OA\Post(path: '/swagger/update', summary: 'Обновляет роуты и документацию', tags: ['Core'])]
    #[OA\Response(response: 200, description: "Успешно обновлено")]
    #[Route('/swagger/update', ['POST'])]
    public function updateDocs(): array
    {
        DocsUtil::updateDocs();
        return ['result' => 'ok'];
    }





}

