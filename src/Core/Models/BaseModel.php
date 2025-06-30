<?php

namespace SpsFW\Core\Models;

abstract class BaseModel
{
    public const TABLE = '';


    public function getTable()
    {
        return static::TABLE;
    }




}