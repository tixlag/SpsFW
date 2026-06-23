<?php
namespace SpsFW\Core\Queue;

use PhpAmqpLib\Exchange\AMQPExchangeType;
use SpsFW\Core\Queue\Interfaces\QueuePublisherInterface;
use SpsFW\Core\Queue\LargeMessage\ChunkedMessageHandler;
use SpsFW\Core\Queue\LargeMessage\LargeMessageHandlerInterface;
use SpsFW\Core\Queue\Outbox\OutboxPublisher;
use SpsFW\Core\Queue\Outbox\OutboxStorage;
use SpsFW\Core\Queue\Outbox\OutboxWakeupInterface;
use SpsFW\Core\Queue\Outbox\TransactionManager;
use SpsFW\Core\Queue\Outbox\TransactionalOutboxPublisher;
use SpsFW\Core\Workers\WorkerConfig;

/**
 * Factory builds transport clients and publishers.
 * Keep retry/DLX configuration centralized here.
 *
 * Outbox Pattern:
 *   Если передать OutboxStorage в конструктор, все методы create() / createByWorkerName() /
 *   createWithRetry() автоматически вернут OutboxPublisher — при сбое RabbitMQ сообщения
 *   будут сохраняться в БД и публиковаться при восстановлении.
 *
 *   Для явного отказа от outbox используйте методы createWithoutOutbox() / createByWorkerNameWithoutOutbox().
 */
class QueueClientAndPublisherFactory
{
    private RabbitMQConfig $config;
    private ?WorkerConfig $workerConfig;
    private ?LargeMessageHandlerInterface $largeMessageHandler;
    private ?OutboxStorage $outboxStorage;

    public function __construct(
        RabbitMQConfig $config,
        ?WorkerConfig $workerConfig = null,
        ?LargeMessageHandlerInterface $largeMessageHandler = null,
        ?OutboxStorage $outboxStorage = null,
    ) {
        $this->config = $config;
        $this->workerConfig = $workerConfig;
        $this->largeMessageHandler = $largeMessageHandler;
        $this->outboxStorage = $outboxStorage;
    }

    // -------------------------------------------------------------------------
    // create() — умный алиас: outbox, если хранилище задано в конструкторе
    // -------------------------------------------------------------------------

    /**
     * Создаёт publisher.
     *
     * Если в конструктор фабрики передан OutboxStorage — возвращает OutboxPublisher
     * (сообщения сохраняются в БД при недоступности RabbitMQ).
     * Иначе — RabbitMQQueuePublisher (прежнее поведение, полная обратная совместимость).
     *
     * @return OutboxPublisher|RabbitMQQueuePublisher
     */
    public function create(
        string $queueName,
        string $exchange = "",
        string $routingKey = "",
        string $exchangeType = AMQPExchangeType::DIRECT,
        array $exchangeArguments = [],
        ?LargeMessageHandlerInterface $largeMessageHandler = null
    ): QueuePublisherInterface {
        $publisher = $this->createWithoutOutbox($queueName, $exchange, $routingKey, $exchangeType, $exchangeArguments, $largeMessageHandler);

        if ($this->outboxStorage !== null) {
            return new OutboxPublisher($publisher, $this->outboxStorage);
        }

        return $publisher;
    }

    /**
     * Создаёт publisher по имени воркера из конфига.
     * Автоматически активирует outbox, если хранилище задано в конструкторе.
     *
     * @return OutboxPublisher|RabbitMQQueuePublisher
     */
    public function createByWorkerName(string $workerName): QueuePublisherInterface
    {
        $publisher = $this->createByWorkerNameWithoutOutbox($workerName);

        if ($this->outboxStorage !== null) {
            return new OutboxPublisher($publisher, $this->outboxStorage);
        }

        return $publisher;
    }

    /**
     * Создаёт publisher с retry через DLX.
     * Автоматически активирует outbox, если хранилище задано в конструкторе.
     *
     * @return OutboxPublisher|RabbitMQQueuePublisher
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
    ): QueuePublisherInterface {
        $publisher = $this->createWithRetryWithoutOutbox($queueName, $exchange, $routingKey, $retryDelayMs, $maxRetries, $exchangeType, $exchangeArguments, $largeMessageHandler);

        if ($this->outboxStorage !== null) {
            return new OutboxPublisher($publisher, $this->outboxStorage);
        }

        return $publisher;
    }

    // -------------------------------------------------------------------------
    // WithoutOutbox — явный обход outbox
    // -------------------------------------------------------------------------

    /**
     * Создаёт базовый RabbitMQQueuePublisher без обёртки outbox.
     * Используйте, когда потеря сообщения при сбое RabbitMQ допустима.
     *
     * @param string $exchangeType - e.g. AMQPExchangeType::DIRECT или 'x-delayed-message'
     * @param array  $exchangeArguments - аргументы для exchange
     */
    public function createWithoutOutbox(
        string $queueName,
        string $exchange = "",
        string $routingKey = "",
        string $exchangeType = AMQPExchangeType::DIRECT,
        array $exchangeArguments = [],
        ?LargeMessageHandlerInterface $largeMessageHandler = null,
        array $queueArguments = [],
        array $bindingKeys = [],
    ): RabbitMQQueuePublisher {
        $cfg = array_merge($this->buildConfig(), [
            "exchange_arguments" => $exchangeArguments,
            "queue_arguments"    => $queueArguments,
            "binding_keys"       => $bindingKeys !== [] ? $bindingKeys : ($routingKey !== '' ? [$routingKey] : []),
        ]);

        $handler = $largeMessageHandler ?? $this->largeMessageHandler;

        $client = new RabbitMQClient(
            exchange:            $exchange,
            exchangeType:        $exchangeType,
            queue:               $queueName,
            routingKey:          $routingKey,
            config:              $cfg,
            largeMessageHandler: $handler,
        );

        $this->ensureDlqTopology($queueName, $exchange, $routingKey);

        return new RabbitMQQueuePublisher($client, $routingKey, $exchange);
    }

    /**
     * Создаёт RabbitMQQueuePublisher по имени воркера без обёртки outbox.
     */
    public function createByWorkerNameWithoutOutbox(string $workerName): RabbitMQQueuePublisher
    {
        $workerConfig = $this->workerConfig->getQueueConfig($workerName);
        if ($workerConfig === null) {
            throw new \InvalidArgumentException("Unknown queue worker: {$workerName}");
        }
        $exchangeType = $workerConfig['exchange_type']
            ?? (!empty($workerConfig["delayed"]) ? "x-delayed-message" : AMQPExchangeType::DIRECT);
        $publishRoutingKey = $workerConfig['publish_routing_key'] ?? $workerConfig['routing_key'];

        return $this->createWithoutOutbox(
            queueName:       $workerConfig["queue"],
            exchange:        $workerConfig["exchange"],
            routingKey:      $publishRoutingKey,
            exchangeType:    $exchangeType,
            exchangeArguments: $workerConfig["exchange_arguments"] ?? [],
            queueArguments: $workerConfig['queue_arguments'] ?? [],
            bindingKeys: $workerConfig['binding_keys'] ?? [$workerConfig['routing_key']],
        );
    }

    /**
     * Создаёт RabbitMQQueuePublisher с retry через DLX без обёртки outbox.
     */
    public function createWithRetryWithoutOutbox(
        string $queueName,
        string $exchange = "",
        string $routingKey = "",
        int $retryDelayMs = 10000,
        int $maxRetries = 5,
        string $exchangeType = AMQPExchangeType::DIRECT,
        array $exchangeArguments = [],
        ?LargeMessageHandlerInterface $largeMessageHandler = null
    ): RabbitMQQueuePublisher {
        $dlxExchange  = $exchange . ".dlx";
        $retryQueue   = $queueName . ".retry";
        $retryRouting = $routingKey . ".retry";

        $mainArgs = [
            "x-dead-letter-exchange"     => $dlxExchange,
            "x-dead-letter-routing-key"  => $retryRouting,
        ];

        $retryArgs = [
            "x-dead-letter-exchange"    => $exchange,
            "x-dead-letter-routing-key" => $routingKey,
            "x-message-ttl"             => $retryDelayMs,
        ];

        $handler = $largeMessageHandler ?? $this->largeMessageHandler;

        $mainClient = new RabbitMQClient(
            exchange:     $exchange,
            exchangeType: $exchangeType,
            queue:        $queueName,
            routingKey:   $routingKey,
            config:       array_merge($this->buildConfig(), [
                "exchange_arguments" => $exchangeArguments,
                "queue_arguments"    => $mainArgs,
            ]),
            largeMessageHandler: $handler,
        );

        // ensure dlx exchange exists (direct)
        new RabbitMQClient(
            $dlxExchange,
            AMQPExchangeType::DIRECT,
            "",
            "",
            $this->buildConfig(),
        );

        // create retry queue bound to dlxExchange
        new RabbitMQClient(
            $dlxExchange,
            AMQPExchangeType::DIRECT,
            $retryQueue,
            $retryRouting,
            array_merge($this->buildConfig(), [
                "queue_arguments" => $retryArgs,
            ]),
        );

        $this->ensureDlqTopology($queueName, $exchange, $routingKey);

        return new RabbitMQQueuePublisher($mainClient, $routingKey, $exchange);
    }

    // -------------------------------------------------------------------------
    // WithOutbox — явное создание outbox-publisher с указанием хранилища
    // -------------------------------------------------------------------------

    /**
     * Создаёт OutboxPublisher с явно указанным хранилищем.
     * Используйте, если хранилище не было передано в конструктор фабрики,
     * или если нужен отдельный storage с другими параметрами.
     */
    public function createWithOutbox(
        string $queueName,
        string $exchange = "",
        string $routingKey = "",
        ?OutboxStorage $storage = null,
        int $autoFlushBatch = 10,
        string $exchangeType = AMQPExchangeType::DIRECT,
        array $exchangeArguments = [],
        ?LargeMessageHandlerInterface $largeMessageHandler = null,
    ): OutboxPublisher {
        $storage ??= $this->outboxStorage ?? throw new \LogicException(
            'OutboxStorage must be provided either via createWithOutbox() argument or QueueClientAndPublisherFactory constructor.'
        );
        $publisher = $this->createWithoutOutbox($queueName, $exchange, $routingKey, $exchangeType, $exchangeArguments, $largeMessageHandler);
        return new OutboxPublisher($publisher, $storage, $autoFlushBatch);
    }

    /**
     * Создаёт OutboxPublisher по имени воркера с явно указанным хранилищем.
     */
    public function createByWorkerNameWithOutbox(
        string $workerName,
        ?OutboxStorage $storage = null,
        int $autoFlushBatch = 10,
    ): OutboxPublisher {
        $storage ??= $this->outboxStorage ?? throw new \LogicException(
            'OutboxStorage must be provided either via createByWorkerNameWithOutbox() argument or QueueClientAndPublisherFactory constructor.'
        );
        $publisher = $this->createByWorkerNameWithoutOutbox($workerName);
        return new OutboxPublisher($publisher, $storage, $autoFlushBatch);
    }

    public function createTransactional(
        string $queueName,
        string $exchange = '',
        string $routingKey = '',
        ?OutboxStorage $storage = null,
        ?TransactionManager $transactionManager = null,
        ?OutboxWakeupInterface $wakeup = null,
        string $exchangeType = AMQPExchangeType::DIRECT,
        array $exchangeArguments = [],
        array $queueArguments = [],
        array $bindingKeys = [],
    ): TransactionalOutboxPublisher {
        $storage ??= $this->outboxStorage ?? throw new \LogicException(
            'OutboxStorage must be provided for transactional publication.',
        );

        return new TransactionalOutboxPublisher(
            new RabbitMQQueuePublisher(null, $routingKey, $exchange),
            $storage,
            $transactionManager,
            $wakeup,
        );
    }

    public function createByWorkerNameTransactional(
        string $workerName,
        ?OutboxStorage $storage = null,
        ?TransactionManager $transactionManager = null,
        ?OutboxWakeupInterface $wakeup = null,
    ): TransactionalOutboxPublisher {
        $storage ??= $this->outboxStorage ?? throw new \LogicException(
            'OutboxStorage must be provided for transactional publication.',
        );

        $workerConfig = $this->workerConfig?->getQueueConfig($workerName);
        if ($workerConfig === null) {
            throw new \InvalidArgumentException("Unknown queue worker: {$workerName}");
        }

        return new TransactionalOutboxPublisher(
            new RabbitMQQueuePublisher(
                null,
                $workerConfig['publish_routing_key'] ?? $workerConfig['routing_key'],
                $workerConfig['exchange'],
            ),
            $storage,
            $transactionManager,
            $wakeup,
        );
    }

    // -------------------------------------------------------------------------
    // Client factories
    // -------------------------------------------------------------------------

    public function createClientByWorkerName(string $workerName): RabbitMQClient
    {
        $workerConfig = $this->workerConfig->getQueueConfig($workerName);
        if ($workerConfig === null) {
            throw new \InvalidArgumentException("Unknown queue worker: {$workerName}");
        }
        $exchangeType = $workerConfig['exchange_type']
            ?? (!empty($workerConfig["delayed"]) ? "x-delayed-message" : AMQPExchangeType::DIRECT);
        $publishRoutingKey = $workerConfig['publish_routing_key'] ?? $workerConfig['routing_key'];

        $client = new RabbitMQClient(
            exchange:     $workerConfig["exchange"],
            exchangeType: $exchangeType,
            queue:        $workerConfig["queue"],
            routingKey:   $publishRoutingKey,
            config:       array_merge($this->buildConfig(), [
                "exchange_arguments" => $workerConfig["exchange_arguments"] ?? [],
                "queue_arguments" => $workerConfig["queue_arguments"] ?? [],
                "binding_keys" => $workerConfig['binding_keys'] ?? [$workerConfig['routing_key']],
            ]),
            largeMessageHandler: $this->largeMessageHandler,
        );

        $this->ensureDlqTopology(
            $workerConfig["queue"],
            $workerConfig["exchange"],
            $publishRoutingKey
        );

        return $client;
    }

    public function createClient(
        string $queueName,
        string $exchange = "",
        string $routingKey = "",
        ?LargeMessageHandlerInterface $largeMessageHandler = null
    ): RabbitMQClient {
        $handler = $largeMessageHandler ?? $this->largeMessageHandler;

        $client = new RabbitMQClient(
            exchange:            $exchange,
            exchangeType:        AMQPExchangeType::DIRECT,
            queue:               $queueName,
            routingKey:          $routingKey,
            config:              $this->buildConfig(),
            largeMessageHandler: $handler,
        );

        $this->ensureDlqTopology($queueName, $exchange, $routingKey);

        return $client;
    }

    public function createClientWithArguments(
        string $queueName,
        string $exchange = "",
        string $routingKey = "",
        array $queueArguments = [],
        ?LargeMessageHandlerInterface $largeMessageHandler = null
    ): RabbitMQClient {
        $handler = $largeMessageHandler ?? $this->largeMessageHandler;

        $client = new RabbitMQClient(
            exchange:            $exchange,
            exchangeType:        AMQPExchangeType::DIRECT,
            queue:               $queueName,
            routingKey:          $routingKey,
            config:              array_merge($this->buildConfig(), [
                'queue_arguments' => $queueArguments
            ]),
            largeMessageHandler: $handler,
        );

        return $client;
    }

    // -------------------------------------------------------------------------
    // Static helpers
    // -------------------------------------------------------------------------

    /**
     * Создаёт обработчик для больших сообщений с указанными параметрами.
     *
     * @param int    $chunkSize         Размер чанка в байтах (по умолчанию 8MB)
     * @param bool   $enableCompression Включить сжатие GZIP
     * @param string $checksumAlgo      Алгоритм checksum (md5, sha256)
     */
    public static function createLargeMessageHandler(
        int $chunkSize = 8 * 1024 * 1024,
        bool $enableCompression = true,
        string $checksumAlgo = 'md5'
    ): LargeMessageHandlerInterface {
        return new ChunkedMessageHandler($chunkSize, $enableCompression, $checksumAlgo);
    }

    // -------------------------------------------------------------------------

    private function buildConfig(): array
    {
        $heartbeat = max(0, (int)$this->config->heartbeat);
        $readWriteTimeout = (float)$this->config->readWriteTimeout;

        if ($heartbeat > 0) {
            $readWriteTimeout = max($readWriteTimeout, (float)($heartbeat * 2));
        }

        if ($readWriteTimeout <= 0.0) {
            $readWriteTimeout = $heartbeat > 0 ? (float)($heartbeat * 2) : 60.0;
        }

        return [
            "host"               => $this->config->host,
            "port"               => $this->config->port,
            "user"               => $this->config->user,
            "password"           => $this->config->password,
            "vhost"              => $this->config->vhost,
            "connection_timeout" => $this->config->connectionTimeout,
            "read_write_timeout" => $readWriteTimeout,
            "heartbeat"          => $heartbeat,
            "prefetch"           => 1,
            "exchange_durable"   => true,
            "queue_durable"      => true,
        ];
    }

    private function ensureDlqTopology(string $queueName, string $exchange, string $routingKey): void
    {
        if ($queueName === '' || $exchange === '' || $routingKey === '') {
            return;
        }

        new RabbitMQClient(
            exchange:     $exchange . ".dlx",
            exchangeType: AMQPExchangeType::DIRECT,
            queue:        $queueName . ".dlq",
            routingKey:   $routingKey . ".dlq",
            config:       $this->buildConfig(),
        );
    }
}
