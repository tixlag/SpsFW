<?php

namespace SpsFW\Core\Db\Models;

abstract class BaseModel
{
    public const TABLE = '';


    public function getTable()
    {
        return static::TABLE;
    }






}