<?php

declare(strict_types=1);

namespace SpsFW\Core\Queue\Outbox;

use SpsFW\Core\Queue\Interfaces\JobInterface;
use SpsFW\Core\Queue\Interfaces\QueuePublisherInterface;
use SpsFW\Core\Queue\RabbitMQQueuePublisher;

final readonly class TransactionalOutboxPublisher implements QueuePublisherInterface
{
    public function __construct(
        private RabbitMQQueuePublisher $publisher,
        private OutboxStorage $storage,
        private ?TransactionManager $transactionManager = null,
        private ?OutboxWakeupInterface $wakeup = null,
    ) {
    }

    public function publish(JobInterface $job, array $options = []): void
    {
        $deduplicationKey = isset($options['deduplicationKey'])
            ? trim((string) $options['deduplicationKey'])
            : null;
        unset($options['deduplicationKey']);

        $prepared = $this->publisher->prepare($job, $options);
        $this->storePrepared($prepared, $deduplicationKey);
    }

    /**
     * Сохраняет готовое тело JSON-сообщения в outbox без обертки фреймворка.
     *
     * publish(JobInterface) подходит для PHP-воркеров SpsFW, но добавляет
     * jobName/payload/meta. Для очередей, которые читает внешний сервис со своим
     * контрактом сообщения, нужно использовать этот метод.
     */
    public function publishPayload(array $payload, array $options = []): void
    {
        $deduplicationKey = isset($options['deduplicationKey'])
            ? trim((string) $options['deduplicationKey'])
            : null;
        unset($options['deduplicationKey']);

        $prepared = $this->publisher->preparePayload($payload, $options);
        $this->storePrepared($prepared, $deduplicationKey);
    }

    public function publishAt(JobInterface $job, \DateTimeInterface $when, array $options = []): void
    {
        $options['executeAt'] = $when;
        $this->publish($job, $options);
    }

    /**
     * Сохраняет готовое тело сообщения в outbox с временем доставки.
     *
     * Это аналог publishAt(JobInterface) для внешних потребителей. Время
     * доставки попадет в queue_outbox.available_at, а Redis-пробуждение будет
     * выполнено только после успешного commit через TransactionManager.
     */
    public function publishPayloadAt(array $payload, \DateTimeInterface $when, array $options = []): void
    {
        $options['executeAt'] = $when;
        $this->publishPayload($payload, $options);
    }

    private function storePrepared(\SpsFW\Core\Queue\PreparedQueueMessage $prepared, ?string $deduplicationKey): void
    {
        $this->storage->savePrepared(
            $prepared,
            $deduplicationKey !== '' ? $deduplicationKey : null,
        );

        if ($this->wakeup !== null) {
            $notify = function () use ($prepared): void {
                try {
                    $this->wakeup?->notify($prepared->availableAt);
                } catch (\Throwable) {
                    // Wakeups reduce latency only. The indexed relay fallback
                    // remains authoritative and the outbox row is committed.
                }
            };
            if ($this->transactionManager !== null) {
                $this->transactionManager->afterCommit($notify);
            } else {
                $notify();
            }
        }
    }
}
