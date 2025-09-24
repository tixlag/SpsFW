<?php

namespace SpsFW\Core\Queue;

use PhpAmqpLib\Message\AMQPMessage;
use SpsFW\Core\Queue\Heartbeat\WorkerStrategyInterface;


class RabbitMQWorkerRunner
{
    private RabbitMQClient $client;
    private JobRegistry $jobRegistry;
    private ?WorkerHeartbeat $heartbeat;
    private ?WorkerStrategyInterface $strategy;
    private bool $isRunning = false;
    private array $stats = [
        'processed' => 0,
        'success' => 0,
        'failed' => 0,
        'retried' => 0,
        'started_at' => null,
    ];

    public function __construct(
        RabbitMQClient           $client,
        JobRegistry              $jobRegistry,
        ?WorkerHeartbeat         $heartbeat = null,
        ?WorkerStrategyInterface $strategy = null
    )
    {
        $this->client = $client;
        $this->jobRegistry = $jobRegistry;
        $this->heartbeat = $heartbeat;
        $this->strategy = $strategy;
    }

    public function run(): void
    {
        if ($this->strategy) {
            $this->strategy->run($this);
        } else {
            $this->runSimpleLoop();
        }
    }

    private function runSimpleLoop(): void
    {
        $this->start();

        while ($this->isRunning) {
            $this->runIteration();
        }

        $this->stop();
    }

    public function start(): void
    {
        $this->isRunning = true;
        $this->stats['started_at'] = time();

        if ($this->heartbeat) {
            $this->heartbeat->beat();
            $this->heartbeat->setStatus('running', $this->stats);
        }

        $this->client->startConsuming(
            function (AMQPMessage $message) {
                $this->processMessage($message);
            }
        );
    }

    public function runIteration(): void
    {
        if ($this->heartbeat) {
            // Отправляем первый сигнал
            $this->heartbeat->beat();
            $this->heartbeat->setStatus('running', $this->stats);
        }

        $this->client->waitOne(5.0);
    }

    public function stop(): void
    {
        $this->isRunning = false;

        if ($this->heartbeat) {
            $this->heartbeat->setStatus('stopped', $this->stats);
        }

        $this->client->stopConsuming();
    }

    private function processMessage(AMQPMessage $message): void
    {
        $decoded = json_decode($message->body, true);
        $jobName = $decoded['jobName'] ?? null;
        $payload = $decoded['payload'] ?? null;

        $this->stats['processed']++;

        if (!$jobName || !$payload) {
            $message->nack();
            $this->stats['failed']++;
            return;
        }

        try {
            $job = $this->jobRegistry->createJob($jobName, (string)$payload);
            $handler = $this->jobRegistry->getHandler($jobName);

            if ($this->heartbeat) {
                $this->heartbeat->beat();
                $this->heartbeat->setStatus('processing', array_merge($this->stats, [
                    'current_job' => $jobName
                ]));
            }

            $result = $handler->handle($job);

            switch ($result) {
                case JobResult::Success:
                    $message->ack();
                    $this->stats['success']++;
                    break;
                case JobResult::Retry:
                    $message->nack(false, true);
                    $this->stats['retried']++;
                    break;
                case JobResult::Failed:
                    $message->nack(false, false);
                    $this->stats['failed']++;
                    break;
            };

        } catch (\Throwable $e) {
            if ($this->heartbeat) {
                $this->heartbeat->beat();
                $this->heartbeat->setError($e->getMessage());
            }

            error_log('Worker exception for job ' . ($jobName ?? 'unknown') . ': ' . $e->getMessage());
            $message->nack(false, true);
            $this->stats['failed']++;
        }
    }

    public function isRunning(): bool
    {
        return $this->isRunning;
    }
}