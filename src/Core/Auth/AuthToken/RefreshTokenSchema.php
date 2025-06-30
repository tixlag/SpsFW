<?php

namespace SpsFW\Core\Auth\AuthToken;

use SpsFW\Core\Db\Migration\MigrationsSchema;

class RefreshTokenSchema extends MigrationsSchema
{

    public const string TABLE_NAME = 'users__refresh_tokens';

    public const array VERSIONS = [
        '2.1' => [
            'description' => 'Initial schema',
            'up' => /** @lang MariaDB */
                "
                CREATE TABLE IF NOT EXISTS " . self::TABLE_NAME . " (
                    user_id UUID NOT NULL COMMENT 'UUID пользователя (users.id)',
                    selector VARBINARY(8) NOT NULL UNIQUE,
                    verifier_hash CHAR(60) NOT NULL,       
                    expires_at DATETIME NOT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                
                    INDEX idx_expires_at(expires_at) 
            ) COMMENT 'Таблица refresh-токенов пользователей'
            ",
            'down' =>
            /** @lang MariaDB */
                "DROP TABLE " . self::TABLE_NAME
        ],
    ];

}