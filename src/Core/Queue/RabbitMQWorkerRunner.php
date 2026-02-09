<?php

namespace SpsFW\Core\Queue;

use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use SpsFW\Core\Queue\Heartbeat\WorkerStrategyInterface;
use SpsFW\Core\Queue\LargeMessage\LargeMessageHandlerInterface;


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
        ?JobRegistry             $jobRegistry = null,
        ?WorkerHeartbeat         $heartbeat = null,
        ?WorkerStrategyInterface $strategy = null
    )
    {
        $this->client = $client;
        $this->jobRegistry = $jobRegistry ?? JobRegistry::loadFromCache();
        $this->heartbeat = $heartbeat;
        $this->strategy = $strategy;
    }

    /**
     * Get the large message handler for chunk assembly.
     */
    public function getLargeMessageHandler(): LargeMessageHandlerInterface
    {
        return $this->client->getLargeMessageHandler();
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

        try {
            // Ждём до 30 сек — достаточно для heartbeat и быстрого завершения
            $this->client->waitOne(30.0);
        } catch (AMQPTimeoutException $e) {
            // Нормально: просто проверяем, не пора ли завершаться
            // Ничего не делаем — цикл while сам проверит $this->running
        }
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
        $decoded = json_decode($message->getBody(), true);

        // --- Handle chunked messages ---
        if ($this->client->isChunkedMessage($decoded)) {
            $this->handleChunkedMessage($decoded, $message);
            return;
        }
        // --- /handle chunked messages ---

        $jobName = $decoded['jobName'] ?? null;
        $payload = $decoded['payload'] ?? null;

        $this->stats['processed']++;

        if (!$jobName || !$payload) {
            $message->nack();
            $this->stats['failed']++;
            return;
        }

        $this->executeJob($jobName, $payload, $message);
    }

    /**
     * Handle a chunked message by assembling all chunks before processing.
     */
    private function handleChunkedMessage(array $chunk, AMQPMessage $message): void
    {
        $handler = $this->getLargeMessageHandler();
        $messageId = $chunk['meta']['messageId'] ?? null;

        if (!$messageId) {
            error_log('Chunked message missing messageId, rejecting');
            $message->nack(false, false);
            $this->stats['failed']++;
            return;
        }

        try {
            $isComplete = $handler->addChunk($chunk);

            if (!$isComplete) {
                // Not all chunks received yet, wait for more
                $message->ack();
                return;
            }

            // All chunks received, assemble the payload
            $assembledPayload = $handler->getAssembledPayload($messageId);

            if ($assembledPayload === null) {
                throw new \RuntimeException("Failed to assemble chunks for message $messageId");
            }

            $this->stats['processed']++;

            $jobName = $assembledPayload['jobName'] ?? null;
            $payload = $assembledPayload['payload'] ?? null;

            if (!$jobName || !$payload) {
                $message->nack();
                $this->stats['failed']++;
                return;
            }

            // Clear assembly buffer after successful processing
            $handler->clearAssembly($messageId);

            $this->executeJob($jobName, $payload, $message);

        } catch (\Throwable $e) {
            error_log('Error processing chunked message: ' . $e->getMessage());
            $handler->clearAssembly($messageId);
            $message->nack(false, false);
            $this->stats['failed']++;
        }
    }

    /**
     * Execute a job with the given payload.
     */
    private function executeJob(string $jobName, mixed $payload, AMQPMessage $message): void
    {
        // --- Проверка executeAt (страховка на стороне consumer) ---
        $executeAtStr = null;

        // meta на верхнем уровне - нужно брать из входящего decoded если это отложенное сообщение
        // Для обычных сообщений executeAt может быть в meta верхнего уровня
        if (!empty($payload['meta']['executeAt'])) {
            $executeAtStr = $payload['meta']['executeAt'];
        }

        if ($executeAtStr) {
            try {
                // ЖЁСТКО считаем, что executeAt — UTC
                $utc = new \DateTimeZone('UTC');
                $executeAt = new \DateTimeImmutable($executeAtStr, $utc);
                $now = new \DateTimeImmutable('now', $utc);

                $diffSeconds = $executeAt->getTimestamp() - $now->getTimestamp();

                // grace window — меньше секунды не переоткладываем
                if ($diffSeconds > 1) {

                    // защита от бесконечного ping-pong
                    $delayAttempts = $payload['meta']['delayAttempts'] ?? 0;
                    if ($delayAttempts >= 3) {
                        error_log('Max delayAttempts reached, processing job normally');
                    } else {
                        $delayMs = (int)($diffSeconds * 1000);

                        // увеличиваем счётчик
                        $payload['meta']['delayAttempts'] = $delayAttempts + 1;

                        // routing key
                        $routingKey = '';
                        try {
                            $rk = $message->get('routing_key');
                            if (is_string($rk)) {
                                $routingKey = $rk;
                            }
                        } catch (\Throwable $_) {
                            // ignore
                        }

                        $properties = [
                            'application_headers' => new AMQPTable([
                                'x-delay' => $delayMs
                            ])
                        ];

                        try {
                            $this->client->publish($payload, $properties, $routingKey);
                        } catch (\Throwable $e) {
                            error_log('Failed to republish early message with x-delay: ' . $e->getMessage());
                            $message->nack(false, true);
                            $this->stats['retried']++;
                            return;
                        }

                        // текущее сообщение закрываем
                        $message->ack();
                        $this->stats['retried']++;
                        return;
                    }
                }

                // время наступило — чистим executeAt, чтобы больше не мешал
                unset($payload['meta']['executeAt']);

            } catch (\Throwable $e) {
                error_log('Invalid executeAt format: ' . $e->getMessage());
                // продолжаем обычную обработку
            }
        }
    // --- /проверка executeAt ---


        try {
            $job = $this->jobRegistry->createJob($jobName, $payload);
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