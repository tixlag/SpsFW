<?php

namespace SpsFW\Core\Storage;

use PDO;
use Ramsey\Uuid\Uuid;
use SpsFW\Core\Db\Db;
use SpsFW\Core\Db\Models\BaseModel;

abstract class PdoStorage
{

    protected ?PDO $pdo = null;

    protected function getPdo(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = Db::get();
        }
        return $this->pdo;
    }

    protected function generateId(): string
    {
        return Uuid::uuid7()->toString();
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
        $paramsValues = implode(', ', array_values($params));
        $paramsKeysForInsert = implode(', :', $params);
         $this->pdo->prepare(/** @lang MariaDB */"
            INSERT INTO $table ($paramsKeys)
            VALUES ($paramsValues)
");
    }

}