<?php

namespace SpsFW\Core\Queue\Outbox;

use SpsFW\Core\Queue\Interfaces\JobInterface;
use SpsFW\Core\Queue\Interfaces\QueuePublisherInterface;
use SpsFW\Core\Queue\RabbitMQQueuePublisher;

/**
 * Декоратор над RabbitMQQueuePublisher.
 *
 * Если RabbitMQ недоступен при publish() — сохраняет сообщение в БД (outbox).
 * По умолчанию не сливает накопленные сообщения из publish(). Для доставки
 * используется отдельный OutboxRelay; autoFlushBatch оставлен только для
 * явно настроенного legacy-кода.
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
        private readonly int $autoFlushBatch = 0,
    ) {}

    /**
     * Публикует задачу.
     * При недоступности RabbitMQ сохраняет в outbox.
     * Ошибка последующего best-effort flush не превращает уже опубликованное
     * сообщение в новую запись outbox.
     */
    public function publish(JobInterface $job, array $options = []): void
    {
        try {
            $this->publisher->publish($job, $options);
        } catch (\Throwable) {
            $this->saveToOutbox($job, $options);
            return;
        }

        if ($this->autoFlushBatch > 0) {
            try {
                $this->flush($this->autoFlushBatch);
            } catch (\Throwable $exception) {
                // Основное сообщение уже принято publisher'ом. Нельзя сохранять
                // его повторно из-за сбоя доставки старых outbox-записей.
                error_log('Outbox flush failed after successful publish: ' . $exception->getMessage());
            }
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
     * Использует lease-модель OutboxRelay и publisher confirms, чтобы конкурентные
     * вызовы не держали общую транзакцию во время сетевого вызова к RabbitMQ.
     *
     * @param int|null $limit Максимальное количество сообщений за один вызов (null = 100)
     * @return int Количество успешно опубликованных сообщений
     */
    public function flush(?int $limit = null): int
    {
        return (new OutboxRelay($this->storage, $this->publisher))->runBatch($limit ?? 100);
    }

    // -----------------------------------------------------------------------

    private function saveToOutbox(JobInterface $job, array $options): void
    {
        $deduplicationKey = isset($options['deduplicationKey'])
            ? trim((string) $options['deduplicationKey'])
            : null;
        unset($options['deduplicationKey']);

        $prepared = $this->publisher->prepare($job, $options);
        $this->storage->savePrepared(
            $prepared,
            $deduplicationKey !== '' ? $deduplicationKey : $prepared->messageId,
        );
    }
}
