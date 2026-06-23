<?php

declare(strict_types=1);

namespace SpsFW\Core\Queue\Outbox;

final class RedisOutboxWakeup implements OutboxWakeupInterface
{
    public function __construct(
        private readonly \Redis $redis,
        private readonly string $key = 'spsfw:outbox:wakeup',
    ) {
    }

    public function notify(\DateTimeImmutable $availableAt): void
    {
        $this->redis->lPush($this->key, $availableAt->format(DATE_ATOM));
        $this->redis->expire($this->key, 60);
    }

    public function wait(int $timeoutMilliseconds): void
    {
        $timeoutSeconds = max(1, (int) ceil($timeoutMilliseconds / 1000));
        $this->redis->brPop([$this->key], $timeoutSeconds);
    }
}
