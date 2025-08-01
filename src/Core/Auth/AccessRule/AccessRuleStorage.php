<?php

namespace SpsFW\Core\Auth\AccessRule;

use PDOException;
use SpsFW\Core\Auth\Util\AccessRuleRegistry;
use SpsFW\Core\Storage\PdoStorage;

class AccessRuleStorage extends PdoStorage implements AccessRuleStorageI
{


    /**
     * @param string $userUuid
     * @return array<int, mixed>
     */
    public function extractAccessRules(string $userUuid): array
    {
        $stmt = $this->getPdo()->prepare(
        /** @lang MariaDB */
            "SELECT
                    uar.access_rule_id,
                    uar.value
                    FROM
                        users__access_rules uar
                    WHERE user_uuid = UUID_TO_BIN(:uuid)
                    "
        );
        $stmt->execute(['uuid' => $userUuid]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if (!$rows) return [];
        $accessRules = [];
        foreach ($rows as $row) {
            $accessRules[$row['access_rule_id']] = isset($row['value']) ? json_decode($row['value'], true) : [];
        }
        return $accessRules;
    }

    public function addAccessRules(string $userUuid, array $accessRules): bool
    {

        $transactionStarted = false;

        try {
            if (!$this->getPdo()->inTransaction()) {
                $this->getPdo()->beginTransaction();
                $transactionStarted = true;
            }

            foreach ($accessRules as $accessRuleId => $accessRuleValue) {
                $stmt = $this->getPdo()->prepare(
                /** @lang MariaDB */
                    "INSERT IGNORE INTO access_rules (id, name, description, role)
                        VALUES (:id, :name, :description, :role)
                        "
                );
                $stmt->execute([
                    'id' => $accessRuleId,
                    'name' => AccessRuleRegistry::getRuleConstant($accessRuleId),
                    'description' => AccessRuleRegistry::getRuleDescription($accessRuleId),
                    'role' => AccessRuleRegistry::getRole($accessRuleId)
                ]);


                $stmt = $this->getPdo()->prepare(
                /** @lang MariaDB */
                    "INSERT INTO users__access_rules (user_uuid, access_rule_id, value)
                    VALUES (UUID_TO_BIN(:user_uuid), :access_rule_id, :value)
                ON DUPLICATE KEY UPDATE value = VALUES(value)
                    "
                );
                $stmt->execute([
                    'user_uuid' => $userUuid,
                    'access_rule_id' => $accessRuleId,
                    'value' => json_encode($accessRuleValue)
                ]);
            }
            if ($transactionStarted) {
                return $this->getPdo()->commit();
            }

            return true;
        } catch (PDOException $e) {
            if ($transactionStarted && $this->getPdo()->inTransaction()) {
                $this->getPdo()->rollBack();
            }
            throw $e;
        }
    }

    public function setAccessRules(string $userUuid, array $accessRules): bool
    {
        try {
            $this->getPdo()->beginTransaction();

            $stmt = $this->getPdo()->prepare(/** @lang MariaDB */ "DELETE FROM users__access_rules WHERE user_uuid = UUID_TO_BIN(:user_id)");
            $stmt->execute(['user_id' => $userUuid]);

            $this->addAccessRules($userUuid, $accessRules);

            return $this->getPdo()->commit();
        } catch (PDOException $e) {
            // Откатываем транзакцию, если она начата
            if ($this->getPdo()->inTransaction()) {
                $this->getPdo()->rollBack();
            }
            throw $e;
        }
    }


}