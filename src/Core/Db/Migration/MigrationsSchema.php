<?php

namespace SpsFW\Core\Db\Migration;

abstract class MigrationsSchema
{

    /**
     * Название таблицы в которую пишет миграция
     */
    public const string TABLE_NAME = 'example';

    /**
     * Ниже пример, как описывать миграции.
     * Хорошая практика - каждую новую миграцию записывать ниже
     */
    public const array VERSIONS = [
        '1.0' => [
            'description' => 'Описание миграции',
            'up' => /** @lang MariaDB */
                "CREATE TABLE `" . self::TABLE_NAME . "` (
                `example` string NOT NULL ,
                PRIMARY KEY (`id`)
            )",
            'down' =>
            /**
             * @warning Не обязательный ключ
             * @lang MariaDB
             */
                "DROP TABLE `" . self::TABLE_NAME . "`"
        ]
    ];

    /**
     * Версия схемы (увеличивайте при изменениях)
     * @return string
     */
    public function getLastVersion(): string
    {
        return  array_key_last(static::VERSIONS);
    }

    /**
     * Получить up последней миграции
     * @return string
     */
    public function getLastUp(): string
    {
        return static::VERSIONS[static::getLastVersion()]['up'];
    }

    /**
     * Получить down последней миграции
     * @return string
     */
    public function getLastDown(): string
    {
        return static::VERSIONS[static::getLastVersion()]['down'] ?? '';
    }


    /**
     * Описание изменений в версии
     * @return string
     */
    public function getLastVersionDescription(): string
    {
        return static::VERSIONS[static::getLastVersion()]['description'];
    }

    /**
     * Описание изменений в версии
     * @return string
     */
    public function getTableName(): string
    {
        return static::TABLE_NAME;
    }


}