<?php

namespace SpsFW\Core\Queue\Outbox;

use PhpAmqpLib\Wire\AMQPTable;
use SpsFW\Core\Queue\PreparedQueueMessage;
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
                $this->databaseId($this->newUuid()),
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                json_encode($properties, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                $routingKey,
                $exchange,
            ]
        );
    }

    public function savePrepared(PreparedQueueMessage $message, ?string $deduplicationKey = null): void
    {
        $properties = [];
        foreach ($message->properties as $key => $value) {
            $properties[$key] = $value instanceof AMQPTable ? $value->getNativeData() : $value;
        }

        $driver = $this->driver();
        $deduplicationClause = $driver === 'pgsql'
            ? 'ON CONFLICT (deduplication_key) DO NOTHING'
            : 'ON DUPLICATE KEY UPDATE id = id';
        $date = $this->formatDate($message->availableAt);

        $this->execute(
            sprintf(
                "INSERT INTO %s (
                    id, payload, properties, routing_key, exchange,
                    message_id, available_at, next_attempt_at, deduplication_key
                 )
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                 %s",
                self::TABLE,
                $deduplicationClause,
            ),
            [
                $this->databaseId($this->newUuid()),
                json_encode($message->payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                json_encode($properties, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                $message->routingKey,
                $message->exchange,
                $message->messageId,
                $date,
                $date,
                $deduplicationKey,
            ],
        );
    }

    /**
     * @return list<OutboxMessage>
     */
    public function claimDue(int $limit, int $leaseSeconds): array
    {
        return $this->claimDueFiltered($limit, $leaseSeconds);
    }

    /**
     * @return list<OutboxMessage>
     */
    public function claimDueFiltered(
        int $limit,
        int $leaseSeconds,
        ?string $exchange = null,
        ?string $routingKey = null,
        int $maxAttempts = 10,
    ): array
    {
        $limit = max(1, $limit);
        $maxAttempts = max(1, $maxAttempts);
        $claimToken = $this->newUuid();
        $claimedUntil = $this->formatDate(new \DateTimeImmutable(sprintf('+%d seconds', max(1, $leaseSeconds))));

        $conditions = [
            'available_at <= CURRENT_TIMESTAMP',
            'next_attempt_at <= CURRENT_TIMESTAMP',
            '(claimed_until IS NULL OR claimed_until < CURRENT_TIMESTAMP)',
            'quarantined_at IS NULL',
            'attempts < ?',
        ];
        $parameters = [$maxAttempts];

        if ($exchange !== null) {
            $conditions[] = 'exchange = ?';
            $parameters[] = $exchange;
        }
        if ($routingKey !== null) {
            $conditions[] = 'routing_key = ?';
            $parameters[] = $routingKey;
        }

        $this->beginTransaction();
        try {
            $statement = $this->getPdo()->prepare(sprintf(
                "SELECT * FROM %s
                 WHERE %s
                 ORDER BY available_at ASC, created_at ASC
                 LIMIT ?
                 FOR UPDATE SKIP LOCKED",
                self::TABLE,
                implode("\n                   AND ", $conditions),
            ));
            $parameters[] = $limit;
            foreach ($parameters as $index => $parameter) {
                $statement->bindValue(
                    $index + 1,
                    $parameter,
                    is_int($parameter) ? \PDO::PARAM_INT : \PDO::PARAM_STR,
                );
            }
            $statement->execute();
            $rows = $statement->fetchAll();

            foreach ($rows as $row) {
                $this->execute(
                    sprintf(
                        "UPDATE %s
                         SET claim_token = ?, claimed_until = ?
                         WHERE id = ?",
                        self::TABLE,
                    ),
                    [$claimToken, $claimedUntil, $row['id']],
                );
            }

            $this->commitTransaction();
        } catch (\Throwable $exception) {
            $this->rollbackTransaction();
            throw $exception;
        }

        return array_map(function (array $row) use ($claimToken): OutboxMessage {
            $row['id'] = $this->canonicalId($row['id']);
            $row['claim_token'] = $claimToken;
            return OutboxMessage::fromRow($row);
        }, $rows);
    }

    public function markPublished(string $id, string $claimToken): void
    {
        $this->execute(
            sprintf("DELETE FROM %s WHERE id = ? AND claim_token = ?", self::TABLE),
            [$this->databaseId($id), $claimToken],
        );
    }

    public function releaseFailed(
        string $id,
        string $claimToken,
        string $error,
        int $retryDelaySeconds,
    ): void {
        $this->releaseFailedWithLimit($id, $claimToken, $error, $retryDelaySeconds, 10);
    }

    public function releaseFailedWithLimit(
        string $id,
        string $claimToken,
        string $error,
        int $retryDelaySeconds,
        int $maxAttempts,
    ): void {
        $maxAttempts = max(1, $maxAttempts);
        $error = mb_substr($error, 0, 2000);
        $quarantineAt = $this->formatDate(new \DateTimeImmutable('now'));
        $nextAttemptAt = $this->formatDate(new \DateTimeImmutable(sprintf('+%d seconds', max(1, $retryDelaySeconds))));
        $this->execute(
            sprintf(
                "UPDATE %s
                 SET quarantined_at = CASE WHEN attempts + 1 >= ? THEN ? ELSE quarantined_at END,
                     quarantine_reason = CASE WHEN attempts + 1 >= ? THEN ? ELSE quarantine_reason END,
                     attempts = attempts + 1,
                     last_error = ?,
                     next_attempt_at = ?,
                     claim_token = NULL,
                     claimed_until = NULL
                 WHERE id = ? AND claim_token = ?",
                self::TABLE,
            ),
            [
                $maxAttempts,
                $quarantineAt,
                $maxAttempts,
                $error,
                $error,
                $nextAttemptAt,
                $this->databaseId($id),
                $claimToken,
            ],
        );
    }

    public function nextAvailableAt(): ?\DateTimeImmutable
    {
        return $this->nextAvailableAtFiltered();
    }

    public function nextAvailableAtFiltered(
        ?string $exchange = null,
        ?string $routingKey = null,
        int $maxAttempts = 10,
    ): ?\DateTimeImmutable
    {
        $maxAttempts = max(1, $maxAttempts);
        $conditions = [
            'quarantined_at IS NULL',
            '(claim_token IS NULL OR claimed_until < CURRENT_TIMESTAMP)',
            'attempts < ?',
        ];
        $parameters = [$maxAttempts];

        if ($exchange !== null) {
            $conditions[] = 'exchange = ?';
            $parameters[] = $exchange;
        }
        if ($routingKey !== null) {
            $conditions[] = 'routing_key = ?';
            $parameters[] = $routingKey;
        }

        $row = $this->fetchOne(sprintf(
            "SELECT CASE
                        WHEN next_attempt_at > available_at THEN next_attempt_at
                        ELSE available_at
                    END AS next_at
             FROM %s
             WHERE %s
             ORDER BY next_at ASC
             LIMIT 1",
            self::TABLE,
            implode("\n               AND ", $conditions),
        ), $parameters);

        return isset($row['next_at']) ? new \DateTimeImmutable((string) $row['next_at']) : null;
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
        return array_map(function (array $row): OutboxMessage {
            $row['id'] = $this->canonicalId($row['id']);
            return OutboxMessage::fromRow($row);
        }, $stmt->fetchAll());
    }

    public function delete(string $id): void
    {
        $this->execute(
            sprintf("DELETE FROM %s WHERE id = ?", self::TABLE),
            [$this->databaseId($id)]
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

    private function driver(): string
    {
        return (string) $this->getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME);
    }

    private function formatDate(\DateTimeInterface $date): string
    {
        $utc = \DateTimeImmutable::createFromInterface($date)->setTimezone(new \DateTimeZone('UTC'));
        return $this->driver() === 'pgsql'
            ? $utc->format('Y-m-d H:i:s.uP')
            : $utc->format('Y-m-d H:i:s.u');
    }

    private function databaseId(string $id): string
    {
        return $this->driver() === 'pgsql' ? $id : $this->uuidToBytes($id);
    }

    private function canonicalId(string $id): string
    {
        if ($this->driver() === 'pgsql') {
            return $id;
        }

        return strlen($id) === 16 ? $this->bytesToUuid($id) : $id;
    }

    private function newUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return $this->bytesToUuid($bytes);
    }

    private function uuidToBytes(string $uuid): string
    {
        $hex = str_replace('-', '', $uuid);
        $bytes = hex2bin($hex);
        if ($bytes === false || strlen($bytes) !== 16) {
            throw new \InvalidArgumentException('Invalid UUID.');
        }
        return $bytes;
    }

    private function bytesToUuid(string $bytes): string
    {
        $hex = bin2hex($bytes);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}
