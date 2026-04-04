<?php

namespace SpsFW\Core\Queue\Outbox;

use Ramsey\Uuid\Uuid;
use SpsFW\Core\Storage\PdoStorage;

class OutboxStorage extends PdoStorage
{
    private const TABLE = 'queue_outbox';

    /**
     * Сохранить сообщение в outbox.
     * Вызывается когда RabbitMQ недоступен.
     */
    public function save(
        array  $payload,
        array  $properties,
        string $routingKey,
        string $exchange,
    ): void {
        $this->execute(
            sprintf(
                "INSERT INTO %s (id, payload, properties, routing_key, exchange)
                 VALUES (?, ?, ?, ?, ?)",
                self::TABLE
            ),
            [
                Uuid::uuid7()->toString(),
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                json_encode($properties, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                $routingKey,
                $exchange,
            ]
        );
    }

    /**
     * Атомарно захватить N сообщений для публикации.
     *
     * Использует SELECT FOR UPDATE SKIP LOCKED (PostgreSQL, MySQL 8+, MariaDB 10.6+)
     * чтобы гарантировать, что конкурентные процессы не получат одни и те же строки.
     *
     * ⚠️ Должен вызываться внутри активной транзакции (beginTransaction → flush → commit/rollback).
     *
     * @return OutboxMessage[]
     */
    public function fetchLocked(int $limit): array
    {
        $stmt = $this->getPdo()->prepare(
            sprintf(
                "SELECT * FROM %s ORDER BY created_at ASC LIMIT ? FOR UPDATE SKIP LOCKED",
                self::TABLE
            )
        );
        $stmt->execute([$limit]);
        return array_map(OutboxMessage::fromRow(...), $stmt->fetchAll());
    }

    public function delete(string $id): void
    {
        $this->execute(
            sprintf("DELETE FROM %s WHERE id = ?", self::TABLE),
            [$id]
        );
    }

    /**
     * Удалить все сообщения из outbox (очистка вручную / для тестов).
     */
    public function deleteAll(): void
    {
        $this->execute(sprintf("DELETE FROM %s", self::TABLE));
    }

    public function hasPending(): bool
    {
        return $this->exists(
            sprintf("SELECT EXISTS(SELECT 1 FROM %s LIMIT 1)", self::TABLE)
        );
    }

    public function countPending(): int
    {
        $row = $this->fetchOne(sprintf("SELECT COUNT(*) AS cnt FROM %s", self::TABLE));
        return (int) ($row['cnt'] ?? 0);
    }
}
