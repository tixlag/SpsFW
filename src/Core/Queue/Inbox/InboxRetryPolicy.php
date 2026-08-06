<?php

declare(strict_types=1);

namespace SpsFW\Core\Queue\Inbox;

final readonly class InboxRetryPolicy
{
    public function __construct(
        public int $maxAttempts = 5,
        public int $baseDelaySeconds = 5,
        public int $maxDelaySeconds = 300,
    ) {
        if ($this->maxAttempts < 1 || $this->baseDelaySeconds < 1 || $this->maxDelaySeconds < 1) {
            throw new \InvalidArgumentException('Inbox retry policy values must be positive.');
        }
    }

    public function shouldQuarantine(InboxMessage $message): bool
    {
        return $message->attempts >= $this->maxAttempts;
    }

    public function nextAttemptAt(InboxMessage $message, \DateTimeImmutable $now): \DateTimeImmutable
    {
        $power = min(10, max(0, $message->attempts - 1));
        $delay = min($this->maxDelaySeconds, $this->baseDelaySeconds * (2 ** $power));

        return $now->modify(sprintf('+%d seconds', $delay));
    }
}

