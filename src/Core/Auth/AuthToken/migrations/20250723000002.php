<?php

namespace SpsFW\Core\Auth\AuthToken\migrations;

use Phinx\Migration\AbstractMigration;

class V20250723000002 extends AbstractMigration
{
    public function up(): void
    {
        if ($this->getAdapter()->getAdapterType() === 'pgsql') {
            $this->query("
                CREATE TABLE IF NOT EXISTS users__refresh_tokens (
                    user_id     UUID        NOT NULL,
                    selector    BYTEA       NOT NULL,
                    verifier_hash CHAR(60)  NOT NULL,
                    expires_at  TIMESTAMP   NOT NULL,
                    created_at  TIMESTAMP   NOT NULL DEFAULT NOW(),
                    CONSTRAINT users__refresh_tokens_selector_key UNIQUE (selector)
                )
            ");
            $this->query("CREATE INDEX IF NOT EXISTS idx_refresh_tokens_expires_at ON users__refresh_tokens (expires_at)");
        } else {
            $this->query("
                CREATE TABLE IF NOT EXISTS users__refresh_tokens (
                    user_id       BINARY(16)    NOT NULL COMMENT 'UUID пользователя (users.id)',
                    selector      VARBINARY(8)  NOT NULL UNIQUE,
                    verifier_hash CHAR(60)      NOT NULL,
                    expires_at    DATETIME      NOT NULL,
                    created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_user_id (user_id),
                    INDEX idx_expires_at (expires_at)
                ) COMMENT 'Таблица refresh-токенов пользователей'
            ");
        }
    }

    public function down(): void
    {
        $this->query("DROP TABLE IF EXISTS users__refresh_tokens");
    }
}
