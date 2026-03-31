<?php
// bin/worker.php

require_once __DIR__ . '/../../bootstrap.php';

use Psr\Log\LoggerInterface;
use SpsFW\Core\Config;
use SpsFW\Core\DI\DIContainer;
use SpsFW\Core\Psr\MonologLogger;
use SpsFW\Core\Queue\QueueClientAndPublisherFactory;
use SpsFW\Core\Queue\RabbitMQWorkerRunner;
use SpsFW\Core\Queue\JobRegistry;
use SpsFW\Core\Queue\WorkerHeartbeat;
use SpsFW\Core\Queue\Heartbeat\PcntlStrategy;
use SpsFW\Core\Workers\WorkerConfig;
use SpsNext\Workers\Errors\GlobalErrorHandler;


// Получаем ID воркера из аргументов
$workerId = $argv[1] ?? null;
//$workerId = "import_employee_worker";

$bootstrapLog = static function (string $level, string $event, array $context = []): void {
    $ctx = '';
    if ($context !== []) {
        $ctx = ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    $line = sprintf(
        '[%s] [%s] %s%s%s',
        (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTime::ATOM),
        strtoupper($level),
        $event,
        $ctx,
        PHP_EOL
    );

    if (in_array($level, ['warning', 'error', 'critical', 'alert', 'emergency'], true)) {
        fwrite(STDERR, $line);
        return;
    }

    echo $line;
};

$bootstrapLog('info', 'worker_bootstrap_started', [
    'argv' => $argv,
    'script' => __FILE__,
]);

if (!$workerId) {
    $bootstrapLog('error', 'worker_id_missing');
    echo "Usage: php worker.php <worker_id>\n";
    exit(1);
}

$projectRoot = dirname(__DIR__, 2);
$safeWorkerId = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $workerId) ?: 'worker';
$workerLogFile = $projectRoot . '/log/workers/' . $safeWorkerId . '.log';
Config::setDIBindings([
    LoggerInterface::class => [
        'class' => MonologLogger::class,
        'args' => [$workerLogFile, 'worker.' . $safeWorkerId],
    ],
]);

$bootstrapLog('info', 'worker_logger_configured', [
    'logger' => MonologLogger::class,
    'log_file' => $workerLogFile,
]);

try {
    ini_set('memory_limit', '2G');

    $container = DIContainer::getInstance();
    $queueList = $container->get(WorkerConfig::class);
    $bootstrapLog('info', 'worker_config_loaded');

    $workerConfig = $queueList->getAll()[$workerId] ?? null;

    if (!is_array($workerConfig)) {
        $bootstrapLog('error', 'worker_config_not_found');
        echo "Unknown worker: {$workerId}\n";
        exit(1);
    }

    if (($workerConfig['type'] ?? null) !== 'queueConsumer') {
        $workerType = (string)($workerConfig['type'] ?? 'undefined');
        $bootstrapLog('error', 'worker_type_not_supported', ['worker_type' => $workerType]);
        throw new \RuntimeException("Unsupported worker type '{$workerType}' for worker '{$workerId}'");
    }

    $workerConfig = $workerConfig['config'];
    $bootstrapLog('info', 'worker_queue_consumer_selected', [
        'queue' => $workerConfig['queue'] ?? null,
        'exchange' => $workerConfig['exchange'] ?? null,
        'routing_key' => $workerConfig['routing_key'] ?? null,
    ]);

/** @var QueueClientAndPublisherFactory $factory */
    $factory = $container->get(\SpsFW\Core\Queue\QueueClientAndPublisherFactory::class);
    $bootstrapLog('info', 'queue_factory_resolved');
    $client = $factory->createClientByWorkerName($workerId);
    $bootstrapLog('info', 'queue_client_created');

    // Загружаем реестр задач
    $jobRegistry = JobRegistry::loadFromCache();
    $bootstrapLog('info', 'job_registry_loaded');

    // Создаём кеш для heartbeat
    $cache = $container->get(\Psr\SimpleCache\CacheInterface::class);
    $bootstrapLog('info', 'heartbeat_cache_resolved');

    // Создаём heartbeat
    $heartbeat = new WorkerHeartbeat($cache, $workerId, 60);
    $bootstrapLog('info', 'heartbeat_created');

    // Выбираем стратегию
    $strategy = null;
    if (extension_loaded('pcntl')) {
        echo "Using PCNTL strategy for graceful shutdown\n";
        $strategy = new PcntlStrategy();
        $bootstrapLog('info', 'worker_strategy_selected', ['strategy' => 'pcntl']);
    } else {
        echo "PCNTL not available, using simple loop strategy\n";
        $bootstrapLog('warning', 'worker_strategy_selected', ['strategy' => 'simple_loop']);
    }

    // Создаём runner
    $runner = new RabbitMQWorkerRunner(
        client: $client,
        jobRegistry: $jobRegistry,
        heartbeat: $heartbeat,
        strategy: $strategy
    );
    $bootstrapLog('info', 'worker_runner_created');

    echo "Worker {$workerId} started\n";
    echo "Queue: {$workerConfig['queue']}\n";
    echo "Exchange: {$workerConfig['exchange']}\n";
    echo "Routing Key: {$workerConfig['routing_key']}\n";
    echo "PID: " . getmypid() . "\n";
    echo "Press Ctrl+C to stop\n\n";
    $bootstrapLog('info', 'worker_run_started');

    // Запускаем воркера
    $runner->run();
    $bootstrapLog('info', 'worker_run_finished');
} catch (\Throwable $e) {
    $bootstrapLog('critical', 'worker_bootstrap_failed', [
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'trace' => $e->getTraceAsString(),
    ]);
    fwrite(STDERR, "Worker fatal error: " . $e->getMessage() . PHP_EOL);
    throw $e;
}
