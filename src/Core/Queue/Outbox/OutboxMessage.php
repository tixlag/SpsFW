<?php

namespace SpsFW\Core\Queue\Outbox;

class OutboxMessage
{
    public function __construct(
        public readonly string            $id,
        public readonly array             $payload,
        public readonly array             $properties,
        public readonly string            $routingKey,
        public readonly string            $exchange,
        public readonly int               $attempts,
        public readonly \DateTimeImmutable $createdAt,
        public readonly string $messageId = '',
        public readonly ?\DateTimeImmutable $availableAt = null,
        public readonly string $claimToken = '',
    ) {}

    public static function fromRow(array $row): self
    {
        return new self(
            id:         $row['id'],
            payload:    json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR),
            properties: json_decode($row['properties'], true, 512, JSON_THROW_ON_ERROR),
            routingKey: $row['routing_key'],
            exchange:   $row['exchange'],
            attempts:   (int) $row['attempts'],
            createdAt:  new \DateTimeImmutable($row['created_at']),
            messageId: (string) ($row['message_id'] ?? ($row['payload']['meta']['messageId'] ?? '')),
            availableAt: new \DateTimeImmutable((string) ($row['available_at'] ?? $row['created_at'])),
            claimToken: (string) ($row['claim_token'] ?? ''),
        );
    }
}
