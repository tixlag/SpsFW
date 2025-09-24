<?php
namespace SpsFW\Core\Queue;

use PhpAmqpLib\Exchange\AMQPExchangeType;

/**
 * Factory builds transport clients and publishers.
 * Keep retry/DLX configuration centralized here.
 */
class QueueClientAndPublisherFactory
{
    private RabbitMQConfig $config;

    public function __construct(RabbitMQConfig $config)
    {
        $this->config = $config;
    }

    public function create(string $queueName, string $exchange = '', string $routingKey = ''): RabbitMQQueuePublisher
    {
        $client = new RabbitMQClient(
            exchange: $exchange,
            exchangeType: AMQPExchangeType::DIRECT,
            queue: $queueName,
            routingKey: $routingKey,
            config: $this->buildConfig()
        );
        return new RabbitMQQueuePublisher($client, $routingKey);
    }

    public function createClient(string $queueName, string $exchange = '', string $routingKey = ''): RabbitMQClient
    {
        return new RabbitMQClient(
            exchange: $exchange,
            exchangeType: AMQPExchangeType::DIRECT,
            queue: $queueName,
            routingKey: $routingKey,
            config: $this->buildConfig()
        );
    }

    /**
     * Create queue + retry queue + DLX. Retry delay in ms.
     */
    public function createWithRetry(string $queueName, string $exchange = '', string $routingKey = '', int $retryDelayMs = 10000, int $maxRetries = 5): RabbitMQQueuePublisher
    {
        $dlxExchange = $exchange . '.dlx';
        $retryQueue = $queueName . '.retry';
        $retryRouting = $routingKey . '.retry';

        // main queue bound to exchange and has DLX pointing to dlxExchange -> retryQueue
        $mainArgs = ['x-dead-letter-exchange' => $dlxExchange, 'x-dead-letter-routing-key' => $retryRouting];

        // retry queue sends messages back to main exchange after TTL
        $retryArgs = [
            'x-dead-letter-exchange' => $exchange,
            'x-dead-letter-routing-key' => $routingKey,
            'x-message-ttl' => $retryDelayMs
        ];

        $mainClient = new RabbitMQClient($exchange, AMQPExchangeType::DIRECT, $queueName, $routingKey, array_merge($this->buildConfig(), ['queue_arguments' => $mainArgs]));
        $retryClient = new RabbitMQClient($dlxExchange, AMQPExchangeType::DIRECT, $retryQueue, $retryRouting, array_merge($this->buildConfig(), ['queue_arguments' => $retryArgs]));
        // ensure dlx exchange exists
        $dlxClient = new RabbitMQClient($dlxExchange, AMQPExchangeType::DIRECT, '', '', $this->buildConfig());

        return new RabbitMQQueuePublisher($mainClient, $routingKey);
    }

    private function buildConfig(): array
    {
        return [
            'host' => $this->config->host,
            'port' => $this->config->port,
            'user' => $this->config->user,
            'password' => $this->config->password,
            'vhost' => $this->config->vhost,
            'connection_timeout' => $this->config->connectionTimeout,
            'read_write_timeout' => $this->config->readWriteTimeout,
            'heartbeat' => $this->config->heartbeat,
            'exchange_durable' => true,
            'queue_durable' => true,
        ];
    }
}
