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
use SpsFW\Core\Attributes\OpenApi\Response as ApiResponse;

#[Controller]
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
    #[ApiResponse(status: 200, description: 'Успешный ответ')]
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
