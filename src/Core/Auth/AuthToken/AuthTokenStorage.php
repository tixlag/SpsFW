<?php

namespace SpsFW\Core\Auth\AuthToken;

use DateInterval;
use DateMalformedIntervalStringException;
use DateTime;
use SpsFW\Core\Auth\Instances\UserAbstract;
use SpsFW\Core\Storage\PdoStorage;

class AuthTokenStorage extends PdoStorage implements AuthTokenStorageI
{


    /**
     * @param string $selector
     * @param string $hashedToken
     * @param int $expiresIn
     * @return UserAbstract
     * @throws DateMalformedIntervalStringException
     */
    public function addRefreshToken(UserAbstract $user, string $selector, string $hashedToken, int $expiresIn): UserAbstract
    {
        $this->getPdo()->prepare(/** @lang MariaDB */
            "INSERT INTO users__refresh_tokens (user_id, selector, verifier_hash, expires_at)
                    VALUES (:user_id, :selector, :verifier_hash, :expires_at)"
        )
            ->execute([
                'user_id' => $user->uuid,
                'selector' => $selector,
                'verifier_hash' => $hashedToken,
                'expires_at' => new DateTime()->add(new DateInterval('PT'.$expiresIn.'S'))->format('Y-m-d H:i:s')
            ]);
        return $user;
    }

    public function getAndDeleteRefreshToken(string $selector) {
        $stmt = $this->getPdo()->prepare("
        DELETE FROM users__refresh_tokens 
        WHERE selector = ? 
        RETURNING *
    ");
        $stmt->execute([$selector]);
        return $stmt->fetch();
    }

}