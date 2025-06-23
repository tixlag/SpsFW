<?php

namespace SpsFW\Core\Storage;

use PDO;
use Sps\Db;
use SpsFW\Core\Models\BaseModel;

abstract class PdoStorage
{

    protected PDO $pdo;

    public function __construct()
    {
        $this->pdo = Db::get();
    }

    protected function insertModel(BaseModel $model): bool
    {

        $table = $model->getTable();
        $params = (array)$model;

        $paramsKeys = implode(', ', array_keys($params));
        $paramsValues = array_values($params);
        $paramsKeysForInsert = ':' . implode(', :', array_keys($params));

        $stmt = $this->pdo->prepare(/** @lang MariaDB */"
            INSERT INTO $table ($paramsKeys)
            VALUES ($paramsKeysForInsert)
");
        return $stmt->execute($paramsValues);

    }

    protected function insert(string $table, array $params): void
    {
        $paramsKeys = implode(', ', array_keys($params));
        $paramsValues = array_values($params);
        $paramsKeysForInsert = implode(', :', $paramsKeys);
         $this->pdo->prepare(/** @lang MariaDB */"
            INSERT INTO $table ($paramsKeys)
            VALUES ($params)
");
    }

}