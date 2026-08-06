<?php

declare(strict_types=1);

use SpsFW\Core\Queue\Inbox\InboxHandlerRunner;
use SpsFW\Core\Queue\Inbox\InboxMessage;
use SpsFW\Core\Queue\Inbox\InboxQuarantine;
use SpsFW\Core\Queue\Inbox\InboxRetryPolicy;
use SpsFW\Core\Queue\Inbox\InboxStorage;
use SpsFW\Core\Queue\JobResult;

require_once dirname(__DIR__) . '/bootstrap.php';

final class InboxRunnerTestStorage implements InboxStorage
{
    public ?InboxMessage $message = null;
    public array $calls = [];
    public bool $simulateEarlyRedelivery = false;

    public function claim(string $messageId, string $consumerId, DateTimeImmutable $now, int $leaseSeconds): ?InboxMessage
    {
        $this->calls[] = ['claim', $messageId, $consumerId, $leaseSeconds];
        if ($this->message === null || $this->message->messageId !== $messageId || $this->message->isTerminal()) {
            return null;
        }
        if ($this->simulateEarlyRedelivery && $this->message->status === 'retrying') {
            return null;
        }

        return $this->message = new InboxMessage(
            messageId: $this->message->messageId,
            source: $this->message->source,
            type: $this->message->type,
            schemaVersion: $this->message->schemaVersion,
            payload: $this->message->payload,
            status: 'processing',
            attempts: $this->message->attempts + 1,
            nextAttemptAt: $this->message->nextAttemptAt,
            lockedUntil: $now->modify(sprintf('+%d seconds', $leaseSeconds)),
            lastError: $this->message->lastError,
            processedAt: $this->message->processedAt,
            quarantinedAt: $this->message->quarantinedAt,
            lockedBy: $consumerId,
            claimToken: 'claim-' . $consumerId,
            processingGeneration: $this->message->processingGeneration + 1,
        );
    }

    public function find(string $messageId): ?InboxMessage
    {
        return $this->message?->messageId === $messageId ? $this->message : null;
    }

    public function markProcessed(InboxMessage $message, DateTimeImmutable $processedAt): bool
    {
        if ($this->message?->claimToken !== $message->claimToken) {
            return false;
        }
        $this->calls[] = ['processed', $message->messageId, $message->lockedBy];
        $this->message = $this->message?->withStatus('processed', processedAt: $processedAt);
        return true;
    }

    public function scheduleRetry(InboxMessage $message, string $error): bool
    {
        if ($this->message?->claimToken !== $message->claimToken) {
            return false;
        }
        $this->calls[] = ['retry', $message->messageId, $message->lockedBy, $error];
        $this->message = $this->message?->withStatus('retrying', lastError: $error);
        return true;
    }

    public function quarantine(InboxMessage $message, DateTimeImmutable $quarantinedAt, string $error): bool
    {
        if ($this->message?->claimToken !== $message->claimToken) {
            return false;
        }
        $this->calls[] = ['quarantine', $message->messageId, $message->lockedBy, $error];
        $this->message = $this->message?->withStatus('quarantined', lastError: $error, quarantinedAt: $quarantinedAt);
        return true;
    }

}

function inbox_runner_test_message(string $status = 'pending', int $attempts = 0): InboxMessage
{
    return new InboxMessage(
        messageId: 'inbox-message-1',
        source: 'test',
        type: 'test.event',
        schemaVersion: 1,
        payload: ['value' => 1],
        status: $status,
        attempts: $attempts,
        nextAttemptAt: null,
        lockedUntil: null,
        lastError: null,
        processedAt: null,
        quarantinedAt: $status === 'quarantined' ? new DateTimeImmutable('2026-08-06T00:00:00+00:00') : null,
    );
}

$now = new DateTimeImmutable('2026-08-06T12:00:00+00:00');
$storage = new InboxRunnerTestStorage();
$quarantine = new InboxQuarantine($storage);
assert_true($storage instanceof InboxStorage, 'CRM adapters can implement the canonical InboxStorage contract');
$storage->message = inbox_runner_test_message();
$runner = new InboxHandlerRunner($storage, new InboxRetryPolicy(maxAttempts: 3, baseDelaySeconds: 5, maxDelaySeconds: 30));

$handled = 0;
assert_same(
    JobResult::Success,
    $runner->run('inbox-message-1', 'worker-1', static function (InboxMessage $message) use (&$handled): JobResult {
        $handled++;
        return JobResult::Success;
    }, $now),
    'successful inbox handler is acknowledged',
);
assert_same(1, $handled, 'fresh inbox message is handled once');
assert_same('processed', $storage->message?->status, 'successful inbox message is terminal');

$handled = 0;
assert_same(
    JobResult::Success,
    $runner->run('inbox-message-1', 'worker-2', static function () use (&$handled): JobResult {
        $handled++;
        return JobResult::Success;
    }, $now),
    'duplicate job for a terminal inbox message is acknowledged',
);
assert_same(0, $handled, 'terminal inbox message is a no-op');

$failedStatusRejected = false;
try {
    inbox_runner_test_message(status: 'failed');
} catch (InvalidArgumentException) {
    $failedStatusRejected = true;
}
assert_true($failedStatusRejected, 'failed is rejected instead of drifting from the database status contract');

$storage->message = inbox_runner_test_message();
assert_same(
    JobResult::Retry,
    $runner->run('inbox-message-1', 'worker-1', static function (): JobResult {
        return JobResult::Retry;
    }, $now),
    'retryable inbox result asks the queue to retry',
);
assert_same('retrying', $storage->message?->status, 'retryable inbox result stores retry state');
assert_same(null, $storage->message?->nextAttemptAt, 'retry state does not create a second database scheduler');

$storage->simulateEarlyRedelivery = true;
assert_same(
    JobResult::Retry,
    $runner->run('inbox-message-1', 'worker-2', static function (): JobResult {
        throw new RuntimeException('must not run while the broker redelivery is early');
    }, $now),
    'early broker redelivery of a non-terminal inbox event is never acknowledged',
);
$storage->simulateEarlyRedelivery = false;

$storage->message = inbox_runner_test_message(attempts: 2);
assert_same(
    JobResult::Success,
    $runner->run('inbox-message-1', 'worker-1', static function (): JobResult {
        throw new RuntimeException('poison');
    }, $now),
    'exhausted inbox failure is acknowledged after quarantine',
);
assert_same('quarantined', $storage->message?->status, 'exhausted inbox failure is quarantined');
echo "Inbox handler runner contract passed\n";
