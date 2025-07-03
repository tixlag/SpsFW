<?php

namespace SpsFW\Core\Auth\AuthToken;

use DateMalformedStringException;
use DateTime;
use Exception;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Random\RandomException;
use SpsFW\Core\Auth\AccessRules\Models\UserAbstract;
use SpsFW\Core\Config;
use SpsFW\Core\Exceptions\AuthorizationException;

class AuthTokenUtil
{

    private static ?Key $key = null;

    public static function getKey(): Key
    {
        if (self::$key === null) {
            $jwtConfig = Config::get('auth')['jwt'];
            self::$key = new Key($jwtConfig['secret'], $jwtConfig['header']['alg']);
        }
        return self::$key;
    }

    public static function decodeJwt(string $jwt): object
    {
        return JWT::decode($jwt, self::getKey());
    }

    public static function generateJwt(UserAbstract $user): string
    {
        $jwtConfig = Config::get('auth')['jwt'];
        $payload = $jwtConfig['payload'];
        $payload['id'] = $user->id;
        $payload['accessRules'] = $user->accessRules;

        return JWT::encode(
            $payload,
            $jwtConfig['secret'],
            $jwtConfig['header']['alg'],
            head: $jwtConfig['header']
        );
    }


    /**
     * @param mixed $refreshTokenInfo
     * @param string $verifier
     * @return bool
     * @throws DateMalformedStringException
     * @throws AuthorizationException
     */
    public static function checkRefreshToken(mixed $refreshTokenInfo, string $verifier): bool
    {
        $good = $refreshTokenInfo
            && new DateTime($refreshTokenInfo['expires_at']) > new DateTime()
            && password_verify(
                $verifier,
                $refreshTokenInfo['verifier_hash']
            );
        if ($good) {
            return true;
        }
        throw new AuthorizationException('Неверный refresh token');
    }

    /** @return array{selector: string, token: string, verifier_hash: string}
     * @throws RandomException
     */
    public static function generateRefreshToken(): array
    {
        $selectorBinary = random_bytes(8);      // 8 байт = 16 hex символов
        $verifierBinary = random_bytes(32);     // 32 байта = 64 hex символа

        $selectorHex = bin2hex($selectorBinary);
        $verifierHex = bin2hex($verifierBinary);

        return [
            'selector' => $selectorBinary, // <-- для хранения в БД как VARBINARY(8)
            'token' => "$selectorHex.$verifierHex", // <-- для отправки клиенту
            'verifier_hash' => password_hash($verifierHex, PASSWORD_BCRYPT)
        ];
    }

}