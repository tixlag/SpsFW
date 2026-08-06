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
        ?int $expectedGeneration = null,
    ): ?InboxMessage;

    public function find(string $messageId): ?InboxMessage;

    public function markProcessed(InboxMessage $message, \DateTimeImmutable $processedAt): bool;

    public function scheduleRetry(
        InboxMessage $message,
        string $error,
    ): bool;

    public function quarantine(
        InboxMessage $message,
        \DateTimeImmutable $quarantinedAt,
        string $error,
    ): bool;

}
