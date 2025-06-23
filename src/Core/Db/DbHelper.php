<?php

namespace SpsFW\Core\Db;

class DbHelper
{


    /**
     * @param  $dto - dto, из чьих полей хотим получить колонки в таблице БД
     * @return string Строка вида "master_code_1c, employee_id" для получения имен колонок таблицы соответствующие полям в DTO
     */
    public static function getColumnsForQuery(array|object $dto): string
    {
        return implode(', ', array_keys((array)$dto));
    }


    /**
     * Из DTO получаем строку вида ":master_code_1c, :employee_id" для вставки в prepare() выражения
     * @param array|object $dto
     * @return string строка для вставки в VALUES нашего запроса к бд: ":code_id, :employee_id"
     */
    public static function getParamsForQuery(array|object $dto): string
    {
        return implode(', ', array_values(array_map(function ($key) {
            return ":$key";
        }, array_keys((array)$dto))));
    }

    /**
     * Правильно биндит параметры нашего dto к $stmt нашей бд
     * @param array|object $dto
     * @param false|\PDOStatement $stmt
     */
    public static function bindParamsToQuery(array|object $dto, false|\PDOStatement &$stmt): void
    {
        if ($stmt) {
            foreach ((array)$dto as $key => $value) {
                $stmt->bindValue(":$key", $value);
            }
        }
    }

    public static function insertFromDto(string $table, array|object $dto): array
    {
        $data = (array)$dto;
        $columns = implode(', ', array_keys($data));
        $placeholders = ':' . implode(', :', array_keys($data));

        return [
            'sql' => "INSERT INTO $table ($columns) VALUES ($placeholders)",
            'params' => $data
        ];
    }


}