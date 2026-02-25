<?php

namespace SpsFW\Core\Queue\Controllers;

use Psr\SimpleCache\CacheInterface;
use SpsFW\Core\Attributes\Controller;
use SpsFW\Core\Attributes\Inject;
use SpsFW\Core\Attributes\Route;
use SpsFW\Core\Http\Response;
use SpsFW\Core\Queue\WorkerHeartbeat;
use SpsFW\Core\Route\RestController;
use SpsFW\Core\Workers\WorkerConfig;

// Импортируем атрибуты OpenAPI
use OpenApi\Attributes as OA;

#[Controller]
#[OA\Tag(name: "Worker Health", description: "Проверка работоспособности фоновых воркеров")]
class WorkerHealthController extends RestController
{
    public function __construct(
        #[Inject] private CacheInterface $cache,
        #[Inject] private ?WorkerConfig $workerConfig = null,
    ) {
        parent::__construct();
    }

    /**
     * Проверка статуса работоспособности воркеров
     */
    #[Route(path: "/api/worker-health", httpMethods: ['GET'])]
    #[OA\Get(
        path: "/api/worker-health",
        description: "Возвращает список воркеров и флаг их активности (heartbeat за последние 60 секунд)",
        summary: "Получить статус работоспособности всех воркеров",
        tags: ["Worker Health"],
        responses: [
            new OA\Response(
                response: 200,
                description: "Успешный ответ",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: "workers",
                            type: "object",
                            example: [
                                "order_notification_worker" => true,
                                "import_employees_worker" => false,
                                "visited_worker" => true
                            ], // динамические ключи
                            additionalProperties: true
                        ),
                        new OA\Property(
                            property: "timestamp",
                            type: "integer",
                            example: 1743849600
                        ),
                    ],
                    type: "object"
                )
            )
        ]
    )]
    public function check(): Response
    {
        $workers = $this->workerConfig?->getQueueWorkerNames() ?? [
            'order_notification_worker',
            'import_employees_worker',
            'visited_worker',
        ];

        $statuses = [];
        $instances = [];
        foreach ($workers as $workerId) {
            $heartbeat = new WorkerHeartbeat($this->cache, $workerId, 60);
            $statuses[$workerId] = $heartbeat->isAlive();
            $instances[$workerId] = array_values($heartbeat->getInstancesStatuses());
        }

        return Response::json([
            'workers' => $statuses,
            'instances' => $instances,
            'timestamp' => time(),
        ]);
    }
}
