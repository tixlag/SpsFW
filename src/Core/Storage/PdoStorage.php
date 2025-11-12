<?php

namespace SpsFW\Core\Storage;

use PDO;
use SpsFW\Core\Db\Db;
use SpsFW\Core\Db\Models\BaseModel;

abstract class PdoStorage
{

    /**
     * @var array<PDO>
     */
    protected array $pdo = [];

    public function getPdo(string $id = 'db'): PDO
    {
        if (!isset($this->pdo[$id])) {
            $this->pdo[$id] = Db::getByConfig($id);
        }
        return $this->pdo[$id];
    }



//    protected function generateId(): string
//    {
//        return Uuid::uuid7()->toString();
//    }

    protected function insertModel(BaseModel $model): bool
    {

        $table = $model->getTable();
        $params = (array)$model;

        $paramsKeys = implode(', ', array_keys($params));
        $paramsValues = array_values($params);
        $paramsKeysForInsert = ':' . implode(', :', array_keys($params));

        $stmt = $this->pdo['db']->prepare(/** @lang MariaDB */"
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
         $this->pdo['db']->prepare(/** @lang MariaDB */"
            INSERT INTO $table ($paramsKeys)
            VALUES ($paramsValues)
");
    }

}