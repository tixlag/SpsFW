<?php

namespace SpsFW\Api\Metrics;

use OpenApi\Attributes as OA;
use Prometheus\RenderTextFormat;
use SpsFW\Core\Http\Response;
use SpsFW\Core\Route\Controller;
use SpsFW\Core\Route\Route;

#[Controller]
class MetricsController
{
    /**
     * @throws \Throwable
     */

    #[OA\Get(
        path: '/api/v3/metrics',
        summary: 'Здесь Prometheus собирает метрики приложения',
        tags: ['Core'],
        responses: [ new OA\Response(response: 200,description: 'Ответ',content: new OA\MediaType(mediaType: 'plain/text')) ]
    )]

    #[Route('/api/v3/metrics')]
    public function index(): Response
    {
        return Response::raw(
            Metrics::getMetrics(),
            RenderTextFormat::MIME_TYPE
        );
    }
}