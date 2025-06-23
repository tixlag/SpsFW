<?php

namespace SpsFW\Core;

use OpenApi\Attributes as OA;
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
    #[OA\Get(path: '/api/v3/core/update', summary: 'Обновляет роуты и документацию', tags: ['Core'])]
    #[OA\Response(response: 200, description: "Успешно обновлено")]
    #[Route(path: '/api/v3/core/update')]
    public function updateRoutes(): array
    {
        new Router(SPS_NEW_CONTROLLERS_DIR, SPS_NEW_USE_CACHE,SPS_NEW_PATH_TO_CACHE_FILE)
            ->loadRoutes(createCache: true);
        DocsUtil::updateDocs();

        return ['result' => 'ok'];
    }

    #[OA\Get(path: '/api/v3/core/update/routes', summary: 'Обновляет только роуты', tags: ['Core'])]
    #[OA\Response(response: 200, description: "Успешно обновлено")]
    #[Route(path: '/api/v3/core/update/routes')]
    public function updateOnlyRoutes(): array
    {
        new Router(SPS_NEW_CONTROLLERS_DIR, SPS_NEW_USE_CACHE,SPS_NEW_PATH_TO_CACHE_FILE)->loadRoutes(createCache: true);

        return ['result' => 'ok'];
    }

}

