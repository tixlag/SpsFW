<?php

namespace SpsFW\Core\Db\migrations;

use Phinx\Migration\AbstractMigration;

/**
 * Создаёт хелпер-функции UUID_TO_BIN() и BIN_TO_UUID() для MariaDB и MySQL < 8.0.
 *
 * На MySQL 8.0+ эти функции встроены. Создаём их как stored functions с
 * CREATE FUNCTION IF NOT EXISTS — на MySQL 8.0 built-in имеет приоритет при
 * неквалифицированном вызове, stored function просто не используется.
 * На MariaDB без нативных функций — stored function применяется.
 *
 * Совместимость: MySQL 8.0+, MariaDB 10.2+
 */
class V20250101000001 extends AbstractMigration
{
    public function up(): void
    {
        if ($this->getAdapter()->getAdapterType() === 'pgsql') {
            return; // PostgreSQL имеет нативный UUID, функции не нужны
        }

        $this->execute("
            CREATE FUNCTION IF NOT EXISTS UUID_TO_BIN(uuid VARCHAR(36))
                RETURNS BINARY(16) DETERMINISTIC NO SQL
                RETURN UNHEX(REPLACE(uuid, '-', ''))
        ");

        $this->execute("
            CREATE FUNCTION IF NOT EXISTS BIN_TO_UUID(b BINARY(16))
                RETURNS VARCHAR(36) DETERMINISTIC NO SQL
                RETURN LOWER(CONCAT(
                    HEX(SUBSTR(b,  1, 4)), '-',
                    HEX(SUBSTR(b,  5, 2)), '-',
                    HEX(SUBSTR(b,  7, 2)), '-',
                    HEX(SUBSTR(b,  9, 2)), '-',
                    HEX(SUBSTR(b, 11))
                ))
        ");
    }

    public function down(): void
    {
        if ($this->getAdapter()->getAdapterType() === 'pgsql') {
            return;
        }

        $this->execute('DROP FUNCTION IF EXISTS UUID_TO_BIN');
        $this->execute('DROP FUNCTION IF EXISTS BIN_TO_UUID');
    }
}
