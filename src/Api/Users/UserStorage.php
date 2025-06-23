<?php

namespace SpsFW\Api\Users;

use SpsFW\Core\Storage\RestStorage;

class UserStorage extends RestStorage
{


    public function changePassword(int $userId, string $password): void
    {
        $sth = $this->pdo->prepare(/** @lang MariaDB */"UPDATE profile set password = :password where id = :id");
        $sth->execute([
            ":id" => $userId,
            ":password" => password_hash($password, PASSWORD_DEFAULT)
        ]);


    }

}