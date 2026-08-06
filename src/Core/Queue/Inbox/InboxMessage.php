<?php

declare(strict_types=1);

namespace SpsFW\Core\Queue\Inbox;

final readonly class InboxMessage
{
    public function __construct(
        public string $messageId,
        public string $source,
        public string $type,
        public int $schemaVersion,
        public array $payload,
        public string $status,
        public int $attempts,
        public ?\DateTimeImmutable $nextAttemptAt,
        public ?\DateTimeImmutable $lockedUntil,
        public ?string $lastError,
        public ?\DateTimeImmutable $processedAt,
        public ?\DateTimeImmutable $quarantinedAt,
        public ?string $lockedBy = null,
        public ?string $claimToken = null,
        public int $processingGeneration = 0,
        /** Application-level message generation used to fence replayed deliveries. */
        public ?int $messageGeneration = null,
    ) {
        if (!in_array($this->status, ['pending', 'processing', 'retrying', 'processed', 'skipped', 'quarantined'], true)) {
            throw new \InvalidArgumentException('Unsupported inbox status: ' . $this->status);
        }
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, ['processed', 'skipped', 'quarantined'], true);
    }

    public function withStatus(
        string $status,
        ?\DateTimeImmutable $nextAttemptAt = null,
        ?string $lastError = null,
        ?\DateTimeImmutable $processedAt = null,
        ?\DateTimeImmutable $quarantinedAt = null,
    ): self {
        return new self(
            messageId: $this->messageId,
            source: $this->source,
            type: $this->type,
            schemaVersion: $this->schemaVersion,
            payload: $this->payload,
            status: $status,
            attempts: $this->attempts,
            nextAttemptAt: $nextAttemptAt,
            lockedUntil: null,
            lastError: $lastError,
            processedAt: $processedAt,
            quarantinedAt: $quarantinedAt,
            lockedBy: null,
            claimToken: null,
            processingGeneration: $this->processingGeneration,
            messageGeneration: $this->messageGeneration,
        );
    }
}
