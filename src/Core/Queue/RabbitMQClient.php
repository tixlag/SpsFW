<?php

namespace SpsFW\Core\Queue;

use Exception;
use InvalidArgumentException;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use PhpAmqpLib\Exception\AMQPIOException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use SpsFW\Core\Queue\LargeMessage\ChunkedMessageHandler;
use SpsFW\Core\Queue\LargeMessage\LargeMessageHandlerInterface;


class RabbitMQClient
{
    private AMQPStreamConnection $connection;
    private \PhpAmqpLib\Channel\AbstractChannel|\PhpAmqpLib\Channel\AMQPChannel $channel;
    private string $exchange;
    private string $queue;
    private string $routingKey;
    private string $consumerTag = '';
    private ?LargeMessageHandlerInterface $largeMessageHandler;

    /**
     * Инициализация подключения к RabbitMQ
     *
     * @param string $exchange Название обменника
     * @param string $exchangeType Тип обменника (direct, fanout, topic, headers)
     * @param string $queue Название очереди
     * @param string $routingKey Ключ маршрутизации
     * @param array|null $config Конфигурация подключения
     * @param LargeMessageHandlerInterface|null Обработчик для больших сообщений
     * @throws Exception
     */
    public function __construct(
        string $exchange = '',
        string $exchangeType = AMQPExchangeType::DIRECT,
        string $queue = '',
        string $routingKey = '',
        ?array $config = null,
        ?LargeMessageHandlerInterface $largeMessageHandler = null
    )
    {
        $this->largeMessageHandler = $largeMessageHandler ?? new ChunkedMessageHandler();
        $config = $config ?? [];
        $this->validateConfig($config);

        $this->exchange = $exchange;
        $this->queue = $queue;
        $this->routingKey = $routingKey;

        $heartbeat = (int)($config['heartbeat'] ?? 30);
        $readWriteTimeout = (float)($config['read_write_timeout'] ?? 60.0);
        if ($heartbeat > 0 && $readWriteTimeout < ($heartbeat * 2)) {
            $readWriteTimeout = (float)($heartbeat * 2);
        }

        $prefetch = max(1, (int)($config['prefetch'] ?? 1));

        try {
            // Установка соединения
            $this->connection = new AMQPStreamConnection(
                $config['host'],
                $config['port'],
                $config['user'],
                $config['password'],
                $config['vhost'] ?? '/',
                $config['insist'] ?? false,
                $config['login_method'] ?? 'AMQPLAIN',
                $config['login_response'] ?? null,
                $config['locale'] ?? 'en_US',
                $config['connection_timeout'] ?? 3.0,
                $readWriteTimeout,
                $config['context'] ?? null,
                $config['keepalive'] ?? false,
                $heartbeat
            );

            $this->channel = $this->connection->channel();
            $this->channel->basic_qos(null, $prefetch, null);

            // Объявление обменника
            if ($exchange) {

                // Специальный случай: delayed exchange
                if ($exchangeType === 'x-delayed-message') {

                    if (isset($config['exchange_arguments']) && is_array($config['exchange_arguments'])) {
                        $config['exchange_arguments'] = array_merge($config['exchange_arguments'], ['x-delayed-type' => 'direct']);
                    } else {
                        $config['exchange_arguments'] = ['x-delayed-type' => 'direct'];
                    }

                }

                $exchangeArgs = $this->toAmqpTable($config['exchange_arguments'] ?? []);

                // exchange_declare(
                //   string $exchange, string $type, bool $passive = false,
                //   bool $durable = false, bool $auto_delete = false, bool $internal = false,
                //   bool $nowait = false, AMQPTable $arguments = null, int $ticket = null
                // )
                $this->channel->exchange_declare(
                    $exchange,
                    $exchangeType,
                    false,
                    $config['exchange_durable'] ?? true,
                    false,
                    false,
                    false,
                    $exchangeArgs
                );
            }

            // Объявление очереди
            if ($queue) {
                $queueArgs = $this->toAmqpTable($config['queue_arguments'] ?? []);
                $this->channel->queue_declare(
                    $queue,
                    false,
                    $config['queue_durable'] ?? true,
                    false,
                    false,
                    false,
                    $queueArgs
                );
            }

            // привязка как раньше
            if ($exchange && $queue && $routingKey) {
                $this->channel->queue_bind($queue, $exchange, $routingKey);
            }
        } catch (AMQPIOException $e) {
            throw new Exception("Ошибка подключения к RabbitMQ: " . $e->getMessage());
        }
    }

    /**
     * Отправка сообщения в очередь
     *
     * @param mixed $data Данные для отправки
     * @param array $properties Свойства сообщения
     * @param string|null $routingKey Ключ маршрутизации (если нужно переопределить)
     */
    public function publish(mixed $data, array $properties = [], ?string $routingKey = null, ?string $exchange = null): void
    {
        $routingKey = $routingKey ?? $this->routingKey;
        $exchange = $exchange ?? $this->exchange;

        // Check if we need to chunk the message
        if ($this->largeMessageHandler && $this->largeMessageHandler->needsChunking($data)) {
            $this->publishChunked($data, $properties, $routingKey, $exchange);
            return;
        }

        $message = new AMQPMessage(
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            array_merge([
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT
            ], $properties)
        );

        $this->channel->basic_publish($message, $exchange, $routingKey);
    }

    /**
     * Publish a message that exceeds size limits by splitting into chunks.
     *
     * @param array $data The payload to chunk and publish
     * @param array $properties AMQP message properties
     * @param string|null $routingKey Routing key override
     * @param string|null $exchange Exchange override
     */
    private function publishChunked(array $data, array $properties, ?string $routingKey, ?string $exchange): void
    {
        $chunks = $this->largeMessageHandler->splitIntoChunks($data);

        foreach ($chunks as $chunk) {
            $message = new AMQPMessage(
                json_encode($chunk, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                array_merge([
                    'content_type' => 'application/json',
                    'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                    'message_id' => $chunk['meta']['messageId'],
                    'application_headers' => new AMQPTable([
                        'x-chunk-index' => $chunk['meta']['chunkIndex'],
                        'x-total-chunks' => $chunk['meta']['totalChunks'],
                        'x-is-chunked' => true,
                    ]),
                ], $properties)
            );

            $this->channel->basic_publish($message, $exchange, $routingKey);
        }
    }

    public function startConsuming(
        callable $callback,
        ?string $queue = null,
        bool $noAck = false,
        ?string $consumerTag = null
    ): void
    {
        $queue = $queue ?? $this->queue;
        $this->consumerTag = $this->resolveConsumerTag($consumerTag);

        $this->channel->basic_consume(
            $queue,
            $this->consumerTag,
            false,
            $noAck,
            false,
            false,
            $callback
        );
    }

    public function waitOne(float $timeout = 5.0): void
    {
        if ($this->channel->is_consuming()) {
            $this->channel->wait(null, false, $timeout);
        }
    }

    public function stopConsuming(): void
    {
        if ($this->consumerTag && $this->channel->is_open()) {
            $this->channel->basic_cancel($this->consumerTag);
            $this->consumerTag = '';
        }
    }

    /**
     * Прослушивание очереди и обработка сообщений
     *
     * @param callable $callback Функция обработки сообщений
     * @param string|null $queue Имя очереди (если нужно переопределить)
     * @param bool $noAck Отключать подтверждение обработки
     */
    public function consume(callable $callback, ?string $queue = null, bool $noAck = false): void
    {
        $queue = $queue ?? $this->queue;

        $this->channel->basic_consume(
            $queue,
            '', // consumer tag
            false,
            $noAck,
            false,
            false,
            function ($message) use ($callback) {
                try {
                    $body = json_decode($message->body, true);
                    $result = call_user_func($callback, $body, $message);

                    if (!$message->get('no_ack')) {
                        if ($result) {
                            $message->ack();
                        } else {
                            $message->nack();
                        }
                    }
                } catch (Exception $e) {
                    if (!$message->get('no_ack')) {
                        $message->nack();
                    }
                    throw $e;
                }
            }
        );

        while ($this->channel->is_consuming()) {
            $this->channel->wait();
        }
    }

    public function waitIteration(): void
    {
        if ($this->channel->is_consuming()) {
            $this->channel->wait(null, false, 5); // timeout = 5 сек, чтобы можно было прерывать
        }
    }

    /**
     * Закрытие соединения
     * @throws Exception
     */
    public function close(): void
    {
        $this->stopConsuming();

        if ($this->channel->is_open()) {
            $this->channel->close();
        }
        if ($this->connection->isConnected()) {
            $this->connection->close();
        }
    }

    /**
     * Валидация конфигурации подключения
     */
    private function validateConfig(array $config): void
    {
        $required = ['host', 'port', 'user', 'password'];
        foreach ($required as $key) {
            if (!isset($config[$key])) {
                throw new InvalidArgumentException("Не указан обязательный параметр: $key");
            }
        }

        $heartbeat = (int)($config['heartbeat'] ?? 30);
        $readWriteTimeout = (float)($config['read_write_timeout'] ?? 60.0);
        if ($heartbeat > 0 && $readWriteTimeout < ($heartbeat * 2)) {
            throw new InvalidArgumentException('read_write_timeout must be at least heartbeat*2');
        }
    }

    /**
     * Деструктор для автоматического закрытия соединения
     */
    public function __destruct()
    {
        $this->close();
    }

    /**
     * Get the large message handler for chunk reassembly.
     *
     * @return LargeMessageHandlerInterface
     */
    public function getLargeMessageHandler(): LargeMessageHandlerInterface
    {
        return $this->largeMessageHandler;
    }

    /**
     * Check if a message body represents a chunk.
     *
     * @param array $body Decoded message body
     * @return bool True if this is a chunk
     */
    public function isChunkedMessage(array $body): bool
    {
        return isset($body['meta']['isChunked']) && $body['meta']['isChunked'] === true;
    }

    public function getQueue(): string
    {
        return $this->queue;
    }

    public function getExchange(): string
    {
        return $this->exchange;
    }

    public function getRoutingKey(): string
    {
        return $this->routingKey;
    }

    public function getConsumerTag(): string
    {
        return $this->consumerTag;
    }

    private function resolveConsumerTag(?string $consumerTag): string
    {
        $candidate = is_string($consumerTag) ? trim($consumerTag) : '';
        if ($candidate === '') {
            $pid = getmypid() ?: 0;
            $candidate = sprintf('consumer.%d.%s', $pid, $this->generateRandomSuffix(4));
        }

        $candidate = preg_replace('/[^a-zA-Z0-9_.:-]/', '_', $candidate) ?: 'consumer';
        if (strlen($candidate) > 255) {
            $candidate = substr($candidate, 0, 255);
        }

        return $candidate;
    }

    private function generateRandomSuffix(int $bytes = 4): string
    {
        try {
            return bin2hex(random_bytes($bytes));
        } catch (\Throwable) {
            return (string)mt_rand(10000000, 99999999);
        }
    }

    private function toAmqpTable(mixed $value): ?AMQPTable
    {
        if ($value instanceof AMQPTable) {
            return $value;
        }

        if (is_array($value)) {
            return new AMQPTable($value);
        }

        return null;
    }
}
