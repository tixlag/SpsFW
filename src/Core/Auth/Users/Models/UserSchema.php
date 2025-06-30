<?php

namespace SpsFW\Core\Auth\Users\Models;


use SpsFW\Core\Db\Migration\MigrationsSchema;

class UserSchema extends MigrationsSchema
{
    public const string TABLE_NAME = 'users';
    public const array VERSIONS = [
        '1.0' => [
            'description' => 'Создание таблицы пользователей',
                'up' => /** @lang MariaDB */ "
                    CREATE TABLE IF NOT EXISTS users (
                    id binary(16) PRIMARY KEY COMMENT 'UUID пользователя',
                    login VARCHAR(25) NOT NULL UNIQUE COMMENT 'Уникальный логин пользователя',
                    code_1c VARCHAR(14) UNIQUE COMMENT 'Уникальный код 1с пользователя. NULL, если еще нет в 1c',
                    hashed_password TEXT NOT NULL COMMENT 'Хэшированный пароль',
                    passport VARCHAR(255) NOT NULL COMMENT 'Паспортные данные',
                    fio VARCHAR(255) NOT NULL COMMENT 'ФИО пользователя',
                    birthday DATE NOT NULL COMMENT 'Дата рождения',
                    email VARCHAR(255) DEFAULT NULL COMMENT 'Электронная почта',
                    phone VARCHAR(20) DEFAULT NULL COMMENT 'Номер телефона',
                    refresh_token TEXT DEFAULT NULL COMMENT 'Токен обновления сессии',
                    access_rules JSON DEFAULT NULL COMMENT 'Права доступа (JSON)',
                    time_signup DATETIME DEFAULT NULL COMMENT 'Время регистрации',
                    time_login DATETIME DEFAULT NULL COMMENT 'Время последнего входа'
                ) comment 'Таблица пользователей'
                    ",
                'down' => /** @lang MariaDB */ "
                    DROP TABLE users;
                    "
            ],
        '1.4' => [
            'description' => "Переход на uuid",
            'up' => /** @lang MariaDB */
                "ALTER TABLE users MODIFY id UUID COMMENT 'UUID пользователя'"
        ]


    ];


}