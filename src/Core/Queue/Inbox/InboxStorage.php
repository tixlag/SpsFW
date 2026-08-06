<?php

declare(strict_types=1);

namespace SpsFW\Core\Queue\Inbox;

interface InboxStorage
{
    public function claim(
        string $messageId,
        string $consumerId,
        \DateTimeImmutable $now,
        int $leaseSeconds,
    ): ?InboxMessage;

    public function markProcessed(string $messageId, string $consumerId, \DateTimeImmutable $processedAt): void;

    public function scheduleRetry(
        string $messageId,
        string $consumerId,
        \DateTimeImmutable $nextAttemptAt,
        string $error,
    ): void;

    public function quarantine(
        string $messageId,
        string $consumerId,
        \DateTimeImmutable $quarantinedAt,
        string $error,
    ): void;

    public function replay(string $messageId, \DateTimeImmutable $replayedAt): void;
}

