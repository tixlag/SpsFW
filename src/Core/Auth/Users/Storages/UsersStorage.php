<?php

namespace SpsFW\Core\Auth\Users\Storages;

use PDOException;
use SpsFW\Core\Auth\AccessRules\AccessRulesRegistry;
use SpsFW\Core\Auth\Users\Models\User;
use SpsFW\Core\Storage\PdoStorage;

class UsersStorage extends PdoStorage implements UsersStorageI
{
    /**
     * Возвращает пользователя с назначенным uuid
     * @param User $user
     * @return User
     */
    public function register(User $user): User
    {
        $stmt = $this->getPdo()->prepare(
        /** @lang MariaDB */
            "INSERT INTO users (id, login, hashed_password, passport, fio, birthday, email, phone)
        VALUES (UUID_TO_BIN(UUID_V7()), :login, :hashed_password, :passport, :fio, :birthday, :email, :phone)
        RETURNING BIN_TO_UUID(id) as user_id"
        );

        $stmt->execute([
            'login' => $user->login,
            'hashed_password' => $user->hashedPassword,
            'passport' => $user->passport,
            'fio' => $user->fio,
            'birthday' => $user->birthday,
            'email' => $user->email,
            'phone' => $user->phone
        ]);

        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        $user->setId($result['user_id']);

        return $user;
    }

//    public function login(string $login, string $hashedPassword)
//    {
//        $stmt = $this->getPdo()->prepare(/** @lang MariaDB */
//            "SELECT
//                    u.*,
//                    uar.access_rule_id,
//                    uar.value
//                    FROM users u
//                    LEFT JOIN user_access_rules uar ON users.id = uar.user_id
//                    JOIN access_rules ON uar.access_rule_id = access_rules.id
//                    WHERE login = :login AND password = :hashedPassword
//                    ");
//        $stmt->execute(['login' => $login, 'hashedPassword' => $hashedPassword]);
//        $rows = $stmt->fetch(\PDO::FETCH_ASSOC);
//        $user = new User($rows[0]['login'], $rows[0]['$hashedPassword'], $rows[0]['passport'], $rows[0]['fio'], $rows[0]['birthday'], $rows[0]['email'], $rows[0]['phone']);
//
//        $accessRules = [];
//        foreach ($rows as $row) {
//            $accessRules[$row['access_rule_id']] = $row['value'] ?? true;
//        }
//
//        $user->setAccessRules($accessRules);
//
//    }


    public function getByLogin(string $login): ?User
    {
        $stmt = $this->getPdo()->prepare(
        /** @lang MariaDB */
            "SELECT
                    u.*
                    FROM users u
                    WHERE login = :login;
                    "
        );
        $stmt->execute(['login' => $login]);
        $rawUser = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$rawUser) {
            return null;
        }
        $user = new User(
            $rawUser['login'],
            $rawUser['code_1c'],
            $rawUser['hashed_password'],
            $rawUser['passport'],
            $rawUser['fio'],
            $rawUser['birthday'],
            $rawUser['email'],
            $rawUser['phone']
        );
        $user->setId($rawUser['id']);
        return $user;
    }

    public function getById(string $id): ?User
    {
        $stmt = $this->getPdo()->prepare(
        /** @lang MariaDB */
            "SELECT
                    u.*
                    FROM users u
                    WHERE id = :id;
                    "
        );
        $stmt->execute(['id' => $id]);
        $rawUser = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$rawUser) {
            return null;
        }
        $user = new User(
            $rawUser['login'],
            $rawUser['code_1c'],
            $rawUser['hashed_password'],
            $rawUser['passport'],
            $rawUser['fio'],
            $rawUser['birthday'],
            $rawUser['email'],
            $rawUser['phone']
        );
        $user->setId($rawUser['id']);
        return $user;
    }





    public function addAccessRules(string $userId, array $accessRules): bool
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
                'name' => AccessRulesRegistry::getRuleConstant($accessRuleId),
                'description' => AccessRulesRegistry::getRuleDescription($accessRuleId),
                'role' => AccessRulesRegistry::getRole($accessRuleId)
            ]);


            $stmt = $this->getPdo()->prepare(
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

    public function setAccessRules(string $userId, array $accessRules): bool
    {
        try {
            $this->getPdo()->beginTransaction();

            $stmt = $this->getPdo()->prepare(/** @lang MariaDB */ "DELETE FROM users__access_rules WHERE user_id = :user_id");
            $stmt->execute(['user_id' => $userId]);

            $this->addAccessRules($userId, $accessRules);

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