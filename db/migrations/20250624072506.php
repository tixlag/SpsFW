<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class V20250624072506 extends AbstractMigration
{
    /**
     * Change Method.
     *
     * Write your reversible migrations using this method.
     *
     * More information on writing migrations is available here:
     * https://book.cakephp.org/phinx/0/en/migrations.html#the-change-method
     *
     * Remember to call "create()" or "update()" and NOT "save()" when working
     * with the Table class.
     */
    public function up(): void
    {
        $this->query(/** @lang MariaDB */
        "
CREATE FUNCTION BIN_TO_UUID(b BINARY(16))
            RETURNS CHAR(36)
            BEGIN
               DECLARE hexStr CHAR(32);
               SET hexStr = HEX(b);
               RETURN LOWER(CONCAT(
                    SUBSTR(hexStr, 1, 8), '-',
                    SUBSTR(hexStr, 9, 4), '-',
                    SUBSTR(hexStr, 13, 4), '-',
                    SUBSTR(hexStr, 17, 4), '-',
                    SUBSTR(hexStr, 21)
                ));
            END;
            CREATE FUNCTION UUID_TO_BIN(_uuid CHAR(36))
                RETURNS BINARY(16)
                LANGUAGE SQL  DETERMINISTIC  CONTAINS SQL  SQL SECURITY INVOKER
                RETURN
                UNHEX(REPLACE(_uuid, '-', ''));
            "
        );
    }
}
