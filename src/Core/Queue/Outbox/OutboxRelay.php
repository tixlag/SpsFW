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
    ) {
    }

    public function runBatch(int $limit = 100): int
    {
        $published = 0;
        foreach ($this->storage->claimDue($limit, $this->leaseSeconds) as $message) {
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
                $this->storage->releaseFailed(
                    $message->id,
                    $message->claimToken,
                    $exception->getMessage(),
                    $retryDelay,
                );
            }
        }

        return $published;
    }
}
