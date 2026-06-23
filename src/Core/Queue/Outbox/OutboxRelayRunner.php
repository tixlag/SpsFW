<?php

declare(strict_types=1);

namespace SpsFW\Core\Queue\Outbox;

final readonly class OutboxRelayRunner
{
    public function __construct(
        private OutboxRelay $relay,
        private OutboxStorage $storage,
        private OutboxWakeupInterface $wakeup,
        private int $batchSize = 100,
        private int $fallbackMilliseconds = 10_000,
    ) {
    }

    public function run(callable $shouldStop): void
    {
        while (!$shouldStop()) {
            if ($this->relay->runBatch($this->batchSize) > 0) {
                continue;
            }

            $timeout = $this->fallbackMilliseconds;
            $nextAvailableAt = $this->storage->nextAvailableAt();
            if ($nextAvailableAt !== null) {
                $milliseconds = ($nextAvailableAt->getTimestamp() - time()) * 1000;
                $timeout = max(100, min($this->fallbackMilliseconds, $milliseconds));
            }

            $this->wakeup->wait($timeout);
        }
    }
}
