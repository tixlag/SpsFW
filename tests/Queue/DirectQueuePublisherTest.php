<?php

declare(strict_types=1);

use SpsFW\Core\Queue\DirectQueuePublisher;
use SpsFW\Core\Queue\Interfaces\JobInterface;
use SpsFW\Core\Queue\RabbitMQClient;
use SpsFW\Core\Queue\RabbitMQQueuePublisher;
use SpsFW\Core\Queue\QueueClientAndPublisherFactory;
use SpsFW\Core\Queue\Outbox\OutboxStorage;
use SpsFW\Core\Queue\Outbox\TransactionManager;
use SpsFW\Core\Queue\Outbox\TransactionalOutboxPublisher;
use SpsFW\Core\Queue\RabbitMQConfig;
use SpsFW\Core\Workers\WorkerConfig;

require_once dirname(__DIR__) . '/bootstrap.php';

final class DirectPublisherTestJob implements JobInterface
{
    public function getName(): string
    {
        return 'direct_test';
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

final class DirectPublisherTestClient extends RabbitMQClient
{
    public array $published = [];

    public function __construct()
    {
    }

    public function __destruct()
    {
    }

    public function publish(mixed $data, array $properties = [], ?string $routingKey = null, ?string $exchange = null): void
    {
        $this->published[] = [$data, $properties, $routingKey, $exchange];
    }
}

$client = new DirectPublisherTestClient();
$publisher = new DirectQueuePublisher($client, 'direct.test', 'crm.direct');
$publisher->publish(new DirectPublisherTestJob(), ['messageId' => 'direct-message-1']);

assert_same(1, count($client->published), 'direct publisher sends exactly one broker message');
assert_same('direct.test', $client->published[0][2], 'direct publisher keeps routing key');
assert_same('crm.direct', $client->published[0][3], 'direct publisher keeps exchange');

final class DirectFactoryTestFactory extends QueueClientAndPublisherFactory
{
    public function createWithoutOutbox(
        string $queueName,
        string $exchange = '',
        string $routingKey = '',
        string $exchangeType = \PhpAmqpLib\Exchange\AMQPExchangeType::DIRECT,
        array $exchangeArguments = [],
        ?\SpsFW\Core\Queue\LargeMessage\LargeMessageHandlerInterface $largeMessageHandler = null,
        array $queueArguments = [],
        array $bindingKeys = [],
    ): RabbitMQQueuePublisher {
        return new RabbitMQQueuePublisher(new DirectPublisherTestClient(), $routingKey, $exchange);
    }
}

$factory = new DirectFactoryTestFactory(
    new RabbitMQConfig('localhost', 5672, 'guest', 'guest', '/'),
    new WorkerConfig([
        'direct_worker' => [
            'type' => 'queueConsumer',
            'config' => [
                'queue' => 'direct.queue',
                'exchange' => 'direct.exchange',
                'routing_key' => 'direct.#',
                'publish_routing_key' => 'direct.job',
                'binding_keys' => ['direct.#'],
            ],
        ],
    ]),
);
$directFromFactory = $factory->createDirect('direct.queue', 'direct.exchange', 'direct.job');
assert_true($directFromFactory instanceof DirectQueuePublisher, 'factory direct method returns DirectQueuePublisher');
assert_true(!$directFromFactory instanceof TransactionalOutboxPublisher, 'direct method never returns an outbox publisher');
assert_true($factory->createByWorkerNameDirect('direct_worker') instanceof DirectQueuePublisher, 'named direct method returns DirectQueuePublisher');

$pdo = new class extends \PDO {
    public function __construct()
    {
    }
};
$outbox = new class ($pdo) extends OutboxStorage {
    public function __construct(private \PDO $testPdo)
    {
    }

    public function getPdo(string $id = 'db'): \PDO
    {
        return $this->testPdo;
    }
};
$factoryWithConfiguredOutbox = new DirectFactoryTestFactory(
    new RabbitMQConfig('localhost', 5672, 'guest', 'guest', '/'),
    new WorkerConfig([
        'direct_worker' => [
            'type' => 'queueConsumer',
            'config' => [
                'queue' => 'direct.queue',
                'exchange' => 'direct.exchange',
                'routing_key' => 'direct.#',
                'publish_routing_key' => 'direct.job',
                'binding_keys' => ['direct.#'],
            ],
        ],
    ]),
    null,
    $outbox,
);
$directWithConfiguredOutbox = $factoryWithConfiguredOutbox->createDirect(
    'direct.queue',
    'direct.exchange',
    'direct.job',
);
assert_true(
    $directWithConfiguredOutbox instanceof DirectQueuePublisher,
    'direct method remains direct even when the factory has an outbox configured',
);

$transactional = $factory->createForTransaction(
    queueName: 'direct.queue',
    transactionManager: new TransactionManager($pdo),
    exchange: 'direct.exchange',
    routingKey: 'direct.job',
    storage: $outbox,
);
assert_true($transactional instanceof TransactionalOutboxPublisher, 'transactional method returns TransactionalOutboxPublisher');
assert_true($factory->createByWorkerNameForTransaction('direct_worker', new TransactionManager($pdo), $outbox) instanceof TransactionalOutboxPublisher, 'named transactional method returns TransactionalOutboxPublisher');

echo "Direct publisher contract passed\n";
