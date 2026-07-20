<?php

declare(strict_types=1);

use SpsFW\Core\Queue\Interfaces\JobInterface;
use SpsFW\Core\Queue\Outbox\OutboxStorage;
use SpsFW\Core\Queue\Outbox\OutboxWakeupInterface;
use SpsFW\Core\Queue\Outbox\TransactionManager;
use SpsFW\Core\Queue\Outbox\TransactionalOutboxPublisher;
use SpsFW\Core\Queue\PreparedQueueMessage;
use SpsFW\Core\Queue\QueueClientAndPublisherFactory;
use SpsFW\Core\Queue\RabbitMQConfig;
use SpsFW\Core\Workers\WorkerConfig;

require_once dirname(__DIR__) . '/bootstrap.php';

final class BindingTestJob implements JobInterface
{
    public function getName(): string
    {
        return 'binding_test';
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

final class BindingTestPdo extends PDO
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

final class BindingTestOutboxStorage extends OutboxStorage
{
    /** @var list<array{PreparedQueueMessage, ?string}> */
    public array $messages = [];

    public function __construct(private readonly PDO $connection)
    {
    }

    public function getPdo(string $id = 'db'): PDO
    {
        return $this->connection;
    }

    public function savePrepared(PreparedQueueMessage $message, ?string $deduplicationKey = null): void
    {
        $this->messages[] = [$message, $deduplicationKey];
    }
}

final class BindingTestWakeup implements OutboxWakeupInterface
{
    /** @var list<DateTimeImmutable> */
    public array $notified = [];

    public function notify(DateTimeImmutable $availableAt): void
    {
        $this->notified[] = $availableAt;
    }

    public function wait(int $timeoutMilliseconds): void
    {
    }
}

$businessPdo = new BindingTestPdo();
$otherPdo = new BindingTestPdo();
$manager = new TransactionManager($businessPdo);
$sameConnectionStorage = new BindingTestOutboxStorage($businessPdo);
$otherConnectionStorage = new BindingTestOutboxStorage($otherPdo);
$wakeup = new BindingTestWakeup();
$workerConfig = new WorkerConfig([
    'binding_test' => [
        'type' => 'queueConsumer',
        'config' => [
            'queue' => 'binding.test',
            'exchange' => 'binding.events',
            'routing_key' => 'binding.test',
        ],
    ],
]);
$factory = new QueueClientAndPublisherFactory(
    new RabbitMQConfig('localhost', 5672, 'guest', 'guest'),
    $workerConfig,
);

assert_true($manager->manages($businessPdo), 'manager recognizes its exact PDO instance');
assert_true(!$manager->manages($otherPdo), 'manager rejects another PDO instance for the same role');

$publisher = $factory->createForTransaction(
    queueName: 'binding.test',
    transactionManager: $manager,
    exchange: 'binding.events',
    routingKey: 'binding.test',
    storage: $sameConnectionStorage,
    wakeup: $wakeup,
);
assert_true($publisher instanceof TransactionalOutboxPublisher, 'strict factory creates a transactional publisher');

$manager->transactional(function () use ($publisher, $wakeup): void {
    $publisher->publish(new BindingTestJob(), ['deduplicationKey' => 'binding:test:1']);
    assert_same([], $wakeup->notified, 'strict publisher defers wakeup until commit');
});
assert_same(1, count($sameConnectionStorage->messages), 'strict publisher stores one prepared message');
assert_same('binding:test:1', $sameConnectionStorage->messages[0][1], 'strict publisher keeps deduplication key');
assert_same(1, count($wakeup->notified), 'strict publisher wakes relay after commit');

try {
    $manager->transactional(function () use ($publisher): void {
        $publisher->publish(new BindingTestJob(), ['deduplicationKey' => 'binding:test:rollback']);
        throw new RuntimeException('force rollback');
    });
    throw new RuntimeException('rollback exception was not propagated');
} catch (RuntimeException $exception) {
    assert_same('force rollback', $exception->getMessage(), 'transaction propagates the caller error');
}
assert_same(1, count($wakeup->notified), 'rollback discards the pending relay wakeup');

$mismatchRejected = false;
try {
    $factory->createForTransaction(
        queueName: 'binding.test',
        transactionManager: $manager,
        storage: $otherConnectionStorage,
    );
} catch (LogicException $exception) {
    $mismatchRejected = str_contains($exception->getMessage(), 'same PDO instance');
}
assert_true($mismatchRejected, 'strict factory rejects an outbox storage on another PDO');

$workerPublisher = $factory->createByWorkerNameForTransaction(
    workerName: 'binding_test',
    transactionManager: $manager,
    storage: $sameConnectionStorage,
);
assert_true($workerPublisher instanceof TransactionalOutboxPublisher, 'strict worker factory accepts the same PDO');

$workerMismatchRejected = false;
try {
    $factory->createByWorkerNameForTransaction(
        workerName: 'binding_test',
        transactionManager: $manager,
        storage: $otherConnectionStorage,
    );
} catch (LogicException $exception) {
    $workerMismatchRejected = str_contains($exception->getMessage(), 'same PDO instance');
}
assert_true($workerMismatchRejected, 'strict worker factory rejects another PDO');

// Backwards compatibility: the legacy nullable-manager entrypoint keeps its previous behavior.
$legacyPublisher = $factory->createTransactional(
    queueName: 'binding.legacy',
    storage: $otherConnectionStorage,
    transactionManager: $manager,
);
assert_true($legacyPublisher instanceof TransactionalOutboxPublisher, 'legacy factory entrypoint remains available');

echo "Transactional outbox binding contract passed\n";
