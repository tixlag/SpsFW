<?php

declare(strict_types=1);

use SpsFW\Core\Queue\Interfaces\JobInterface;
use SpsFW\Core\Queue\Outbox\OutboxStorage;
use SpsFW\Core\Queue\Outbox\OutboxPublisher;
use SpsFW\Core\Queue\Outbox\OutboxWakeupInterface;
use SpsFW\Core\Queue\Outbox\TransactionManager;
use SpsFW\Core\Queue\Outbox\TransactionalOutboxPublisher;
use SpsFW\Core\Queue\PreparedQueueMessage;
use SpsFW\Core\Queue\RabbitMQClient;
use SpsFW\Core\Queue\RabbitMQQueuePublisher;

require_once dirname(__DIR__) . '/bootstrap.php';

final class ContractJob implements JobInterface
{
    public function getName(): string
    {
        return 'contract_job';
    }

    public function serialize(): string
    {
        return 'payload';
    }

    public static function deserialize(string $payload): static
    {
        return new self();
    }
}

final class CapturingOutboxStorage extends OutboxStorage
{
    public array $messages = [];

    public function savePrepared(PreparedQueueMessage $message, ?string $deduplicationKey = null): void
    {
        $this->messages[] = [$message, $deduplicationKey];
    }
}

final class ContractRabbitClient extends RabbitMQClient
{
    public function __construct()
    {
    }

    public function __destruct()
    {
    }
}

final class FailingRabbitPublisher extends RabbitMQQueuePublisher
{
    public function publish(JobInterface $job, array $options = []): void
    {
        throw new RuntimeException('broker unavailable');
    }
}

final class CapturingWakeup implements OutboxWakeupInterface
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

$client = new ContractRabbitClient();
$rabbitPublisher = new RabbitMQQueuePublisher($client, 'event.ready', 'crm.events');
$executeAt = new DateTimeImmutable('2026-06-23T10:15:00+00:00');

assert_true(method_exists(RabbitMQClient::class, 'publishReliable'), 'RabbitMQ client exposes reliable publication');

$prepared = $rabbitPublisher->prepare(new ContractJob(), [
    'executeAt' => $executeAt,
    'messageId' => 'message-1',
]);

assert_same('crm.events', $prepared->exchange, 'prepared message keeps default exchange');
assert_same('event.ready', $prepared->routingKey, 'prepared message keeps default routing key');
assert_same('message-1', $prepared->messageId, 'prepared message keeps message id');
assert_same($executeAt->format(DATE_ATOM), $prepared->payload['meta']['executeAt'], 'executeAt is serialized');
assert_same($executeAt->format(DATE_ATOM), $prepared->availableAt->format(DATE_ATOM), 'executeAt becomes availableAt');

$storage = new CapturingOutboxStorage();
$transactionPdo = new TransactionManagerPdo();
$transactionManager = new TransactionManager($transactionPdo);
$wakeup = new CapturingWakeup();
$publisher = new TransactionalOutboxPublisher($rabbitPublisher, $storage, $transactionManager, $wakeup);
$transactionManager->transactional(function () use ($publisher, $executeAt, $wakeup): void {
    $publisher->publishAt(new ContractJob(), $executeAt, [
        'messageId' => 'message-2',
        'deduplicationKey' => 'contract:2',
    ]);
    assert_same([], $wakeup->notified, 'outbox wakeup waits for transaction commit');
});

assert_same(1, count($storage->messages), 'transactional publisher stores one message');
assert_same('contract:2', $storage->messages[0][1], 'deduplication key is persisted');
assert_same('crm.events', $storage->messages[0][0]->exchange, 'stored message keeps exchange');
assert_same(1, count($wakeup->notified), 'outbox wakes relay after commit');

$fallbackStorage = new CapturingOutboxStorage();
$fallback = new OutboxPublisher(
    new FailingRabbitPublisher($client, 'event.ready', 'crm.events'),
    $fallbackStorage,
    0,
);
$fallback->publish(new ContractJob(), ['messageId' => 'message-3']);
assert_same(1, count($fallbackStorage->messages), 'fallback publisher stores failed publication');
assert_same('crm.events', $fallbackStorage->messages[0][0]->exchange, 'fallback keeps default exchange');
assert_same('event.ready', $fallbackStorage->messages[0][0]->routingKey, 'fallback keeps default routing key');

echo "Transactional outbox contract passed\n";
