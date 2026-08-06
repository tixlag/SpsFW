<?php

declare(strict_types=1);

namespace SpsFW\Core\Queue\Inbox;

use SpsFW\Core\Queue\JobResult;

final readonly class InboxHandlerRunner
{
    private InboxQuarantine $quarantine;

    public function __construct(
        private InboxStorage $storage,
        private InboxRetryPolicy $retryPolicy,
        private int $leaseSeconds = 300,
        ?InboxQuarantine $quarantine = null,
    ) {
        if ($this->leaseSeconds < 1) {
            throw new \InvalidArgumentException('Inbox lease must be positive.');
        }

        $this->quarantine = $quarantine ?? new InboxQuarantine($this->storage);
    }

    /**
     * @param callable(InboxMessage): JobResult $handler
     */
    public function run(
        string $messageId,
        string $consumerId,
        callable $handler,
        ?\DateTimeImmutable $now = null,
    ): JobResult {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $message = $this->storage->claim($messageId, $consumerId, $now, $this->leaseSeconds);

        if ($message === null) {
            $current = $this->storage->find($messageId);
            // A duplicate delivery must not acknowledge a non-terminal event. The broker
            // remains the retry scheduler; a competing lease or early redelivery is sent
            // back for another delivery instead of being converted into Success.
            return $current !== null && !$current->isTerminal()
                ? JobResult::Retry
                : JobResult::Success;
        }

        try {
            $result = $handler($message);
        } catch (\Throwable $exception) {
            return $this->retryOrQuarantine($message, $consumerId, $now, $exception->getMessage());
        }

        return match ($result) {
            JobResult::Success => $this->markProcessed($message, $consumerId, $now),
            JobResult::Retry => $this->retryOrQuarantine($message, $consumerId, $now, 'Handler requested retry.'),
            JobResult::Failed => $this->markQuarantined($message, $consumerId, $now, 'Handler reported a permanent failure.'),
        };
    }

    private function markProcessed(InboxMessage $message, string $consumerId, \DateTimeImmutable $now): JobResult
    {
        if ($this->storage->markProcessed($message, $now)) {
            return JobResult::Success;
        }

        $current = $this->storage->find($message->messageId);
        return $current !== null && $current->isTerminal()
            ? JobResult::Success
            : JobResult::Retry;
    }

    private function retryOrQuarantine(
        InboxMessage $message,
        string $consumerId,
        \DateTimeImmutable $now,
        string $error,
    ): JobResult {
        if ($this->retryPolicy->shouldQuarantine($message)) {
            return $this->markQuarantined($message, $consumerId, $now, $error);
        }

        if (!$this->storage->scheduleRetry($message, mb_substr($error, 0, 2000))) {
            $current = $this->storage->find($message->messageId);
            return $current !== null && $current->isTerminal()
                ? JobResult::Success
                : JobResult::Retry;
        }

        return JobResult::Retry;
    }

    private function markQuarantined(
        InboxMessage $message,
        string $consumerId,
        \DateTimeImmutable $now,
        string $error,
    ): JobResult {
        if ($this->quarantine->quarantine(
            $message,
            $now,
            $error,
        )) {
            return JobResult::Success;
        }

        $current = $this->storage->find($message->messageId);
        return $current !== null && $current->isTerminal()
            ? JobResult::Success
            : JobResult::Retry;
    }
}
