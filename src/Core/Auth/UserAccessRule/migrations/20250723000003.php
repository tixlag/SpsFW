<?php

namespace SpsFW\Core\Auth\UserAccessRule\migrations;

use Phinx\Migration\AbstractMigration;

class V20250723000003 extends AbstractMigration
{
    public function up(): void
    {
        if ($this->getAdapter()->getAdapterType() === 'pgsql') {
            $this->query("
                CREATE TABLE IF NOT EXISTS users__access_rules (
                    access_rule_id SMALLINT    NOT NULL,
                    user_uuid      UUID        NOT NULL,
                    value          JSONB,
                    CONSTRAINT users__access_rules_unique UNIQUE (user_uuid, access_rule_id)
                )
            ");
            $this->query("CREATE INDEX IF NOT EXISTS idx_access_rules_user ON users__access_rules (user_uuid)");
        } else {
            $this->query("
                CREATE TABLE IF NOT EXISTS users__access_rules (
                    access_rule_id SMALLINT UNSIGNED COMMENT 'Идентификатор правила доступа (access_rules.id)',
                    user_uuid      BINARY(16)       NOT NULL COMMENT 'Идентификатор пользователя (users.uuid)',
                    value          JSON             COMMENT 'Значение правила',
                    INDEX user_idx (user_uuid),
                    UNIQUE (user_uuid, access_rule_id)
                ) COMMENT 'Таблица правил доступа пользователей'
            ");
        }
    }

    public function down(): void
    {
        $this->query("DROP TABLE IF EXISTS users__access_rules");
    }
}
