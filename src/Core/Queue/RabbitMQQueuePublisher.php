<?php

namespace SpsFW\Core\Queue;

use PhpAmqpLib\Wire\AMQPTable;
use SpsFW\Core\Queue\Interfaces\QueuePublisherInterface;
use SpsFW\Core\Queue\Interfaces\JobInterface;
use SpsFW\Core\Queue\Interfaces\PayloadJobInterface;

class RabbitMQQueuePublisher implements QueuePublisherInterface, PreparedMessageTransportInterface
{
    private ?RabbitMQClient $client;
    private string $defaultRoutingKey;
    private string $defaultExchange;

    private const UTC = 'UTC';

    public function __construct(?RabbitMQClient $client, string $defaultRoutingKey = '', string $defaultExchange = '')
    {
        $this->client = $client;
        $this->defaultRoutingKey = $defaultRoutingKey;
        $this->defaultExchange = $defaultExchange;
    }

    public function publish(JobInterface $job, array $options = []): void
    {
        $this->publishPrepared($this->prepare($job, $options));
    }

    /**
     * Публикует уже готовое тело JSON-сообщения без обертки фреймворка.
     *
     * Обычный publish(JobInterface) нужен для PHP-воркеров SpsFW: он добавляет
     * jobName/payload/meta. Для внешних потребителей, например Go-сервисов,
     * такая обертка ломает контракт сообщения, поэтому здесь тело сообщения
     * сохраняется и отправляется ровно в том виде, в котором его передал клиентский код.
     */
    public function publishPayload(array $payload, array $options = []): void
    {
        $this->publishPrepared($this->preparePayload($payload, $options));
    }

    public function prepare(JobInterface $job, array $options = []): PreparedQueueMessage
    {
        [$payload, $properties, $routingKey, $exchange] = $this->buildPublishArgs($job, $options);
        $availableAt = isset($options['executeAt']) && $options['executeAt'] instanceof \DateTimeInterface
            ? \DateTimeImmutable::createFromInterface($options['executeAt'])
            : new \DateTimeImmutable('now', new \DateTimeZone(self::UTC));

        return new PreparedQueueMessage(
            payload: $payload,
            properties: $properties,
            routingKey: $routingKey,
            exchange: $exchange,
            messageId: (string) $payload['meta']['messageId'],
            availableAt: $availableAt,
        );
    }

    /**
     * Подготавливает готовое тело сообщения для прямой отправки или сохранения в outbox.
     *
     * prepare(JobInterface) здесь не подходит: он добавляет служебную обертку
     * SpsFW и x-delay для RabbitMQ-плагина. Этот метод оставляет payload без
     * изменений, а время доставки хранит в PreparedQueueMessage::availableAt,
     * чтобы расписанием управлял outbox.
     */
    public function preparePayload(array $payload, array $options = []): PreparedQueueMessage
    {
        $messageId = (string) ($options['messageId'] ?? bin2hex(random_bytes(16)));
        $availableAt = isset($options['executeAt']) && $options['executeAt'] instanceof \DateTimeInterface
            ? \DateTimeImmutable::createFromInterface($options['executeAt'])
            : new \DateTimeImmutable('now', new \DateTimeZone(self::UTC));

        $properties = $options['properties'] ?? [];
        if (!isset($properties['message_id'])) {
            $properties['message_id'] = $messageId;
        }

        return new PreparedQueueMessage(
            payload: $payload,
            properties: $properties,
            routingKey: $options['routingKey'] ?? $this->defaultRoutingKey,
            exchange: $options['exchange'] ?? $this->defaultExchange,
            messageId: $messageId,
            availableAt: $availableAt,
        );
    }

    public function publishPrepared(PreparedQueueMessage $message, bool $reliable = false): void
    {
        if ($this->client === null) {
            throw new \LogicException('This publisher can prepare messages only; no RabbitMQ client is attached.');
        }

        if ($reliable) {
            $this->client->publishReliable(
                $message->payload,
                $message->properties,
                $message->routingKey,
                $message->exchange,
            );
            return;
        }

        $this->client->publish(
            $message->payload,
            $message->properties,
            $message->routingKey,
            $message->exchange,
        );
    }

    /**
     * @deprecated Use prepare() when routing metadata must be persisted.
     */
    public function buildPayload(JobInterface $job, array $options = []): array
    {
        return $this->prepare($job, $options)->payload;
    }

    public function getClient(): RabbitMQClient
    {
        return $this->client ?? throw new \LogicException('No RabbitMQ client is attached.');
    }

    private function buildPublishArgs(JobInterface $job, array $options): array
    {
        $isPayloadJob = $job instanceof PayloadJobInterface;
        $messageId = $options['messageId'] ?? bin2hex(random_bytes(16));
        $attempt = isset($options['attempt']) ? max(0, (int)$options['attempt']) : 0;

        $payload = [
            'jobName' => $job->getName(),
            'payload' => $isPayloadJob ? $job->toPayload() : $job->serialize(),
            'meta' => [
                'publishedAt' => (new \DateTimeImmutable('now', new \DateTimeZone(self::UTC)))->format(\DateTime::ATOM),
                'schemaVersion' => $isPayloadJob ? 2 : 1,
                'messageId' => $messageId,
                'attempt' => $attempt,
            ]
        ];

        // если пользователь передал executeAt (DateTimeInterface) — преобразуем в строку
        if (!empty($options['executeAt']) && $options['executeAt'] instanceof \DateTimeInterface) {
            $payload['meta']['executeAt'] = $options['executeAt']->format(\DateTime::ATOM);
        }

        $properties = $options['properties'] ?? [];
        if (!isset($properties['message_id'])) {
            $properties['message_id'] = $messageId;
        }

        $properties = $this->mergeApplicationHeaders($properties, [
            'x-attempt' => $attempt,
            'x-schema-version' => $payload['meta']['schemaVersion'],
        ]);

        $routingKey = $options['routingKey'] ?? $this->defaultRoutingKey;
        $exchange = $options['exchange'] ?? $this->defaultExchange;

        // если указан delayMs или executeAt -> выставим x-delay header (плагин)
        $delayMs = null;
        if (isset($options['delayMs'])) {
            $delayMs = (int)$options['delayMs'];
        } elseif (!empty($options['executeAt']) && $options['executeAt'] instanceof \DateTimeInterface) {
            $now = new \DateTimeImmutable('now', new \DateTimeZone(self::UTC));
            $delayMs = max(0, (int)(($options['executeAt']->getTimestamp() - $now->getTimestamp()) * 1000));
        }

        if ($delayMs !== null && $delayMs > 0) {
            $properties = $this->mergeApplicationHeaders($properties, [
                'x-delay' => $delayMs,
            ]);
        }

        return [$payload, $properties, $routingKey, $exchange];
    }

    /**
     * Удобный метод - публикует job чтобы он был доставлен именно в момент $when.
     * @param JobInterface $job
     * @param \DateTimeInterface $when
     * @param array $options Доп. параметры: routingKey, exchange, properties
     */
    public function publishAt(JobInterface $job, \DateTimeInterface $when, array $options = []): void
    {
        $options['executeAt'] = $when;
        $this->publish($job, $options);
    }

    /**
     * Публикует готовое тело сообщения в заданное время.
     *
     * Это аналог publishAt(JobInterface) для внешних потребителей, которые не
     * понимают обертку SpsFW jobName/payload/meta.
     */
    public function publishPayloadAt(array $payload, \DateTimeInterface $when, array $options = []): void
    {
        $options['executeAt'] = $when;
        $this->publishPayload($payload, $options);
    }

    private function mergeApplicationHeaders(array $properties, array $headersToMerge): array
    {
        $currentHeaders = $properties['application_headers'] ?? [];
        if ($currentHeaders instanceof AMQPTable) {
            $currentHeaders = $currentHeaders->getNativeData();
        }

        if (!is_array($currentHeaders)) {
            $currentHeaders = [];
        }

        $properties['application_headers'] = new AMQPTable(array_merge($currentHeaders, $headersToMerge));

        return $properties;
    }
}
