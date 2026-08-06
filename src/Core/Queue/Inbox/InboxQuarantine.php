<?php

declare(strict_types=1);

namespace SpsFW\Core\Queue\Inbox;

final readonly class InboxQuarantine
{
    public function __construct(private InboxStorage $storage)
    {
    }

    public function quarantine(
        InboxMessage $message,
        string $consumerId,
        \DateTimeImmutable $at,
        string $error,
    ): void {
        $this->storage->quarantine($message->messageId, $consumerId, $at, mb_substr($error, 0, 2000));
    }

    public function replay(string $messageId, \DateTimeImmutable $at): void
    {
        $this->storage->replay($messageId, $at);
    }
}

