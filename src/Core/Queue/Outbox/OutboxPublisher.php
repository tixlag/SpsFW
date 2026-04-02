<?php

namespace SpsFW\Core\Queue\Outbox;

use PhpAmqpLib\Wire\AMQPTable;
use SpsFW\Core\Queue\Interfaces\JobInterface;
use SpsFW\Core\Queue\Interfaces\QueuePublisherInterface;
use SpsFW\Core\Queue\RabbitMQQueuePublisher;

/**
 * Декоратор над RabbitMQQueuePublisher.
 *
 * Если RabbitMQ недоступен при publish() — сохраняет сообщение в БД (outbox).
 * При следующем успешном publish() автоматически пробует слить накопленные сообщения
 * (до autoFlushBatch штук).
 *
 * Для ручного слива вызывайте flush() из cron/воркера.
 *
 * Использование:
 *   $publisher = new OutboxPublisher($rabbitmqPublisher, $outboxStorage);
 *   $publisher->publish($job, ['exchange' => '...', 'routingKey' => '...']);
 *
 *   // В cron:
 *   $flushed = $publisher->flush(100);
 */
class OutboxPublisher implements QueuePublisherInterface
{
    public function __construct(
        private readonly RabbitMQQueuePublisher $publisher,
        private readonly OutboxStorage          $storage,
        /** Сколько outbox-сообщений дренировать при каждом успешном publish(). 0 = отключить автодрейн. */
        private readonly int $autoFlushBatch = 10,
    ) {}

    /**
     * Публикует задачу.
     * При недоступности RabbitMQ сохраняет в outbox.
     * При успехе — автоматически пробует слить накопленный outbox.
     */
    public function publish(JobInterface $job, array $options = []): void
    {
        try {
            $this->publisher->publish($job, $options);
            // RabbitMQ доступен — дренируем outbox
            if ($this->autoFlushBatch > 0) {
                $this->flush($this->autoFlushBatch);
            }
        } catch (\Throwable) {
            $this->saveToOutbox($job, $options);
        }
    }

    /**
     * Публикует задачу с отложенной доставкой.
     * При недоступности RabbitMQ сохраняет в outbox (без задержки при последующей доставке).
     */
    public function publishAt(JobInterface $job, \DateTimeInterface $when, array $options = []): void
    {
        $options['executeAt'] = $when;
        $this->publish($job, $options);
    }

    /**
     * Слить накопленные outbox-сообщения в RabbitMQ.
     *
     * Использует SELECT FOR UPDATE SKIP LOCKED, чтобы конкурентные вызовы
     * (например, из нескольких воркеров) не публиковали одни и те же сообщения дважды.
     *
     * @param int|null $limit Максимальное количество сообщений за один вызов (null = 100)
     * @return int Количество успешно опубликованных сообщений
     */
    public function flush(?int $limit = null): int
    {
        if (!$this->storage->hasPending()) {
            return 0;
        }

        $client = $this->publisher->getClient();
        $flushed = 0;

        $this->storage->beginTransaction();
        try {
            $messages = $this->storage->fetchLocked($limit ?? 100);

            foreach ($messages as $message) {
                try {
                    $client->publish(
                        data:       $message->payload,
                        properties: $this->restoreProperties($message->properties),
                        routingKey: $message->routingKey ?: null,
                        exchange:   $message->exchange ?: null,
                    );
                    $this->storage->delete($message->id);
                    $flushed++;
                } catch (\Throwable) {
                    // RabbitMQ снова недоступен — прекращаем попытки.
                    // Успешно опубликованные и удалённые сообщения зафиксируются в commit().
                    break;
                }
            }

            $this->storage->commitTransaction();
        } catch (\Throwable $e) {
            $this->storage->rollbackTransaction();
            throw $e;
        }

        return $flushed;
    }

    // -----------------------------------------------------------------------

    private function saveToOutbox(JobInterface $job, array $options): void
    {
        $payload    = $this->publisher->buildPayload($job, $options);
        $properties = $this->serializeProperties($options['properties'] ?? []);
        $routingKey = $options['routingKey'] ?? '';
        $exchange   = $options['exchange']   ?? '';

        $this->storage->save($payload, $properties, $routingKey, $exchange);
    }

    /**
     * Конвертировать AMQPTable → plain array перед сохранением в JSON.
     */
    private function serializeProperties(array $properties): array
    {
        $result = [];
        foreach ($properties as $key => $value) {
            $result[$key] = $value instanceof AMQPTable ? $value->getNativeData() : $value;
        }
        return $result;
    }

    /**
     * При публикации из outbox восстановить application_headers как AMQPTable.
     */
    private function restoreProperties(array $properties): array
    {
        if (isset($properties['application_headers']) && is_array($properties['application_headers'])) {
            $properties['application_headers'] = new AMQPTable($properties['application_headers']);
        }
        return $properties;
    }
}
