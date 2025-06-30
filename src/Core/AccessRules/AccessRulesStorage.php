<?php

namespace SpsFW\Core\AccessRules;

use DateInterval;
use DateTime;
use PDOException;
use SpsFW\Core\Auth\Users\Models\User;
use SpsFW\Core\Auth\Users\Models\Auth;
use SpsFW\Core\Auth\Users\Models\UserAuthI;
use SpsFW\Core\Db\Db;
use SpsFW\Core\Storage\PdoStorage;

class AccessRulesStorage extends PdoStorage //todo реализовать интерфейс и избавиться от статики
{


    /**
     * @param string $userId
     * @return array<int, mixed>
     */
    public function extractAccessRules(string $userId): array
    {
        $stmt = $this->pdo->prepare(
        /** @lang MariaDB */
            "SELECT
                    uar.access_rule_id,
                    uar.value
                    FROM
                        users__access_rules uar
                    WHERE user_id = :id
                    "
        );
        $stmt->execute(['id' => $userId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if (!$rows) return [];
        $accessRules = [];
        foreach ($rows as $row) {
            $accessRules[$row['access_rule_id']] = isset($row['value']) ? json_decode($row['value'], true) : true;
        }
        return $accessRules;
    }

    public function addAccessRules(string $userId, array $accessRules): bool
    {

        $transactionStarted = false;

        try {
            if (!$this->pdo->inTransaction()) {
                $this->pdo->beginTransaction();
                $transactionStarted = true;
            }

            foreach ($accessRules as $accessRuleId => $accessRuleValue) {
                $stmt = $this->pdo->prepare(
                /** @lang MariaDB */
                    "INSERT IGNORE INTO access_rules (id, name, description, role)
                        VALUES (:id, :name, :description, :role)
                        "
                );
                $stmt->execute([
                    'id' => $accessRuleId,
                    'name' => AccessRulesRegistry::getRuleConstant($accessRuleId),
                    'description' => AccessRulesRegistry::getRuleDescription($accessRuleId),
                    'role' => AccessRulesRegistry::getRole($accessRuleId)
                ]);


                $stmt = $this->pdo->prepare(
                /** @lang MariaDB */
                    "INSERT INTO users__access_rules (user_id, access_rule_id, value)
                    VALUES (:user_id, :access_rule_id, :value)
                ON DUPLICATE KEY UPDATE value = VALUES(value)
                    "
                );
                $stmt->execute([
                    'user_id' => $userId,
                    'access_rule_id' => $accessRuleId,
                    'value' => json_encode($accessRuleValue)
                ]);
            }
            if ($transactionStarted) {
                return $this->pdo->commit();
            }

            return true;
        } catch (PDOException $e) {
            if ($transactionStarted && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function setAccessRules(string $userId, array $accessRules): bool
    {
        try {
            $this->pdo->beginTransaction();

            $stmt = $this->pdo->prepare(/** @lang MariaDB */ "DELETE FROM users__access_rules WHERE user_id = :user_id");
            $stmt->execute(['user_id' => $userId]);

            $this->addAccessRules($userId, $accessRules);

            return $this->pdo->commit();
        } catch (PDOException $e) {
            // Откатываем транзакцию, если она начата
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }


}