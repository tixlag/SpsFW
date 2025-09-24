<?php
namespace SpsFW\Core\Queue\Controllers;

use SpsFW\Core\Attributes\Controller;
use SpsFW\Core\Attributes\Inject;
use SpsFW\Core\Attributes\Route;
use SpsFW\Core\Http\Response;
use SpsFW\Core\Queue\QueueClientAndPublisherFactory;
use SpsFW\Core\Queue\JobRegistry;
use SpsFW\Core\Queue\WorkerHeartbeat;
use SpsFW\Core\Route\RestController;
use Psr\SimpleCache\CacheInterface;

// Импорты OpenAPI атрибутов
use OpenApi\Attributes as OA;

#[Controller]
#[OA\Tag(name: "Queue Management", description: "API для управления очередями задач и воркерами")]
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
        #[Inject] private QueueClientAndPublisherFactory $queueFactory,
        #[Inject] private CacheInterface                 $cache,
        private ?JobRegistry                             $jobRegistry = null,
    ) {
        $this->jobRegistry = $jobRegistry ?? JobRegistry::loadFromCache();
        parent::__construct();
    }

    /**
     * Dashboard со статусом всех воркеров
     */
    #[Route(path: "/api/queue/dashboard", httpMethods: ['GET'])]
    #[OA\Get(
        path: "/api/queue/dashboard",
        tags: ["Queue Management"],
        summary: "Получить статус всех воркеров",
        responses: [
            new OA\Response(
                response: 200,
                description: "Успешный ответ",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "workers", type: "array", items: new OA\Items(
                            properties: [
                                new OA\Property(property: "id", type: "string"),
                                new OA\Property(property: "config", type: "object"),
                                new OA\Property(property: "alive", type: "boolean"),
                                new OA\Property(property: "status", type: "object", nullable: true),
                                new OA\Property(property: "last_error", type: "string", nullable: true),
                                new OA\Property(property: "uptime", type: "integer", nullable: true),
                                new OA\Property(property: "stats", type: "object", nullable: true),
                            ],
                            type: "object"
                        )),
                        new OA\Property(property: "timestamp", type: "string", example: "2025-04-05 12:34:56"),
                        new OA\Property(property: "server", type: "string", example: "app-server-01"),
                    ],
                    type: "object"
                )
            )
        ]
    )]
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
    #[OA\Get(
        path: "/api/queue/stats",
        tags: ["Queue Management"],
        summary: "Получить общую статистику очередей",
        responses: [
            new OA\Response(
                response: 200,
                description: "Информация о статистике",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "message", type: "string"),
                        new OA\Property(property: "note", type: "string"),
                        new OA\Property(property: "management_url", type: "string"),
                    ],
                    type: "object"
                )
            )
        ]
    )]
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

    /**
     * Отправить задачу в очередь
     */
    #[Route(path: "/api/queue/send", httpMethods: ['POST'])]
    #[OA\Post(
        path: "/api/queue/send",
        tags: ["Queue Management"],
        summary: "Отправить задачу в очередь",
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["job_name", "queue"],
                properties: [
                    new OA\Property(property: "job_name", type: "string", example: "ImportEmployeeJob"),
                    new OA\Property(property: "queue", type: "string", example: "employee_import"),
                    new OA\Property(property: "exchange", type: "string", example: "employees"),
                    new OA\Property(property: "routing_key", type: "string", example: "import.key"),
                    new OA\Property(property: "job_data", type: "object", example: ["file_path" => "/tmp/data.json"]),
                    new OA\Property(property: "use_retry", type: "boolean", example: false),
                    new OA\Property(property: "retry_delay_ms", type: "integer", example: 10000),
                    new OA\Property(property: "max_retries", type: "integer", example: 5),
                ],
                type: "object"
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: "Задача успешно отправлена",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "success", type: "boolean", example: true),
                        new OA\Property(property: "job_name", type: "string"),
                        new OA\Property(property: "job_class", type: "string"),
                        new OA\Property(property: "queue", type: "string"),
                        new OA\Property(property: "timestamp", type: "string"),
                    ],
                    type: "object"
                )
            ),
            new OA\Response(
                response: 400,
                description: "Ошибка валидации",
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: "error", type: "string")],
                    type: "object"
                )
            ),
            new OA\Response(
                response: 500,
                description: "Внутренняя ошибка сервера",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "error", type: "string"),
                        new OA\Property(property: "message", type: "string")
                    ],
                    type: "object"
                )
            )
        ]
    )]
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
    #[OA\Post(
        path: "/api/queue/worker/{workerId}/{action}",
        tags: ["Queue Management"],
        summary: "Управление воркером: start, stop, restart",
        parameters: [
            new OA\Parameter(name: "workerId", in: "path", required: true, schema: new OA\Schema(type: "string")),
            new OA\Parameter(name: "action", in: "path", required: true, schema: new OA\Schema(type: "string", enum: ["start", "stop", "restart"]))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: "Операция выполнена",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "success", type: "boolean"),
                        new OA\Property(property: "worker", type: "string"),
                        new OA\Property(property: "action", type: "string"),
                        new OA\Property(property: "command", type: "string", nullable: true),
                        new OA\Property(property: "pid", type: "integer", nullable: true),
                    ],
                    type: "object"
                )
            ),
            new OA\Response(response: 400, description: "Неверные параметры"),
            new OA\Response(response: 404, description: "Воркер не найден"),
            new OA\Response(response: 500, description: "Ошибка сервера")
        ]
    )]
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
    #[OA\Delete(
        path: "/api/queue/worker/{workerId}/clear",
        tags: ["Queue Management"],
        summary: "Очистить данные heartbeat воркера",
        parameters: [
            new OA\Parameter(name: "workerId", in: "path", required: true, schema: new OA\Schema(type: "string"))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: "Данные очищены",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "success", type: "boolean", example: true),
                        new OA\Property(property: "worker", type: "string"),
                        new OA\Property(property: "action", type: "string", example: "cleared"),
                    ],
                    type: "object"
                )
            ),
            new OA\Response(response: 404, description: "Воркер не найден")
        ]
    )]
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
    #[OA\Get(
        path: "/api/queue/jobs",
        tags: ["Queue Management"],
        summary: "Получить список зарегистрированных задач",
        responses: [
            new OA\Response(
                response: 200,
                description: "Список задач",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "jobs", type: "array", items: new OA\Items(
                            properties: [
                                new OA\Property(property: "name", type: "string"),
                                new OA\Property(property: "class", type: "string"),
                                new OA\Property(property: "handler", type: "string", nullable: true),
                                new OA\Property(property: "has_handler", type: "boolean"),
                            ],
                            type: "object"
                        )),
                        new OA\Property(property: "total", type: "integer"),
                    ],
                    type: "object"
                )
            )
        ]
    )]
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