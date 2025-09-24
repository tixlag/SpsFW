<?php
namespace SpsFW\Core\Queue\Controllers;

use SpsFW\Core\Attributes\Controller;
use SpsFW\Core\Attributes\Inject;
use SpsFW\Core\Attributes\Route;
use SpsFW\Core\Http\Response;
use SpsFW\Core\Queue\QueuePublisherFactory;
use SpsFW\Core\Queue\JobRegistry;
use SpsFW\Core\Queue\WorkerHeartbeat;
use SpsFW\Core\Route\RestController;
use Psr\SimpleCache\CacheInterface;

#[Controller]
class QueueManagerController extends RestController
{
    private array $workerDefinitions = [
        'import_employee_worker' => [
            'queue' => 'employee_import',
            'exchange' => 'employees',
            'description' => 'Imports employees from JSON files'
        ],
        'notification_worker' => [
            'queue' => 'notifications',
            'exchange' => 'notifications',
            'description' => 'Sends email/SMS/push notifications'
        ],
        'report_worker' => [
            'queue' => 'reports',
            'exchange' => 'reports',
            'description' => 'Generates various reports'
        ],
    ];

    public function __construct(
        #[Inject] private QueuePublisherFactory $queueFactory,
        #[Inject] private JobRegistry $jobRegistry,
        #[Inject] private CacheInterface $cache
    ) {
        parent::__construct();
    }

    /**
     * Dashboard со статусом всех воркеров
     */
    #[Route(path: "/api/queue/dashboard", httpMethods: ['GET'])]
    public function dashboard(): Response
    {
        $workers = [];

        foreach ($this->workerDefinitions as $workerId => $config) {
            $heartbeat = new WorkerHeartbeat($this->cache, $workerId, 60);

            $status = $heartbeat->getStatus();
            $lastError = $heartbeat->getLastError();

            $workers[] = [
                'id' => $workerId,
                'config' => $config,
                'alive' => $heartbeat->isAlive(),
                'status' => $status,
                'last_error' => $lastError,
                'uptime' => $status && isset($status['data']['started_at'])
                    ? time() - $status['data']['started_at']
                    : null,
                'stats' => $status['data'] ?? null
            ];
        }

        return Response::json([
            'workers' => $workers,
            'timestamp' => date('Y-m-d H:i:s'),
            'server' => gethostname(),
        ]);

    }

    /**
     * Получить статистику очередей
     */
    #[Route(path: "/api/queue/stats", httpMethods: ['GET'])]
    public function stats(): Response
    {
        // Здесь можно добавить подключение к RabbitMQ Management API
        // для получения статистики очередей

        return Response::json([
            'message' => 'Queue statistics',
            'note' => 'Connect to RabbitMQ Management API for detailed stats',
            'management_url' => 'http://localhost:15672/api/queues'
        ]);
    }


    #[Route(path: "/api/queue/send", httpMethods: ['POST'])]
    public function send(): Response
    {
        $data = $this->request->getJsonData();

        $jobName = $data['job_name'] ?? null;
        $queue = $data['queue'] ?? null;
        $exchange = $data['exchange'] ?? '';
        $routingKey = $data['routing_key'] ?? '';
        $jobData = $data['job_data'] ?? [];
        $useRetry = $data['use_retry'] ?? false;

        if (!$jobName || !$queue) {
            return Response::json(['error' => 'job_name and queue are required'], 400);
        }

        $jobClass = $this->jobRegistry->getJobClass($jobName);
        if (!$jobClass) {
            return Response::json(['error' => "Unknown job: {$jobName}"], 400);
        }

        try {
            // Создаём задачу
            $job = new $jobClass(...array_values($jobData));

            // Создаём publisher
            if ($useRetry) {
                $publisher = $this->queueFactory->createWithRetry(
                    $queue,
                    $exchange,
                    $routingKey,
                    $data['retry_delay_ms'] ?? 10000,
                    $data['max_retries'] ?? 5
                );
            } else {
                $publisher = $this->queueFactory->create($queue, $exchange, $routingKey);
            }

            // Публикуем
            $publisher->publish($job);

            return Response::json([
                'success' => true,
                'job_name' => $jobName,
                'job_class' => $jobClass,
                'queue' => $queue,
                'timestamp' => date('Y-m-d H:i:s'),
            ]);

        } catch (\Throwable $e) {
            return Response::json([
                'error' => 'Failed to send job',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Управление воркером (start/stop/restart)
     */
    #[Route(path: "/api/queue/worker/{workerId}/{action}", httpMethods: ['POST'])]
    public function controlWorker(string $workerId, string $action): Response
    {
        if (!isset($this->workerDefinitions[$workerId])) {
            return Response::json(['error' => 'Unknown worker'], 404);
        }

        $heartbeat = new WorkerHeartbeat($this->cache, $workerId, 60);

        switch ($action) {
            case 'start':
                if ($heartbeat->isAlive()) {
                    return Response::json(['error' => 'Worker already running'], 400);
                }

                // Запуск воркера в фоне
                $command = sprintf(
                    'php %s/bin/worker.php %s > /dev/null 2>&1 &',
                    dirname(__DIR__, 6), // Путь к корню проекта
                    $workerId
                );

                exec($command, $output, $returnVar);

                return Response::json([
                    'success' => $returnVar === 0,
                    'worker' => $workerId,
                    'action' => 'started',
                    'command' => $command
                ]);

            case 'stop':
                if (!$heartbeat->isAlive()) {
                    return Response::json(['error' => 'Worker not running'], 400);
                }

                $status = $heartbeat->getStatus();
                if ($status && isset($status['pid'])) {
                    // Отправляем SIGTERM для graceful shutdown
                    posix_kill($status['pid'], SIGTERM);

                    return Response::json([
                        'success' => true,
                        'worker' => $workerId,
                        'action' => 'stopping',
                        'pid' => $status['pid']
                    ]);
                }

                return Response::json(['error' => 'Could not find worker PID'], 500);

            case 'restart':
                $status = $heartbeat->getStatus();
                if ($status && isset($status['pid'])) {
                    posix_kill($status['pid'], SIGTERM);
                    sleep(2);
                }

                $command = sprintf(
                    'php %s/bin/worker.php %s > /dev/null 2>&1 &',
                    dirname(__DIR__, 6),
                    $workerId
                );

                exec($command, $output, $returnVar);

                return Response::json([
                    'success' => $returnVar === 0,
                    'worker' => $workerId,
                    'action' => 'restarted'
                ]);

            default:
                return Response::json(['error' => 'Invalid action'], 400);
        }
    }

    /**
     * Очистка данных воркера
     */
    #[Route(path: "/api/queue/worker/{workerId}/clear", httpMethods: ['DELETE'])]
    public function clearWorker(string $workerId): Response
    {
        if (!isset($this->workerDefinitions[$workerId])) {
            return Response::json(['error' => 'Unknown worker'], 404);
        }

        $heartbeat = new WorkerHeartbeat($this->cache, $workerId, 60);
        $heartbeat->clear();

        return Response::json([
            'success' => true,
            'worker' => $workerId,
            'action' => 'cleared'
        ]);
    }

    /**
     * Получить список зарегистрированных задач
     */
    #[Route(path: "/api/queue/jobs", httpMethods: ['GET'])]
    public function listJobs(): Response
    {
        $jobs = [];

        // Получаем все зарегистрированные задачи
        $registeredJobs = $this->jobRegistry->getRegisteredJobs();


        foreach ($registeredJobs as $jobName => $job) {
            $jobs[] = [
                'name' => $jobName,
                'class' => $job['jobClass'],
                'handler' =>  $job['handlerClass'] ?? null,
                'has_handler' => isset($job['handlerClass'])
            ];
        }

        return Response::json([
            'jobs' => $jobs,
            'total' => count($jobs)
        ]);
    }
}