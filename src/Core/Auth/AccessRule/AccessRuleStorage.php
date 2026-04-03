<?php

namespace SpsFW\Core\Auth\AccessRule;

use PDO;
use PDOException;
use SpsFW\Core\Auth\Util\AccessRuleRegistry;
use SpsFW\Core\Storage\PdoStorage;
//todo сделать сторедж универсальным
class AccessRuleStorage extends PdoStorage implements AccessRuleStorageI
{

    private function isMySQL(): bool
    {
        return $this->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql';
    }

    /**
     * @param string $userUuid
     * @return array<int, mixed>
     */
    public function extractAccessRules(string $userUuid): array
    {
        if ($this->isMySQL()) {
            $sql = "SELECT uar.access_rule_id, uar.value
                    FROM users__access_rules uar
                    WHERE user_uuid = UUID_TO_BIN(:uuid)";
        } else {
            $sql = "SELECT uar.access_rule_id, uar.value
                    FROM users__access_rules uar
                    WHERE user_uuid = :uuid";
        }

        $stmt = $this->getPdo()->prepare($sql);
        $stmt->execute(['uuid' => $userUuid]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
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

                if ($this->isMySQL()) {
                    $stmt = $this->getPdo()->prepare(
                    /** @lang MariaDB */
                        "INSERT INTO users__access_rules (user_uuid, access_rule_id, value)
                        VALUES (UUID_TO_BIN(:user_uuid), :access_rule_id, :value)
                        ON DUPLICATE KEY UPDATE value = VALUES(value)"
                    );
                } else {
                    $stmt = $this->getPdo()->prepare(
                        "INSERT INTO users__access_rules (user_uuid, access_rule_id, value)
                        VALUES (:user_uuid, :access_rule_id, :value)
                        ON CONFLICT (user_uuid, access_rule_id) DO UPDATE SET value = EXCLUDED.value"
                    );
                }
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

            if ($this->isMySQL()) {
                $stmt = $this->getPdo()->prepare("DELETE FROM users__access_rules WHERE user_uuid = UUID_TO_BIN(:user_uuid)");
            } else {
                $stmt = $this->getPdo()->prepare("DELETE FROM users__access_rules WHERE user_uuid = :user_uuid");
            }
            $stmt->execute(['user_uuid' => $userUuid]);

            $this->addAccessRules($userUuid, $accessRules);

            return $this->getPdo()->commit();
        } catch (PDOException $e) {
            if ($this->getPdo()->inTransaction()) {
                $this->getPdo()->rollBack();
            }
            throw $e;
        }
    }


}
