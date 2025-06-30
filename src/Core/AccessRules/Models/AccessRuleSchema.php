<?php

namespace SpsFW\Core\AccessRules\Models;

use SpsFW\Core\Db\Migration\MigrationsSchema;

class AccessRuleSchema extends MigrationsSchema
{
    public const string TABLE_NAME = 'access_rules';
    public const array VERSIONS = [
        '1.0' => [
            'description' => 'Initial schema',
            'up' =>
                /** @lang MariaDB */'CREATE TABLE access_rules (
                    id binary(16) NOT NULL comment "Идентификатор правила доступа",
                    name VARCHAR(255) NOT NULL comment "Название правила доступа",
                    description VARCHAR(255) NOT NULL comment "Описание правила доступа",
                    PRIMARY KEY (id)
                ) comment "Таблица правил доступа"'
            ,
            'down' =>
                /** @lang MariaDB */ 'DROP TABLE access_rules;'

        ],
        '1.5' => [
            'description' => "Переход на uuid",
            'up' => /** @lang MariaDB */
                "ALTER TABLE access_rules MODIFY id SMALLINT UNSIGNED COMMENT 'ID правила доступа'"
        ],
        '1.6' => [
            'description' => "Переход на uuid",
            'up' => /** @lang MariaDB */
                "ALTER TABLE access_rules 
    ADD COLUMN role varchar(250) COMMENT 'Роль, для которой возможно правило',
    ADD INDEX role_idx (role)

"
        ]
    ];

}