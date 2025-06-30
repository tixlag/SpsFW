<?php

namespace SpsFW\Core\Auth\AuthToken;

use DateInterval;
use DateTime;
use SpsFW\Core\Auth\Users\Models\User;
use SpsFW\Core\Auth\Users\Models\Auth;
use SpsFW\Core\Auth\Users\Models\UserAuthI;
use SpsFW\Core\Db\Db;
use SpsFW\Core\Storage\PdoStorage;

class AuthTokenStorage extends PdoStorage //todo реализовать интерфейс и избавиться от статики
{


    /**
     * @param UserAuthI $user
     * @param string $selector
     * @param string $hashedToken
     * @param int $expiresIn
     * @return UserAuthI
     */
    public function addRefreshToken(UserAuthI $user, string $selector, string $hashedToken, int $expiresIn): UserAuthI
    {
        $this->pdo->prepare(/** @lang MariaDB */
            "INSERT INTO users__refresh_tokens (user_id, selector, verifier_hash, expires_at)
                    VALUES (:user_id, :selector, :verifier_hash, :expires_at)"
        )
            ->execute([
                'user_id' => $user->id,
                'selector' => $selector,
                'verifier_hash' => $hashedToken,
                'expires_at' => new DateTime()->add(new DateInterval("P$expiresIn\S"))->format('Y-m-d H:i:s')
            ]);
        return $user;
    }

    public function getAndDeleteRefreshToken(string $selector) {
        $stmt = $this->pdo->prepare("
        DELETE FROM users__refresh_tokens 
        WHERE selector = ? 
        RETURNING *
    ");
        $stmt->execute([$selector]);
        return $stmt->fetch();
    }

}