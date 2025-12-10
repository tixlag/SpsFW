<?php
namespace SpsFW\Core\Queue;

use PhpAmqpLib\Exchange\AMQPExchangeType;
use SpsFW\Core\Workers\WorkerConfig;

/**
 * Factory builds transport clients and publishers.
 * Keep retry/DLX configuration centralized here.
 */
class QueueClientAndPublisherFactory
{
    private RabbitMQConfig $config;
    private ?WorkerConfig $workerConfig;

    public function __construct(RabbitMQConfig $config, ?WorkerConfig $workerConfig = null)
    {
        $this->config = $config;
        $this->workerConfig = $workerConfig;
    }
    /**
     * Создаёт базовый publisher. Можно указать exchangeType и exchangeArguments (например для x-delayed-message).
     *
     * @param string $queueName
     * @param string $exchange
     * @param string $routingKey
     * @param string $exchangeType - e.g. AMQPExchangeType::DIRECT or 'x-delayed-message'
     * @param array $exchangeArguments - аргументы для exchange (например ['x-delayed-type' => 'direct'])
     */
    #[\Deprecated('Теперь создаем публикатор через createByWorkerName')]
    public function create(string $queueName,
                           string $exchange = '',
                           string $routingKey = '',
                           string $exchangeType = AMQPExchangeType::DIRECT,
                           array $exchangeArguments = []
    ): RabbitMQQueuePublisher
    {
        $cfg = array_merge($this->buildConfig(), [
            'exchange_arguments' => $exchangeArguments,
            // по умолчанию указываем queue_arguments пустыми
            'queue_arguments' => []
        ]);

        $client = new RabbitMQClient(
            exchange: $exchange,
            exchangeType: $exchangeType,
            queue: $queueName,
            routingKey: $routingKey,
            config: $cfg
        );
        return new RabbitMQQueuePublisher($client, $routingKey, $exchange);
    }

    /**
     * Создает публикатор задач в очередь по ее имени
     * @param string $workerName - название воркера
     * @return RabbitMQQueuePublisher
     */
    public function createByWorkerName(string $workerName): RabbitMQQueuePublisher
    {
        $workerConfig = $this->workerConfig->getQueueConfig($workerName);
        return $this->create(
            queueName: $workerConfig['queue'],
            exchange: $workerConfig['exchange'],
            routingKey: $workerConfig['routing_key'],
        );

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
    /**
     * Создаёт publisher, опционально с retry через DLX.
     * Поддерживает использование x-delayed-message в качестве main exchange.
     *
     * @param string $queueName
     * @param string $exchange
     * @param string $routingKey
     * @param int $retryDelayMs
     * @param int $maxRetries
     * @param string $exchangeType
     * @param array $exchangeArguments
     */
    public function createWithRetry(
        string $queueName,
        string $exchange = '',
        string $routingKey = '',
        int $retryDelayMs = 10000,
        int $maxRetries = 5,
        string $exchangeType = AMQPExchangeType::DIRECT,
        array $exchangeArguments = []
    ): RabbitMQQueuePublisher
    {
        $dlxExchange = $exchange . '.dlx';
        $retryQueue = $queueName . '.retry';
        $retryRouting = $routingKey . '.retry';

        // main queue DLX -> dlxExchange
        $mainArgs = ['x-dead-letter-exchange' => $dlxExchange, 'x-dead-letter-routing-key' => $retryRouting];

        // retry queue sends messages back to main exchange after TTL
        $retryArgs = [
            'x-dead-letter-exchange' => $exchange,
            'x-dead-letter-routing-key' => $routingKey,
            'x-message-ttl' => $retryDelayMs
        ];

        // create main client (could be x-delayed-message)
        $mainClient = new RabbitMQClient(
            exchange: $exchange,
            exchangeType: $exchangeType,
            queue: $queueName,
            routingKey: $routingKey,
            config: array_merge($this->buildConfig(), [
                'exchange_arguments' => $exchangeArguments,
                'queue_arguments' => $mainArgs
            ])
        );

        // ensure dlx exchange exists (direct)
        $dlxClient = new RabbitMQClient($dlxExchange, AMQPExchangeType::DIRECT, '', '', $this->buildConfig());

        // create retry queue bound to dlxExchange
        $retryClient = new RabbitMQClient(
            $dlxExchange,
            AMQPExchangeType::DIRECT,
            $retryQueue,
            $retryRouting,
            array_merge($this->buildConfig(), ['queue_arguments' => $retryArgs])
        );

        return new RabbitMQQueuePublisher($mainClient, $routingKey, $exchange);
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
