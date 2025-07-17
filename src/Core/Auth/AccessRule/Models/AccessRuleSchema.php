<?php

namespace SpsFW\Core\Auth\AccessRule\Models;

use SpsFW\Core\Db\Migration\MigrationsSchema;

class AccessRuleSchema extends MigrationsSchema
{
    public const string TABLE_NAME = 'access_rules';
    public const array VERSIONS = [
        '1.0' => [
            'description' => 'Initial schema',
            'up' =>
                /** @lang MariaDB */'CREATE TABLE IF NOT EXISTS access_rules (
                    id SMALLINT UNSIGNED COMMENT "Идентификатор правила доступа",
                    name VARCHAR(255) NOT NULL comment "Название правила доступа",
                    role varchar(250) COMMENT "Роль, которой принадлежит правило правило", -- переписать на отдельную таблицу
                    description VARCHAR(255) NOT NULL comment "Описание правила доступа",
                    PRIMARY KEY (id),
                     
                    UNIQUE (id),                    
                    INDEX (role)
                ) comment "Таблица правил доступа"'
            ,
            'down' =>
                /** @lang MariaDB */ 'DROP TABLE access_rules;'

        ],
    ];

}