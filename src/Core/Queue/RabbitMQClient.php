<?php

namespace SpsFW\Core\Queue;

use Exception;
use InvalidArgumentException;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use PhpAmqpLib\Exception\AMQPIOException;


class RabbitMQClient
{
    private AMQPStreamConnection $connection;
    private \PhpAmqpLib\Channel\AbstractChannel|\PhpAmqpLib\Channel\AMQPChannel $channel;
    private string $exchange;
    private string $queue;
    private string $routingKey;
    private string $consumerTag = '';

    /**
     * Инициализация подключения к RabbitMQ
     *
     * @param string $exchange Название обменника
     * @param string $exchangeType Тип обменника (direct, fanout, topic, headers)
     * @param string $queue Название очереди
     * @param string $routingKey Ключ маршрутизации
     * @param array|null $config Конфигурация подключения
     * @throws Exception
     */
    public function __construct(
        string $exchange = '',
        string $exchangeType = AMQPExchangeType::DIRECT,
        string $queue = '',
        string $routingKey = '',
        ?array  $config = null
    )
    {
        //$this->validateConfig($config);
        $this->exchange = $exchange;
        $this->queue = $queue;
        $this->routingKey = $routingKey;

        try {
            // Установка соединения
            $this->connection = new AMQPStreamConnection(
                $config['host'] ,
                $config['port'] ,
                $config['user'] ,
                $config['password'] ,
                $config['vhost'] ?? '/',
                $config['insist'] ?? false,
                $config['login_method'] ?? 'AMQPLAIN',
                $config['login_response'] ?? null,
                $config['locale'] ?? 'en_US',
                $config['connection_timeout'] ?? 3.0,
                $config['read_write_timeout'] ?? 3.0,
                $config['context'] ?? null,
                $config['keepalive'] ?? false,
                $config['heartbeat'] ?? 60 // Увеличиваем heartbeat до 60 секунд
            );

            $this->channel = $this->connection->channel();
            $this->channel->basic_qos((int)null, 1, null); // Prefetch = 1 для равномерной нагрузки

            // Объявление обменника
            if ($exchange) {
            $this->channel->exchange_declare($exchange, $exchangeType, false, $config['exchange_durable'] ?? true);
            }

            // Объявление очереди
            if ($queue) {
            $this->channel->queue_declare($queue, false, $config['queue_durable'] ?? true, false, false, false, $config['queue_arguments'] ?? []);
            }

            // Привязка очереди к обменнику
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
    public function publish(mixed $data, array $properties = [], ?string $routingKey = null): void
    {
        $routingKey = $routingKey ?? $this->routingKey;

        $message = new AMQPMessage(
            json_encode($data, JSON_UNESCAPED_UNICODE),
            array_merge([
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT
            ], $properties)
        );

        $this->channel->basic_publish($message, $this->exchange, $routingKey);
    }

        public function startConsuming(callable $callback, ?string $queue = null, bool $noAck = false): void
    {
        $queue = $queue ?? $this->queue;
        $this->consumerTag = 'consumer_' . getmypid() . '_' . uniqid();

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
    }

    /**
     * Деструктор для автоматического закрытия соединения
     */
    public function __destruct()
    {
        $this->close();
    }
}