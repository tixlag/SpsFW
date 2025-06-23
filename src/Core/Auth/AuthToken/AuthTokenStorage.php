<?php

namespace SpsFW\Core\Auth\AuthToken;

use SpsFW\Core\Auth\Users\Models\User;
use SpsFW\Core\Db\Db;
use SpsFW\Core\Storage\PdoStorage;

class AuthTokenStorage extends PdoStorage
{
    public static function setRefreshToken(User $user, string $token)
    {
        Db::get()->prepare(/** @lang MariaDB */"UPDATE users SET refresh_token = :token WHERE id = :id")
            ->execute([
            'token' => $token,
            'id' => $user->id
        ]);
    }

}