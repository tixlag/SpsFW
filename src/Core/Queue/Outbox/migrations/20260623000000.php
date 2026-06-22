<?php

declare(strict_types=1);

namespace SpsFW\Core\Queue\Outbox\migrations;

use Phinx\Migration\AbstractMigration;

final class V20260623000000 extends AbstractMigration
{
    public function up(): void
    {
        if ($this->getAdapter()->getAdapterType() === 'pgsql') {
            $this->query(<<<'SQL'
ALTER TABLE queue_outbox
    ADD COLUMN message_id VARCHAR(255) NOT NULL DEFAULT '',
    ADD COLUMN available_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),
    ADD COLUMN next_attempt_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),
    ADD COLUMN deduplication_key VARCHAR(255) NULL,
    ADD COLUMN claim_token VARCHAR(36) NULL,
    ADD COLUMN claimed_until TIMESTAMP WITH TIME ZONE NULL,
    ADD COLUMN last_error TEXT NULL;

CREATE UNIQUE INDEX queue_outbox_deduplication_uidx
    ON queue_outbox (deduplication_key);
CREATE INDEX queue_outbox_due_idx
    ON queue_outbox (available_at, next_attempt_at, claimed_until);
SQL);
            return;
        }

        $this->query(<<<'SQL'
ALTER TABLE queue_outbox
    ADD COLUMN message_id VARCHAR(255) NOT NULL DEFAULT '',
    ADD COLUMN available_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    ADD COLUMN next_attempt_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    ADD COLUMN deduplication_key VARCHAR(255) NULL,
    ADD COLUMN claim_token VARCHAR(36) NULL,
    ADD COLUMN claimed_until DATETIME(6) NULL,
    ADD COLUMN last_error TEXT NULL,
    ADD UNIQUE INDEX queue_outbox_deduplication_uidx (deduplication_key),
    ADD INDEX queue_outbox_due_idx (available_at, next_attempt_at, claimed_until);
SQL);
    }

    public function down(): void
    {
        if ($this->getAdapter()->getAdapterType() === 'pgsql') {
            $this->query(<<<'SQL'
DROP INDEX IF EXISTS queue_outbox_due_idx;
DROP INDEX IF EXISTS queue_outbox_deduplication_uidx;
ALTER TABLE queue_outbox
    DROP COLUMN IF EXISTS last_error,
    DROP COLUMN IF EXISTS claimed_until,
    DROP COLUMN IF EXISTS claim_token,
    DROP COLUMN IF EXISTS deduplication_key,
    DROP COLUMN IF EXISTS next_attempt_at,
    DROP COLUMN IF EXISTS available_at,
    DROP COLUMN IF EXISTS message_id;
SQL);
            return;
        }

        $this->query(<<<'SQL'
ALTER TABLE queue_outbox
    DROP INDEX queue_outbox_due_idx,
    DROP INDEX queue_outbox_deduplication_uidx,
    DROP COLUMN last_error,
    DROP COLUMN claimed_until,
    DROP COLUMN claim_token,
    DROP COLUMN deduplication_key,
    DROP COLUMN next_attempt_at,
    DROP COLUMN available_at,
    DROP COLUMN message_id;
SQL);
    }
}
