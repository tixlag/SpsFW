<?php

declare(strict_types=1);

namespace SpsFW\Core\Queue\Outbox;

interface OutboxWakeupInterface
{
    public function notify(\DateTimeImmutable $availableAt): void;

    public function wait(int $timeoutMilliseconds): void;
}
