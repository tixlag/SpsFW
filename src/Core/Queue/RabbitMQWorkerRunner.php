<?php

namespace SpsFW\Core\Queue;

use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
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
        ?JobRegistry              $jobRegistry = null,
        ?WorkerHeartbeat         $heartbeat = null,
        ?WorkerStrategyInterface $strategy = null
    )
    {
        $this->client = $client;
        $this->jobRegistry = $jobRegistry ?? JobRegistry::loadFromCache();
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
        $jobName = $decoded['jobName'] ?? null;
        $payload = $decoded['payload'] ?? null;

        $this->stats['processed']++;

        if (!$jobName || !$payload) {
            $message->nack();
            $this->stats['failed']++;
            return;
        }

        // --- Проверка executeAt (страховка на стороне consumer) ---
        $executeAtStr = null;

        // Обычное место: meta на верхнем уровне (поскольку publisher кладёт meta в decoded['meta'])
        if (!empty($decoded['meta']['executeAt'])) {
            $executeAtStr = $decoded['meta']['executeAt'];
        }
        // На всякий случай: если payload распарсен в массив и там есть meta
        elseif (is_array($payload) && !empty($payload['meta']['executeAt'])) {
            $executeAtStr = $payload['meta']['executeAt'];
        }

        if ($executeAtStr) {
            try {
                $executeAt = new \DateTimeImmutable($executeAtStr);
                $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

                if ($executeAt > $now) {
                    // сообщение пришло раньше времени — републикуем в delayed exchange с оставшимся delay
                    $delayMs = (int)(($executeAt->getTimestamp() - $now->getTimestamp()) * 1000);
                    if ($delayMs < 0) {
                        $delayMs = 0;
                    }

                    // Определим routing key для републикации
                    // Попытаемся взять свойство 'routing_key' из AMQPMessage, иначе пустую строку
                    $routingKey = '';
                    try {
                        $rk = $message->get('routing_key');
                        if (is_string($rk)) {
                            $routingKey = $rk;
                        }
                    } catch (\Throwable $_) {
                        // ignore
                    }

                    // Соберём свойства с заголовком x-delay (AMQPTable)
                    $properties = [];
                    $properties['application_headers'] = new AMQPTable(['x-delay' => $delayMs]);

                    // Републикация: используем клиент, чтобы отправить то же тело в тот же exchange (если клиент знает exchange),
                    // либо можно явно указать exchange в параметрах (если нужно) — здесь используем publish(..., $properties, $routingKey)
                    try {
                        $this->client->publish($decoded, $properties, $routingKey);
                    } catch (\Throwable $e) {
                        // Не удалось републиковать — логируем и позволим обработке идти дальше (чтобы не потерять задачу)
                        error_log('Failed to republish early message with x-delay: ' . $e->getMessage());
                        // В таком случае не делаем ack — чтобы задача могла быть requeued/retried by broker/policy
                        $message->nack(false, true);
                        $this->stats['retried']++;
                        return;
                    }

                    // Успешно републиковали — помечаем текущее сообщение как обработанное
                    $message->ack();
                    $this->stats['retried']++;
                    return;
                }
            } catch (\Exception $e) {
                // Неправильный формат даты — логируем и продолжаем обработку обычным образом
                error_log('Invalid executeAt format: ' . $e->getMessage());
            }
        }
        // --- /проверка executeAt ---

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