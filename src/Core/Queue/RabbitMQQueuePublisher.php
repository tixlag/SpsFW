<?php

namespace SpsFW\Core\Queue;

use PhpAmqpLib\Wire\AMQPTable;
use SpsFW\Core\Queue\Interfaces\QueuePublisherInterface;
use SpsFW\Core\Queue\Interfaces\JobInterface;
use SpsFW\Core\Queue\Interfaces\PayloadJobInterface;

class RabbitMQQueuePublisher implements QueuePublisherInterface
{
    private RabbitMQClient $client;
    private string $defaultRoutingKey;
    private string $defaultExchange;

    private const UTC = 'UTC';

    public function __construct(RabbitMQClient $client, string $defaultRoutingKey = '', string $defaultExchange = '')
    {
        $this->client = $client;
        $this->defaultRoutingKey = $defaultRoutingKey;
        $this->defaultExchange = $defaultExchange;
    }

    public function publish(JobInterface $job, array $options = []): void
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

        $this->client->publish($payload, $properties, $routingKey, $exchange);
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
