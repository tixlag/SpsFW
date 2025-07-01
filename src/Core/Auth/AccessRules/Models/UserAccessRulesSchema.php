<?php

namespace SpsFW\Core\Auth\AccessRules\Models;

use SpsFW\Core\Db\Migration\MigrationsSchema;

class UserAccessRulesSchema extends MigrationsSchema
{
    public const string TABLE_NAME = 'user_access_rules';
    public const array VERSIONS = [
        '1.1' => [
            'description' => 'Initial schema',
            'up' =>
                /** @lang MariaDB */
                'CREATE TABLE user_access_rules (
                        access_rule_id binary(16) NOT NULL comment "Идентификатор правила доступа (access_rules.id)",
                        user_id binary(16) NOT NULL comment "Идентификатор пользователя (users.id)",
                        value JSON comment "Значение правила",
                    INDEX user_idx (user_id)
                ) comment "Таблица правил доступа пользователей"'
            ,
            'down' =>
            /** @lang MariaDB */
                'DROP TABLE users__access_rules;'

        ],
        '1.4' => [
            'description' => "Переход на uuid",
            'up' => /** @lang MariaDB */
                "ALTER TABLE users__access_rules
                MODIFY access_rule_id SMALLINT unsigned COMMENT 'UUID правила доступа (access_rules.id)',
                MODIFY user_id UUID COMMENT 'UUID пользователя (users.id)'
                "
        ],
        '1.5' => [
            'description' => "Ключ для исключения дубликатов",
            'up' => /** @lang MariaDB */
                " DELETE  FROM users__access_rules where 1;
ALTER TABLE users__access_rules 
    ADD UNIQUE (user_id, access_rule_id);
                "
        ]

    ];

}