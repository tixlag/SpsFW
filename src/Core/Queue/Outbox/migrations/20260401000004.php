<?php

namespace SpsFW\Core\Queue\Outbox\migrations;

use Phinx\Migration\AbstractMigration;

/**
 * Таблица outbox для хранения сообщений RabbitMQ при недоступности брокера.
 *
 * Стратегия:
 *  - при ошибке публикации в RabbitMQ → INSERT сюда
 *  - при восстановлении → OutboxPublisher::flush() читает строки
 *    через SELECT FOR UPDATE SKIP LOCKED, публикует и удаляет.
 *
 * Совместимость: PostgreSQL 9.5+, MySQL 8.0+, MariaDB 10.6+
 */
class V20260401000004 extends AbstractMigration
{
    public function up(): void
    {
        $adapter = $this->getAdapter()->getAdapterType();

        if ($adapter === 'pgsql') {
            $this->execute("
                CREATE TABLE IF NOT EXISTS queue_outbox (
                    id          UUID                     NOT NULL DEFAULT gen_random_uuid(),
                    payload     JSONB                    NOT NULL,
                    properties  JSONB                    NOT NULL DEFAULT '{}',
                    routing_key VARCHAR(255)             NOT NULL DEFAULT '',
                    exchange    VARCHAR(255)             NOT NULL DEFAULT '',
                    attempts    INTEGER                  NOT NULL DEFAULT 0,
                    created_at  TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),

                    PRIMARY KEY (id)
                )
            ");
            $this->execute(
                'CREATE INDEX IF NOT EXISTS idx_queue_outbox_created_at ON queue_outbox (created_at ASC)'
            );
        } else {
            // MySQL / MariaDB
            $this->execute("
                CREATE TABLE IF NOT EXISTS queue_outbox (
                    id          BINARY(16)   NOT NULL,
                    payload     JSON     NOT NULL,
                    properties  JSON         NOT NULL,
                    routing_key VARCHAR(255) NOT NULL DEFAULT '',
                    exchange    VARCHAR(255) NOT NULL DEFAULT '',
                    attempts    INT          NOT NULL DEFAULT 0,
                    created_at  DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),

                    PRIMARY KEY (id),
                    INDEX idx_queue_outbox_created_at (created_at ASC)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");
        }
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS queue_outbox');
    }
}
