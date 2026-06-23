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

    public function publishAt(JobInterface $job, \DateTimeInterface $when, array $options = []): void
    {
        $options['executeAt'] = $when;
        $this->publish($job, $options);
    }
}
