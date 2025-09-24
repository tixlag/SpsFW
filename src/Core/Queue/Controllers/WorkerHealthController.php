<?php

namespace SpsFW\Core\Queue\Controllers;

use Psr\SimpleCache\CacheInterface;
use SpsFW\Core\Attributes\Controller;
use SpsFW\Core\Attributes\Inject;
use SpsFW\Core\Attributes\Route;
use SpsFW\Core\Http\Response;
use SpsFW\Core\Queue\WorkerHeartbeat;
use SpsFW\Core\Route\RestController;

#[Controller]
class WorkerHealthController extends RestController
{
    public function __construct(
        #[Inject] private CacheInterface $cache
    ) {
        parent::__construct();
    }

    #[Route(path: "/api/worker-health", httpMethods: ['GET'])]
    public function check(): Response
    {
        $workers = [
            'order_notification_worker',
            'import_employees_worker',
            'visited_worker',
            // добавь остальные
        ];

        $statuses = [];
        foreach ($workers as $workerId) {
            $heartbeat = new WorkerHeartbeat($this->cache, $workerId, 60);
            $statuses[$workerId] = $heartbeat->isAlive();
        }

        return Response::json([
            'workers' => $statuses,
            'timestamp' => time(),
        ]);
    }
}