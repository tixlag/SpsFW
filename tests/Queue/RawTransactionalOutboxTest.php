<?php

declare(strict_types=1);

use SpsFW\Core\Queue\Outbox\TransactionManager;
use SpsFW\Core\Queue\Outbox\TransactionalOutboxPublisher;
use SpsFW\Core\Queue\Outbox\OutboxStorage;
use SpsFW\Core\Queue\Outbox\OutboxWakeupInterface;
use SpsFW\Core\Queue\PreparedQueueMessage;
use SpsFW\Core\Queue\RabbitMQQueuePublisher;

require_once dirname(__DIR__) . '/bootstrap.php';

final class RawCapturingOutboxStorage extends OutboxStorage
{
    public array $messages = [];

    public function savePrepared(PreparedQueueMessage $message, ?string $deduplicationKey = null): void
    {
        $this->messages[] = [$message, $deduplicationKey];
    }
}

final class RawTransactionManagerPdo extends PDO
{
    public bool $transaction = false;

    public function __construct()
    {
    }

    public function beginTransaction(): bool
    {
        $this->transaction = true;
        return true;
    }

    public function commit(): bool
    {
        $this->transaction = false;
        return true;
    }

    public function rollBack(): bool
    {
        $this->transaction = false;
        return true;
    }

    public function inTransaction(): bool
    {
        return $this->transaction;
    }
}

final class RawCapturingWakeup implements OutboxWakeupInterface
{
    public array $notified = [];

    public function notify(DateTimeImmutable $availableAt): void
    {
        $this->notified[] = $availableAt;
    }

    public function wait(int $timeoutMilliseconds): void
    {
    }
}

$executeAt = new DateTimeImmutable('2026-06-30T09:15:00+00:00');
$payload = [
    'broadcast_uuid' => '018f7af2-7c2e-7000-9f5a-6e9b0e0d0001',
    'title' => 'Test notification',
    'recipients' => ['018f7af2-7c2e-7000-9f5a-6e9b0e0d0002'],
];

$offlinePublisher = new RabbitMQQueuePublisher(null, 'broadcasts.send', 'golang.delayed.broadcasts');
$prepared = $offlinePublisher->preparePayload($payload, [
    'executeAt' => $executeAt,
    'messageId' => 'broadcast-message-1',
    'properties' => ['content_type' => 'application/json'],
]);

assert_same($payload, $prepared->payload, 'raw payload is not wrapped into job envelope');
assert_same('golang.delayed.broadcasts', $prepared->exchange, 'raw payload keeps default exchange');
assert_same('broadcasts.send', $prepared->routingKey, 'raw payload keeps default routing key');
assert_same('broadcast-message-1', $prepared->messageId, 'raw payload keeps explicit message id');
assert_same($executeAt->format(DATE_ATOM), $prepared->availableAt->format(DATE_ATOM), 'executeAt becomes raw payload availableAt');
assert_same('broadcast-message-1', $prepared->properties['message_id'], 'message_id property defaults to message id');
assert_same('application/json', $prepared->properties['content_type'], 'raw payload keeps custom properties');
assert_true(!isset($prepared->payload['jobName']), 'raw payload has no jobName field');

$storage = new RawCapturingOutboxStorage();
$transactionPdo = new RawTransactionManagerPdo();
$transactionManager = new TransactionManager($transactionPdo);
$wakeup = new RawCapturingWakeup();
$publisher = new TransactionalOutboxPublisher($offlinePublisher, $storage, $transactionManager, $wakeup);

$transactionManager->transactional(function () use ($publisher, $payload, $executeAt, $wakeup): void {
    $publisher->publishPayloadAt($payload, $executeAt, [
        'messageId' => 'broadcast-message-2',
        'deduplicationKey' => 'notifications-broadcast:018f7af2-7c2e-7000-9f5a-6e9b0e0d0001',
    ]);
    assert_same([], $wakeup->notified, 'raw outbox wakeup waits for transaction commit');
});

assert_same(1, count($storage->messages), 'raw transactional publisher stores one message');
assert_same($payload, $storage->messages[0][0]->payload, 'raw transactional publisher stores raw payload');
assert_same(
    'notifications-broadcast:018f7af2-7c2e-7000-9f5a-6e9b0e0d0001',
    $storage->messages[0][1],
    'raw transactional publisher stores deduplication key',
);
assert_same(1, count($wakeup->notified), 'raw outbox wakes relay after commit');

echo "Raw transactional outbox contract passed\n";
