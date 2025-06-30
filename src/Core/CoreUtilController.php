<?php

namespace SpsFW\Core;

use OpenApi\Attributes as OA;
use SpsFW\Core\AccessRules\Attributes\NoAuthAccess;
use SpsFW\Core\Route\Controller;
use SpsFW\Core\Route\RestController;
use SpsFW\Core\Route\Route;
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

}

