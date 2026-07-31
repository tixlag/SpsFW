<?php

declare(strict_types=1);

namespace SpsFW\Core\Queue\Outbox;

use PhpAmqpLib\Wire\AMQPTable;
use SpsFW\Core\Queue\PreparedMessageTransportInterface;
use SpsFW\Core\Queue\PreparedQueueMessage;

final readonly class OutboxRelay
{
    public function __construct(
        private OutboxStorage $storage,
        private PreparedMessageTransportInterface $transport,
        private int $leaseSeconds = 60,
        private int $maxRetryDelaySeconds = 300,
        private ?string $exchange = null,
        private ?string $routingKey = null,
        private int $maxAttempts = 10,
    ) {
    }

    public function runBatch(int $limit = 100): int
    {
        $published = 0;
        foreach ($this->claimDue($limit) as $message) {
            $properties = $message->properties;
            if (isset($properties['application_headers']) && is_array($properties['application_headers'])) {
                $properties['application_headers'] = new AMQPTable($properties['application_headers']);
            }

            $prepared = new PreparedQueueMessage(
                payload: $message->payload,
                properties: $properties,
                routingKey: $message->routingKey,
                exchange: $message->exchange,
                messageId: $message->messageId,
                availableAt: $message->availableAt,
            );

            try {
                $this->transport->publishPrepared($prepared, true);
                $this->storage->markPublished($message->id, $message->claimToken);
                $published++;
            } catch (\Throwable $exception) {
                $retryDelay = min(
                    $this->maxRetryDelaySeconds,
                    5 * (2 ** min(10, $message->attempts)),
                );
                $this->releaseFailed($message->id, $message->claimToken, $exception->getMessage(), $retryDelay);
            }
        }

        return $published;
    }

    public function nextAvailableAt(): ?\DateTimeImmutable
    {
        if ($this->exchange === null && $this->routingKey === null && $this->maxAttempts === 10) {
            return $this->storage->nextAvailableAt();
        }

        return $this->storage->nextAvailableAtFiltered($this->exchange, $this->routingKey, $this->maxAttempts);
    }

    /**
     * @return list<OutboxMessage>
     */
    private function claimDue(int $limit): array
    {
        if ($this->exchange === null && $this->routingKey === null && $this->maxAttempts === 10) {
            return $this->storage->claimDue($limit, $this->leaseSeconds);
        }

        return $this->storage->claimDueFiltered(
            $limit,
            $this->leaseSeconds,
            $this->exchange,
            $this->routingKey,
            $this->maxAttempts,
        );
    }

    private function releaseFailed(string $id, string $claimToken, string $error, int $retryDelay): void
    {
        if ($this->maxAttempts === 10) {
            $this->storage->releaseFailed($id, $claimToken, $error, $retryDelay);
            return;
        }

        $this->storage->releaseFailedWithLimit($id, $claimToken, $error, $retryDelay, $this->maxAttempts);
    }
}
