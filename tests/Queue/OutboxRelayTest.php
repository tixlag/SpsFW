<?php

declare(strict_types=1);

use SpsFW\Core\Queue\Outbox\OutboxMessage;
use SpsFW\Core\Queue\Outbox\OutboxRelay;
use SpsFW\Core\Queue\Outbox\OutboxStorage;
use SpsFW\Core\Queue\PreparedMessageTransportInterface;
use SpsFW\Core\Queue\PreparedQueueMessage;

require_once dirname(__DIR__) . '/bootstrap.php';

final class RelayStorage extends OutboxStorage
{
    /** @var list<OutboxMessage> */
    public array $claimed = [];
    public array $published = [];
    public array $failed = [];

    public function claimDue(int $limit, int $leaseSeconds): array
    {
        return array_splice($this->claimed, 0, $limit);
    }

    public function markPublished(string $id, string $claimToken): void
    {
        $this->published[] = [$id, $claimToken];
    }

    public function releaseFailed(string $id, string $claimToken, string $error, int $retryDelaySeconds): void
    {
        $this->failed[] = [$id, $claimToken, $error, $retryDelaySeconds];
    }
}

final class RelayTransport implements PreparedMessageTransportInterface
{
    public array $messages = [];
    public bool $fail = false;

    public function publishPrepared(PreparedQueueMessage $message, bool $reliable = false): void
    {
        if ($this->fail) {
            throw new RuntimeException('broker unavailable');
        }
        $this->messages[] = [$message, $reliable];
    }
}

function relay_message(string $id, int $attempts = 0): OutboxMessage
{
    return new OutboxMessage(
        id: $id,
        payload: ['jobName' => 'deliver', 'payload' => ['id' => $id], 'meta' => ['messageId' => 'm-' . $id]],
        properties: [],
        routingKey: 'reminder.deliver',
        exchange: 'crm.reminders',
        attempts: $attempts,
        createdAt: new DateTimeImmutable('2026-06-23T00:00:00+00:00'),
        messageId: 'm-' . $id,
        availableAt: new DateTimeImmutable('2026-06-23T00:00:00+00:00'),
        claimToken: 'claim-' . $id,
    );
}

$storage = new RelayStorage();
$transport = new RelayTransport();
$storage->claimed = [relay_message('one')];
$relay = new OutboxRelay($storage, $transport);

assert_same(1, $relay->runBatch(10), 'relay reports one published message');
assert_same([['one', 'claim-one']], $storage->published, 'relay removes message using claim token');
assert_same(true, $transport->messages[0][1], 'relay requests reliable publication');

$storage->claimed = [relay_message('two', 2)];
$transport->fail = true;
assert_same(0, $relay->runBatch(10), 'failed publication is not counted');
assert_same('two', $storage->failed[0][0], 'failed message is released');
assert_same(20, $storage->failed[0][3], 'retry uses bounded exponential backoff');

echo "Outbox relay contract passed\n";
