<?php

namespace SpsFW\Core\Queue;

use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SpsFW\Core\DI\DIContainer;
use SpsFW\Core\Psr\MonologLogger;
use SpsFW\Core\Queue\Exceptions\JobTimeoutException;
use SpsFW\Core\Queue\Heartbeat\WorkerStrategyInterface;
use SpsFW\Core\Queue\Interfaces\JobHandlerInterface;
use SpsFW\Core\Queue\Interfaces\JobInterface;
use SpsFW\Core\Queue\LargeMessage\LargeMessageHandlerInterface;

class RabbitMQWorkerRunner
{
    private RabbitMQClient $client;
    private JobRegistry $jobRegistry;
    private ?WorkerHeartbeat $heartbeat;
    private ?WorkerStrategyInterface $strategy;
    private WorkerExecutionPolicy $executionPolicy;
    private LoggerInterface $logger;

    private bool $isRunning = false;
    private string $workerId;
    private string $workerInstanceId;
    private string $hostname;
    private int $pid;
    private bool $pcntlWarningLogged = false;

    private array $stats = [
        'processed' => 0,
        'success' => 0,
        'failed' => 0,
        'retried' => 0,
        'dlq' => 0,
        'timeouts' => 0,
        'started_at' => null,
    ];

    private ?array $currentJobContext = null;

    public function __construct(
        RabbitMQClient $client,
        ?JobRegistry $jobRegistry = null,
        ?WorkerHeartbeat $heartbeat = null,
        ?WorkerStrategyInterface $strategy = null,
        ?WorkerExecutionPolicy $executionPolicy = null,
        ?LoggerInterface $logger = null,
        ?string $workerId = null,
        ?string $workerInstanceId = null
    ) {
        $this->client = $client;
        $this->jobRegistry = $jobRegistry ?? JobRegistry::loadFromCache();
        $this->heartbeat = $heartbeat;
        $this->strategy = $strategy;
        $this->executionPolicy = $executionPolicy ?? new WorkerExecutionPolicy();

        $this->hostname = gethostname() ?: 'unknown';
        $this->pid = getmypid() ?: 0;
        $this->workerId = $workerId
            ?: ($this->heartbeat?->getWorkerId() ?: ($this->client->getQueue() ?: 'queue-worker'));
        $this->logger = $logger ?? $this->resolveDefaultLogger($this->workerId);
        $this->workerInstanceId = $workerInstanceId
            ?: sprintf('%s@%s:%d:%d', $this->workerId, $this->hostname, $this->pid, time());

        if ($this->heartbeat) {
            $this->heartbeat->attachInstance($this->workerInstanceId);
        }
    }

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

        $this->client->startConsuming(function (AMQPMessage $message): void {
            $this->processMessage($message);
        });

        $this->updateHeartbeatStatus('running');
        $this->logger->info('worker_started', $this->baseContext());
    }

    public function runIteration(): void
    {
        $this->updateHeartbeatStatus('running');

        try {
            $this->client->waitOne(30.0);
        } catch (AMQPTimeoutException) {
            // Timeout is expected for heartbeat loops.
        }
    }

    public function stop(): void
    {
        $this->isRunning = false;
        $this->clearCurrentJobContext();

        $this->updateHeartbeatStatus('stopped');
        $this->client->stopConsuming();

        $this->logger->info('worker_stopped', $this->baseContext());
    }

    private function processMessage(AMQPMessage $message): void
    {
        $decoded = json_decode($message->getBody(), true);
        if (!is_array($decoded)) {
            $this->stats['processed']++;
            $this->stats['failed']++;

            $this->logger->warning('invalid_message_json', $this->baseContext([
                'message_body' => mb_substr($message->getBody(), 0, 500),
            ]));

            $this->handleRetryOrDlq(
                $message,
                [
                    'jobName' => 'unknown',
                    'payload' => $message->getBody(),
                    'meta' => ['schemaVersion' => 0],
                ],
                $this->resolveAttempt($message, []),
                'invalid_json',
                null,
                true
            );
            return;
        }

        if ($this->client->isChunkedMessage($decoded)) {
            $this->handleChunkedMessage($decoded, $message);
            return;
        }

        $this->stats['processed']++;
        $this->executeEnvelope($decoded, $message);
    }

    private function handleChunkedMessage(array $chunk, AMQPMessage $message): void
    {
        $handler = $this->getLargeMessageHandler();
        $messageId = $chunk['meta']['messageId'] ?? null;

        if (!is_string($messageId) || $messageId === '') {
            $this->stats['failed']++;
            $this->logger->error('chunk_missing_message_id', $this->baseContext());

            $this->handleRetryOrDlq(
                $message,
                [
                    'jobName' => $chunk['jobName'] ?? 'unknown',
                    'payload' => $chunk['payload'] ?? null,
                    'meta' => $chunk['meta'] ?? [],
                ],
                $this->resolveAttempt($message, $chunk['meta'] ?? []),
                'chunk_missing_message_id',
                null,
                true
            );
            return;
        }

        try {
            $isComplete = $handler->addChunk($chunk);

            if (!$isComplete) {
                $message->ack();
                $this->logger->debug('chunk_buffered', $this->baseContext([
                    'message_id' => $messageId,
                    'chunk_index' => $chunk['meta']['chunkIndex'] ?? null,
                    'total_chunks' => $chunk['meta']['totalChunks'] ?? null,
                ]));
                return;
            }

            $assembledPayload = $handler->getAssembledPayload($messageId);
            if (!is_array($assembledPayload)) {
                throw new \RuntimeException("Failed to assemble chunks for message $messageId");
            }

            $handler->clearAssembly($messageId);
            $this->stats['processed']++;
            $this->executeEnvelope($assembledPayload, $message);
        } catch (\Throwable $e) {
            $handler->clearAssembly($messageId);
            $this->stats['failed']++;

            $this->logger->error('chunk_processing_failed', $this->baseContext([
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]));

            $this->handleRetryOrDlq(
                $message,
                [
                    'jobName' => $chunk['jobName'] ?? 'unknown',
                    'payload' => $chunk['payload'] ?? null,
                    'meta' => $chunk['meta'] ?? [],
                ],
                $this->resolveAttempt($message, $chunk['meta'] ?? []),
                'chunk_processing_failed',
                $e
            );
        }
    }

    private function executeEnvelope(array $envelope, AMQPMessage $message): void
    {
        $jobName = isset($envelope['jobName']) && is_string($envelope['jobName']) ? $envelope['jobName'] : null;
        $payload = $envelope['payload'] ?? null;
        $meta = is_array($envelope['meta'] ?? null) ? $envelope['meta'] : [];

        $attempt = $this->resolveAttempt($message, $meta);
        $messageId = $this->resolveMessageId($message, $meta);

        if ($jobName === null || $payload === null) {
            $this->stats['failed']++;

            $this->logger->warning('invalid_envelope', $this->baseContext([
                'message_id' => $messageId,
                'attempt' => $attempt,
                'envelope_keys' => array_keys($envelope),
            ]));

            $this->handleRetryOrDlq($message, $envelope, $attempt, 'invalid_envelope', null, true);
            return;
        }

        if ($this->republishIfExecuteAtNotReached($envelope, $message, $attempt, $messageId, $jobName)) {
            return;
        }

        $startedAt = microtime(true);

        try {
            $job = $this->jobRegistry->createJob($jobName, $payload);
            $handler = $this->jobRegistry->getHandler($jobName);

            $this->setCurrentJobContext($jobName, $messageId, $attempt);
            $this->updateHeartbeatStatus('processing');

            $this->logger->info('job_started', $this->jobContext($jobName, $messageId, $attempt));

            $result = $this->runHandlerWithTimeout($handler, $job, $jobName);
            $durationMs = (int)round((microtime(true) - $startedAt) * 1000);

            switch ($result) {
                case JobResult::Success:
                    $message->ack();
                    $this->stats['success']++;
                    $this->logger->info('job_completed', $this->jobContext($jobName, $messageId, $attempt, [
                        'result' => JobResult::Success->value,
                        'duration_ms' => $durationMs,
                    ]));
                    break;

                case JobResult::Retry:
                    $this->handleRetryOrDlq($message, $envelope, $attempt, 'handler_requested_retry');
                    break;

                case JobResult::Failed:
                    $this->stats['failed']++;
                    $this->handleRetryOrDlq($message, $envelope, $attempt, 'handler_returned_failed', null, true);
                    break;
            }
        } catch (\Throwable $e) {
            $this->stats['failed']++;
            if ($e instanceof JobTimeoutException) {
                $this->stats['timeouts']++;
            }

            if ($this->heartbeat) {
                $this->heartbeat->setError($e->getMessage());
            }

            $this->logger->error('job_exception', $this->jobContext($jobName, $messageId, $attempt, [
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]));

            $reason = $e instanceof JobTimeoutException ? 'job_timeout' : 'job_exception';
            $this->handleRetryOrDlq($message, $envelope, $attempt, $reason, $e);
        } finally {
            $this->clearCurrentJobContext();
            $this->updateHeartbeatStatus('running');
        }
    }

    private function republishIfExecuteAtNotReached(
        array $envelope,
        AMQPMessage $message,
        int $attempt,
        string $messageId,
        string $jobName
    ): bool {
        $meta = is_array($envelope['meta'] ?? null) ? $envelope['meta'] : [];

        $executeAtRaw = $meta['executeAt'] ?? null;
        if (!is_string($executeAtRaw) || $executeAtRaw === '') {
            return false;
        }

        try {
            $utc = new \DateTimeZone('UTC');
            $executeAt = new \DateTimeImmutable($executeAtRaw, $utc);
            $now = new \DateTimeImmutable('now', $utc);
            $diffSeconds = $executeAt->getTimestamp() - $now->getTimestamp();

            if ($diffSeconds <= 1) {
                return false;
            }

            $delayAttempts = max(0, (int)($meta['delayAttempts'] ?? 0));
            if ($delayAttempts >= 3) {
                $this->logger->warning('execute_at_delay_limit_reached', $this->jobContext($jobName, $messageId, $attempt, [
                    'delay_attempts' => $delayAttempts,
                ]));
                return false;
            }

            $delayMs = (int)($diffSeconds * 1000);
            $routingKey = $this->resolveRoutingKey($message);
            $exchange = $this->client->getExchange();

            $envelope['meta']['delayAttempts'] = $delayAttempts + 1;
            $properties = [
                'message_id' => $messageId,
                'application_headers' => new AMQPTable([
                    'x-delay' => $delayMs,
                    'x-attempt' => $attempt,
                ]),
            ];

            $this->client->publish($envelope, $properties, $routingKey, $exchange);
            $message->ack();

            $this->stats['retried']++;
            $this->logger->info('job_delayed_republished', $this->jobContext($jobName, $messageId, $attempt, [
                'delay_ms' => $delayMs,
                'delay_attempts' => $envelope['meta']['delayAttempts'],
            ]));

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('job_delay_republish_failed', $this->jobContext($jobName, $messageId, $attempt, [
                'error' => $e->getMessage(),
            ]));

            $message->nack(false, true);
            return true;
        }
    }

    private function runHandlerWithTimeout(JobHandlerInterface $handler, JobInterface $job, string $jobName): JobResult
    {
        $timeoutSec = $this->executionPolicy->jobTimeoutSec;
        if ($timeoutSec <= 0) {
            return $handler->handle($job);
        }

        if (!extension_loaded('pcntl') || !function_exists('pcntl_signal') || !function_exists('pcntl_alarm')) {
            if (!$this->pcntlWarningLogged) {
                $this->pcntlWarningLogged = true;
                $this->logger->warning('pcntl_not_available_timeout_disabled', $this->baseContext([
                    'configured_timeout_sec' => $timeoutSec,
                ]));
            }
            return $handler->handle($job);
        }

        $previousHandler = function_exists('pcntl_signal_get_handler')
            ? pcntl_signal_get_handler(SIGALRM)
            : SIG_DFL;

        pcntl_async_signals(true);
        pcntl_signal(SIGALRM, static function () use ($jobName, $timeoutSec): void {
            throw new JobTimeoutException(sprintf('Job %s timed out after %d seconds', $jobName, $timeoutSec));
        });
        pcntl_alarm($timeoutSec);

        try {
            return $handler->handle($job);
        } finally {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, $previousHandler);
        }
    }

    private function handleRetryOrDlq(
        AMQPMessage $message,
        array $envelope,
        int $attempt,
        string $reason,
        ?\Throwable $error = null,
        bool $forceDlq = false
    ): void {
        $jobName = isset($envelope['jobName']) && is_string($envelope['jobName']) ? $envelope['jobName'] : 'unknown';
        $meta = is_array($envelope['meta'] ?? null) ? $envelope['meta'] : [];
        $messageId = $this->resolveMessageId($message, $meta);

        $shouldRetry = !$forceDlq && $attempt < $this->executionPolicy->maxRetries;

        if ($shouldRetry) {
            $nextAttempt = $attempt + 1;
            $retryEnvelope = $this->withFailureMeta($envelope, $nextAttempt, $reason, $error);

            $routingKey = $this->resolveRoutingKey($message);
            $exchange = $this->client->getExchange();

            $properties = [
                'message_id' => $messageId,
                'application_headers' => new AMQPTable([
                    'x-attempt' => $nextAttempt,
                    'x-retry-reason' => $reason,
                ]),
            ];

            try {
                $this->client->publish($retryEnvelope, $properties, $routingKey, $exchange);
                $message->ack();

                $this->stats['retried']++;
                $this->logger->warning('job_retry_scheduled', $this->jobContext($jobName, $messageId, $attempt, [
                    'next_attempt' => $nextAttempt,
                    'reason' => $reason,
                ]));
                return;
            } catch (\Throwable $publishError) {
                $this->logger->error('job_retry_publish_failed', $this->jobContext($jobName, $messageId, $attempt, [
                    'reason' => $reason,
                    'error' => $publishError->getMessage(),
                ]));

                $message->nack(false, true);
                return;
            }
        }

        if ($this->executionPolicy->enableDlq || $forceDlq) {
            try {
                $this->publishToDlq($message, $envelope, $attempt, $reason, $error);
                $message->ack();

                $this->stats['dlq']++;
                $this->logger->error('job_sent_to_dlq', $this->jobContext($jobName, $messageId, $attempt, [
                    'reason' => $reason,
                ]));
                return;
            } catch (\Throwable $dlqError) {
                $this->logger->critical('job_dlq_publish_failed', $this->jobContext($jobName, $messageId, $attempt, [
                    'reason' => $reason,
                    'error' => $dlqError->getMessage(),
                ]));

                $message->nack(false, true);
                return;
            }
        }

        $message->nack(false, true);
    }

    private function publishToDlq(
        AMQPMessage $message,
        array $envelope,
        int $attempt,
        string $reason,
        ?\Throwable $error = null
    ): void {
        $meta = is_array($envelope['meta'] ?? null) ? $envelope['meta'] : [];
        $messageId = $this->resolveMessageId($message, $meta);
        $routingKey = $this->resolveRoutingKey($message);

        $dlqExchange = $this->buildDlqExchange();
        $dlqRoutingKey = $this->buildDlqRoutingKey($routingKey);

        $dlqEnvelope = $this->withFailureMeta($envelope, $attempt, $reason, $error);
        $dlqEnvelope['meta']['dlqAt'] = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->format(\DateTime::ATOM);

        $this->client->publish(
            $dlqEnvelope,
            [
                'message_id' => $messageId,
                'application_headers' => new AMQPTable([
                    'x-attempt' => $attempt,
                    'x-failure-reason' => $reason,
                    'x-original-routing-key' => $routingKey,
                ]),
            ],
            $dlqRoutingKey,
            $dlqExchange
        );
    }

    private function withFailureMeta(array $envelope, int $attempt, string $reason, ?\Throwable $error = null): array
    {
        $meta = is_array($envelope['meta'] ?? null) ? $envelope['meta'] : [];
        $meta['attempt'] = $attempt;
        $meta['lastFailureReason'] = $reason;
        $meta['lastFailureAt'] = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->format(\DateTime::ATOM);

        if ($error !== null) {
            $meta['lastFailureMessage'] = $error->getMessage();
            $meta['lastFailureException'] = get_class($error);
        }

        $envelope['meta'] = $meta;

        return $envelope;
    }

    private function resolveAttempt(AMQPMessage $message, array $meta): int
    {
        $attempts = [
            max(0, (int)($meta['attempt'] ?? 0)),
        ];

        $headers = $this->extractHeaders($message);
        if (isset($headers['x-attempt'])) {
            $attempts[] = max(0, (int)$headers['x-attempt']);
        }

        return max($attempts);
    }

    private function resolveMessageId(AMQPMessage $message, array $meta): string
    {
        try {
            $messageId = $message->get('message_id');
            if (is_string($messageId) && $messageId !== '') {
                return $messageId;
            }
        } catch (\Throwable) {
            // ignore
        }

        if (isset($meta['messageId']) && is_string($meta['messageId']) && $meta['messageId'] !== '') {
            return $meta['messageId'];
        }

        return bin2hex(random_bytes(16));
    }

    private function resolveRoutingKey(AMQPMessage $message): string
    {
        try {
            $routing = $message->get('routing_key');
            if (is_string($routing) && $routing !== '') {
                return $routing;
            }
        } catch (\Throwable) {
            // ignore
        }

        if ($this->client->getRoutingKey() !== '') {
            return $this->client->getRoutingKey();
        }

        return $this->client->getQueue();
    }

    private function buildDlqExchange(): string
    {
        $exchange = $this->client->getExchange();
        if ($exchange === '') {
            return '';
        }

        return $exchange . '.dlx';
    }

    private function buildDlqRoutingKey(string $routingKey): string
    {
        if ($routingKey !== '') {
            return $routingKey . '.dlq';
        }

        return ($this->client->getQueue() ?: 'queue') . '.dlq';
    }

    /**
     * @return array<string, mixed>
     */
    private function extractHeaders(AMQPMessage $message): array
    {
        foreach (['application_headers', 'headers'] as $headerName) {
            try {
                $headers = $message->get($headerName);
                if ($headers instanceof AMQPTable) {
                    return $headers->getNativeData();
                }
                if (is_array($headers)) {
                    return $headers;
                }
            } catch (\Throwable) {
                // ignore
            }
        }

        return [];
    }

    private function setCurrentJobContext(string $jobName, string $messageId, int $attempt): void
    {
        $this->currentJobContext = [
            'current_job_name' => $jobName,
            'current_message_id' => $messageId,
            'current_attempt' => $attempt,
            'started_processing_at' => time(),
        ];
    }

    private function clearCurrentJobContext(): void
    {
        $this->currentJobContext = null;
    }

    private function updateHeartbeatStatus(string $status): void
    {
        if ($this->heartbeat === null) {
            return;
        }

        $data = array_merge($this->stats, [
            'worker_id' => $this->workerId,
            'worker_instance_id' => $this->workerInstanceId,
            'queue' => $this->client->getQueue(),
            'exchange' => $this->client->getExchange(),
            'routing_key' => $this->client->getRoutingKey(),
            'consumer_tag' => $this->client->getConsumerTag(),
            'hostname' => $this->hostname,
            'pid' => $this->pid,
        ]);

        if ($this->currentJobContext !== null) {
            $data = array_merge($data, $this->currentJobContext);
        }

        $this->heartbeat->beat();
        $this->heartbeat->setStatus($status, $data);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function baseContext(array $extra = []): array
    {
        return array_merge([
            'worker_id' => $this->workerId,
            'worker_instance_id' => $this->workerInstanceId,
            'queue' => $this->client->getQueue(),
            'exchange' => $this->client->getExchange(),
            'routing_key' => $this->client->getRoutingKey(),
            'consumer_tag' => $this->client->getConsumerTag(),
            'hostname' => $this->hostname,
            'pid' => $this->pid,
        ], $extra);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function jobContext(string $jobName, string $messageId, int $attempt, array $extra = []): array
    {
        return $this->baseContext(array_merge([
            'job_name' => $jobName,
            'message_id' => $messageId,
            'attempt' => $attempt,
        ], $extra));
    }

    public function isRunning(): bool
    {
        return $this->isRunning;
    }

    private function resolveDefaultLogger(string $workerId): LoggerInterface
    {
        try {
            $projectRoot = dirname(__DIR__, 3);
            $logFile = $projectRoot . '/.logs/workers/' . $workerId . '.log';
            return new MonologLogger($logFile, 'queue.' . $workerId);
        } catch (\Throwable) {
            // Fallback to DI logger if monolog logger could not be initialized.
        }

        try {
            $logger = DIContainer::getInstance()->get(LoggerInterface::class);
            if ($logger instanceof LoggerInterface) {
                return $logger;
            }
        } catch (\Throwable) {
            // Fallback to NullLogger when DI is not initialized in CLI bootstrap.
        }

        return new NullLogger();
    }
}
