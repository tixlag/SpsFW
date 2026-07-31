<?php

declare(strict_types=1);

namespace SpsFW\Core\Queue\Outbox\migrations;

use Phinx\Migration\AbstractMigration;

final class V20260731000000 extends AbstractMigration
{
    public function up(): void
    {
        if ($this->getAdapter()->getAdapterType() === 'pgsql') {
            $this->execute(<<<'SQL'
ALTER TABLE queue_outbox
    ADD COLUMN quarantined_at TIMESTAMP WITH TIME ZONE NULL,
    ADD COLUMN quarantine_reason TEXT NULL
SQL);
            $this->execute(
                'CREATE INDEX queue_outbox_active_due_idx ON queue_outbox (quarantined_at, available_at, next_attempt_at, claimed_until)'
            );
            return;
        }

        $this->execute(<<<'SQL'
ALTER TABLE queue_outbox
    ADD COLUMN quarantined_at DATETIME(6) NULL,
    ADD COLUMN quarantine_reason TEXT NULL,
    ADD INDEX queue_outbox_active_due_idx (quarantined_at, available_at, next_attempt_at, claimed_until)
SQL);
    }

    public function down(): void
    {
        if ($this->getAdapter()->getAdapterType() === 'pgsql') {
            $this->execute('DROP INDEX IF EXISTS queue_outbox_active_due_idx');
            $this->execute(<<<'SQL'
ALTER TABLE queue_outbox
    DROP COLUMN IF EXISTS quarantine_reason,
    DROP COLUMN IF EXISTS quarantined_at
SQL);
            return;
        }

        $this->execute(<<<'SQL'
ALTER TABLE queue_outbox
    DROP INDEX queue_outbox_active_due_idx,
    DROP COLUMN quarantine_reason,
    DROP COLUMN quarantined_at
SQL);
    }
}
