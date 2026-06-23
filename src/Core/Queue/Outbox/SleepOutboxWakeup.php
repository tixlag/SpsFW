<?php

declare(strict_types=1);

namespace SpsFW\Core\Queue\Outbox;

final class SleepOutboxWakeup implements OutboxWakeupInterface
{
    public function notify(\DateTimeImmutable $availableAt): void
    {
    }

    public function wait(int $timeoutMilliseconds): void
    {
        usleep(max(1, $timeoutMilliseconds) * 1000);
    }
}
