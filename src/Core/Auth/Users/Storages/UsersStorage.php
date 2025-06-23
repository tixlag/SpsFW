<?php

namespace SpsFW\Core\Auth\Users\Storages;

use SpsFW\Core\Auth\Users\Models\User;
use SpsFW\Core\Storage\PdoStorage;

class UsersStorage extends PdoStorage implements UsersStorageI
{
    public function register(User $user): bool {

       $stmt =  $this->pdo->prepare(/** @lang MariaDB */
            "INSERT INTO users (login, password, passport, fio, birthday, email, phone)
            VALUES (:login, :password, :passport, :fio, :birthday, :email, :phone)
        ");

        return $stmt->execute(['login' => $user->login, 'password' => $user->hashedPassword, 'passport' => $user->passport, 'fio' => $user->fio, 'birthday' => $user->birthday, 'email' => $user->email, 'phone' => $user->phone]);

    }

//    public function login(string $login, string $hashedPassword)
//    {
//        $stmt = $this->pdo->prepare(/** @lang MariaDB */
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


    public function getByLogin(string $login): User
    {
        $stmt = $this->pdo->prepare(/** @lang MariaDB */
            "SELECT
                    u.*,
                    uar.access_rule_id,
                    uar.value
                    FROM users u
                    LEFT JOIN user_access_rules uar ON users.id = uar.user_id
                    WHERE login = :login
                    ");
        $stmt->execute(['login' => $login]);
        $rows = $stmt->fetch(\PDO::FETCH_ASSOC);
        $user = new User($rows[0]['login'], $rows[0]['$hashedPassword'], $rows[0]['passport'], $rows[0]['fio'], $rows[0]['birthday'], $rows[0]['email'], $rows[0]['phone']);

        $accessRules = [];
        foreach ($rows as $row) {
            $accessRules[$row['access_rule_id']] = $row['value'] ?? true;
        }

        $user->setAccessRules($accessRules);

        return $user;
    }


    public function logout()
    {
    }


}