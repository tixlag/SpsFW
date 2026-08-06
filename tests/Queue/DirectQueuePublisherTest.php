<?php

declare(strict_types=1);

use SpsFW\Core\Queue\DirectQueuePublisher;
use SpsFW\Core\Queue\Interfaces\JobInterface;
use SpsFW\Core\Queue\RabbitMQClient;

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

echo "Direct publisher contract passed\n";

