<?php

declare(strict_types=1);

namespace SpsFW\Core\Queue;

final readonly class PreparedQueueMessage
{
    public function __construct(
        public array $payload,
        public array $properties,
        public string $routingKey,
        public string $exchange,
        public string $messageId,
        public \DateTimeImmutable $availableAt,
    ) {
    }
}
