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

    public function claim(string $messageId, string $consumerId, DateTimeImmutable $now, int $leaseSeconds): ?InboxMessage
    {
        $this->calls[] = ['claim', $messageId, $consumerId, $leaseSeconds];
        if ($this->message === null || $this->message->messageId !== $messageId || $this->message->isTerminal()) {
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
        );
    }

    public function markProcessed(string $messageId, string $consumerId, DateTimeImmutable $processedAt): void
    {
        $this->calls[] = ['processed', $messageId, $consumerId];
        $this->message = $this->message?->withStatus('processed', processedAt: $processedAt);
    }

    public function scheduleRetry(string $messageId, string $consumerId, DateTimeImmutable $nextAttemptAt, string $error): void
    {
        $this->calls[] = ['retry', $messageId, $consumerId, $nextAttemptAt, $error];
        $this->message = $this->message?->withStatus('retrying', nextAttemptAt: $nextAttemptAt, lastError: $error);
    }

    public function quarantine(string $messageId, string $consumerId, DateTimeImmutable $quarantinedAt, string $error): void
    {
        $this->calls[] = ['quarantine', $messageId, $consumerId, $error];
        $this->message = $this->message?->withStatus('quarantined', lastError: $error, quarantinedAt: $quarantinedAt);
    }

    public function replay(string $messageId, DateTimeImmutable $replayedAt): void
    {
        $this->calls[] = ['replay', $messageId];
        $this->message = $this->message?->withStatus('pending', nextAttemptAt: $replayedAt, lastError: null, quarantinedAt: null);
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

assert_true(inbox_runner_test_message(status: 'failed')->isTerminal(), 'failed inbox message is terminal');

$storage->message = inbox_runner_test_message();
assert_same(
    JobResult::Retry,
    $runner->run('inbox-message-1', 'worker-1', static function (): JobResult {
        return JobResult::Retry;
    }, $now),
    'retryable inbox result asks the queue to retry',
);
assert_same('retrying', $storage->message?->status, 'retryable inbox result stores retry state');
assert_same('2026-08-06T12:00:05+00:00', $storage->message?->nextAttemptAt?->format(DATE_ATOM), 'retry uses bounded backoff');

$storage->message = inbox_runner_test_message(attempts: 2);
assert_same(
    JobResult::Success,
    $runner->run('inbox-message-1', 'worker-1', static function (): JobResult {
        throw new RuntimeException('poison');
    }, $now),
    'exhausted inbox failure is acknowledged after quarantine',
);
assert_same('quarantined', $storage->message?->status, 'exhausted inbox failure is quarantined');
$quarantine->replay('inbox-message-1', $now);
assert_same('pending', $storage->message?->status, 'quarantine replay returns the message to pending');

echo "Inbox handler runner contract passed\n";
