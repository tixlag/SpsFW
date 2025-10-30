<?php

namespace SpsFW\Core\Queue;

use PhpAmqpLib\Wire\AMQPTable;
use PhpAmqpLib\Message\AMQPMessage;

use SpsFW\Core\Queue\Interfaces\QueuePublisherInterface;
use SpsFW\Core\Queue\Interfaces\JobInterface;

class RabbitMQQueuePublisher implements QueuePublisherInterface
{
    private RabbitMQClient $client;
    private string $defaultRoutingKey;
    private string $defaultExchange;

    public function __construct(RabbitMQClient $client, string $defaultRoutingKey = '', string $defaultExchange = '')
    {
        $this->client = $client;
        $this->defaultRoutingKey = $defaultRoutingKey;
        $this->defaultExchange = $defaultExchange;
    }

    public function publish(JobInterface $job, array $options = []): void
    {
        $payload = [
            'jobName' => $job->getName(),
            'payload' => $job->serialize(),
            // добавим мета-информацию в payload для логов/страховки:
            'meta' => [
                'publishedAt' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTime::ATOM),
            ]
        ];

        // если пользователь передал executeAt (DateTimeInterface) — преобразуем в строку
        if (!empty($options['executeAt']) && $options['executeAt'] instanceof \DateTimeInterface) {
            $payload['meta']['executeAt'] = $options['executeAt']->format(\DateTime::ATOM);
        }

        // properties можно переопределить извне
        $properties = $options['properties'] ?? [];

        $routingKey = $options['routingKey'] ?? $this->defaultRoutingKey;
        $exchange = $options['exchange'] ?? $this->defaultExchange;

        // если указан delayMs или executeAt -> выставим x-delay header (плагин)
        $delayMs = null;
        if (isset($options['delayMs'])) {
            $delayMs = (int)$options['delayMs'];
        } elseif (!empty($options['executeAt']) && $options['executeAt'] instanceof \DateTimeInterface) {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $delayMs = max(0, (int)(($options['executeAt']->getTimestamp() - $now->getTimestamp()) * 1000));
        }

        if ($delayMs !== null && $delayMs > 0) {
            // Добавляем заголовок x-delay
            $headers = $properties['application_headers'] ?? [];
            if ($headers instanceof AMQPTable) {
                $headers->set('x-delay', $delayMs);
                $properties['application_headers'] = $headers;
            } else {
                // если это массив — создадим AMQPTable
                $properties['application_headers'] = new AMQPTable(array_merge(is_array($headers) ? $headers : [], ['x-delay' => $delayMs]));
            }
        }

        // Вызов клиента — ваш существующий client->publish ожидает $data, $properties, $routingKey
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
}