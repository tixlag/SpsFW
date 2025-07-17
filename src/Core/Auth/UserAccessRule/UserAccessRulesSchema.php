<?php

namespace SpsFW\Core\Auth\UserAccessRule;

use SpsFW\Core\Db\Migration\MigrationsSchema;

class UserAccessRulesSchema extends MigrationsSchema
{
    public const string TABLE_NAME = 'users__access_rules';
    public const array VERSIONS = [
        '1.0' => [
            'description' => 'Initial schema',
            'up' =>
                /** @lang MariaDB */
                'CREATE TABLE IF NOT EXISTS users__access_rules (
                        access_rule_id SMALLINT unsigned COMMENT "Идентификатор правила доступа (access_rules.id)",
                        user_uuid BINARY(16) NOT NULL comment "Идентификатор пользователя (users.uuid)",
                        value JSON comment "Значение правила",
                    INDEX user_idx (user_uuid),
                    UNIQUE (user_uuid, access_rule_id)
                ) comment "Таблица правил доступа пользователей"'
            ,
            'down' =>
            /** @lang MariaDB */
                'DROP TABLE users__access_rules;'
        ],

    ];

}