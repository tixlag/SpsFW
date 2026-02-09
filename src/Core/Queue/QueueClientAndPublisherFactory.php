<?php
namespace SpsFW\Core\Queue;

use PhpAmqpLib\Exchange\AMQPExchangeType;
use SpsFW\Core\Queue\LargeMessage\ChunkedMessageHandler;
use SpsFW\Core\Queue\LargeMessage\LargeMessageHandlerInterface;
use SpsFW\Core\Workers\WorkerConfig;

/**
 * Factory builds transport clients and publishers.
 * Keep retry/DLX configuration centralized here.
 */
class QueueClientAndPublisherFactory
{
    private RabbitMQConfig $config;
    private ?WorkerConfig $workerConfig;
    private ?LargeMessageHandlerInterface $largeMessageHandler;

    public function __construct(
        RabbitMQConfig $config,
        ?WorkerConfig $workerConfig = null,
        ?LargeMessageHandlerInterface $largeMessageHandler = null
    ) {
        $this->config = $config;
        $this->workerConfig = $workerConfig;
        $this->largeMessageHandler = $largeMessageHandler;
    }

    /**
     * Создаёт базовый publisher с поддержкой больших сообщений.
     *
     * @param string $queueName
     * @param string $exchange
     * @param string $routingKey
     * @param string $exchangeType - e.g. AMQPExchangeType::DIRECT or 'x-delayed-message'
     * @param array $exchangeArguments - аргументы для exchange
     * @param LargeMessageHandlerInterface|null обработчик для больших сообщений
     */
    public function create(
        string $queueName,
        string $exchange = "",
        string $routingKey = "",
        string $exchangeType = AMQPExchangeType::DIRECT,
        array $exchangeArguments = [],
        ?LargeMessageHandlerInterface $largeMessageHandler = null
    ): RabbitMQQueuePublisher {
        $cfg = array_merge($this->buildConfig(), [
            "exchange_arguments" => $exchangeArguments,
            "queue_arguments" => [],
        ]);

        $handler = $largeMessageHandler ?? $this->largeMessageHandler;

        $client = new RabbitMQClient(
            exchange: $exchange,
            exchangeType: $exchangeType,
            queue: $queueName,
            routingKey: $routingKey,
            config: $cfg,
            largeMessageHandler: $handler,
        );
        return new RabbitMQQueuePublisher($client, $routingKey, $exchange);
    }

    /**
     * Создает публикатор задач в очередь по ее имени
     * @param string $workerName - название воркера
     * @return RabbitMQQueuePublisher
     */
    public function createByWorkerName(
        string $workerName,
    ): RabbitMQQueuePublisher {
        $workerConfig = $this->workerConfig->getQueueConfig($workerName);
        $exchangeType = $workerConfig["delayed"]
            ? "x-delayed-message"
            : AMQPExchangeType::DIRECT;

        return $this->create(
            queueName: $workerConfig["queue"],
            exchange: $workerConfig["exchange"],
            routingKey: $workerConfig["routing_key"],
            exchangeType: $exchangeType,
            exchangeArguments: $workerConfig["exchange_arguments"] ?? [],
        );
    }

    public function createClientByWorkerName(string $workerName): RabbitMQClient
    {
        $workerConfig = $this->workerConfig->getQueueConfig($workerName);
        $exchangeType = $workerConfig["delayed"]
            ? "x-delayed-message"
            : AMQPExchangeType::DIRECT;

        $handler = $this->largeMessageHandler;

        return new RabbitMQClient(
            exchange: $workerConfig["exchange"],
            exchangeType: $exchangeType,
            queue: $workerConfig["queue"],
            routingKey: $workerConfig["routing_key"],
            config: array_merge($this->buildConfig(), [
                "exchange_arguments" =>
                    $workerConfig["exchange_arguments"] ?? [],
            ]),
            largeMessageHandler: $handler,
        );
    }

    public function createClient(
        string $queueName,
        string $exchange = "",
        string $routingKey = "",
        ?LargeMessageHandlerInterface $largeMessageHandler = null
    ): RabbitMQClient {
        $handler = $largeMessageHandler ?? $this->largeMessageHandler;

        return new RabbitMQClient(
            exchange: $exchange,
            exchangeType: AMQPExchangeType::DIRECT,
            queue: $queueName,
            routingKey: $routingKey,
            config: $this->buildConfig(),
            largeMessageHandler: $handler,
        );
    }

    /**
     * Создаёт publisher, опционально с retry через DLX.
     * Поддерживает использование x-delayed-message в качестве main exchange.
     */
    public function createWithRetry(
        string $queueName,
        string $exchange = "",
        string $routingKey = "",
        int $retryDelayMs = 10000,
        int $maxRetries = 5,
        string $exchangeType = AMQPExchangeType::DIRECT,
        array $exchangeArguments = [],
        ?LargeMessageHandlerInterface $largeMessageHandler = null
    ): RabbitMQQueuePublisher {
        $dlxExchange = $exchange . ".dlx";
        $retryQueue = $queueName . ".retry";
        $retryRouting = $routingKey . ".retry";

        // main queue DLX -> dlxExchange
        $mainArgs = [
            "x-dead-letter-exchange" => $dlxExchange,
            "x-dead-letter-routing-key" => $retryRouting,
        ];

        // retry queue sends messages back to main exchange after TTL
        $retryArgs = [
            "x-dead-letter-exchange" => $exchange,
            "x-dead-letter-routing-key" => $routingKey,
            "x-message-ttl" => $retryDelayMs,
        ];

        $handler = $largeMessageHandler ?? $this->largeMessageHandler;

        // create main client (could be x-delayed-message)
        $mainClient = new RabbitMQClient(
            exchange: $exchange,
            exchangeType: $exchangeType,
            queue: $queueName,
            routingKey: $routingKey,
            config: array_merge($this->buildConfig(), [
                "exchange_arguments" => $exchangeArguments,
                "queue_arguments" => $mainArgs,
            ]),
            largeMessageHandler: $handler,
        );

        // ensure dlx exchange exists (direct)
        $dlxClient = new RabbitMQClient(
            $dlxExchange,
            AMQPExchangeType::DIRECT,
            "",
            "",
            $this->buildConfig(),
        );

        // create retry queue bound to dlxExchange
        $retryClient = new RabbitMQClient(
            $dlxExchange,
            AMQPExchangeType::DIRECT,
            $retryQueue,
            $retryRouting,
            array_merge($this->buildConfig(), [
                "queue_arguments" => $retryArgs,
            ]),
        );

        return new RabbitMQQueuePublisher($mainClient, $routingKey, $exchange);
    }

    /**
     * Создаёт обработчик для больших сообщений с указанными параметрами.
     *
     * @param int $chunkSize Размер чанка в байтах (по умолчанию 8MB)
     * @param bool $enableCompression Включить сжатие GZIP
     * @param string $checksumAlgo Алгоритм_checksum (md5, sha256)
     */
    public static function createLargeMessageHandler(
        int $chunkSize = 8 * 1024 * 1024,
        bool $enableCompression = true,
        string $checksumAlgo = 'md5'
    ): LargeMessageHandlerInterface {
        return new ChunkedMessageHandler($chunkSize, $enableCompression, $checksumAlgo);
    }

    private function buildConfig(): array
    {
        return [
            "host" => $this->config->host,
            "port" => $this->config->port,
            "user" => $this->config->user,
            "password" => $this->config->password,
            "vhost" => $this->config->vhost,
            "connection_timeout" => $this->config->connectionTimeout,
            "read_write_timeout" => $this->config->readWriteTimeout,
            "heartbeat" => $this->config->heartbeat,
            "exchange_durable" => true,
            "queue_durable" => true,
        ];
    }
}
