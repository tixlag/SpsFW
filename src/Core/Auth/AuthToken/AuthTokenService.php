<?php

namespace SpsFW\Core\Auth\AuthToken;

use DateMalformedStringException;
use DateTime;
use Exception;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Random\RandomException;
use SpsFW\Core\Auth\Users\Models\User;
use SpsFW\Core\Auth\Users\Models\Auth;
use SpsFW\Core\Auth\Users\Models\UserAuthI;
use SpsFW\Core\Auth\Users\UsersService;
use SpsFW\Core\Auth\Users\UsersServiceI;
use SpsFW\Core\Config;
use SpsFW\Core\Exceptions\AuthorizationException;
use SpsFW\Core\Utils\CookieHelper;

class AuthTokenService
{
    private static ?array $config = null;

    private ?AuthTokenStorage $authTokenStorage;
    private ?UsersServiceI $usersService;

    public function __construct(?AuthTokenStorage $authTokenStorage = null, ?UsersServiceI $usersService = null)
    {
        $this->authTokenStorage = $authTokenStorage ?? new AuthTokenStorage();
        $this->usersService = $usersService ?? new UsersService();
    }

    private static function init(): void
    {
        if (self::$config == null) {
            self::$config = Config::get('jwt');
        }


    }

    /** @return array{selector: string, token: string, verifier_hash: string}
     * @throws RandomException
     */
    public function generateRefreshToken(): array
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

    public static function generateJwt(UserAuthI $user): string
    {
        self::init();
        $payload = self::$config['payload'];
        $payload['id'] = $user->id;
        $payload['accessRules'] = $user->accessRules;

        return JWT::encode($payload, self::$config['secret'], self::$config['header']['alg'], head: self::$config['header']);
    }

/** @throws Exception */
    public static function decodeJwt(string $jwt): object
    {
        return JWT::decode($jwt, self::getKey());
    }

    public static function getKey(): Key
    {
        self::init();
        return new Key(self::$config['secret'], self::$config['header']['alg']);

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
        $good =  $refreshTokenInfo
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










    /**
     * @param User $user
     * @return void
     * @throws RandomException
     * @warning вернет токен в Cookies!
     */
    public function addNewRefreshToken(UserAuthI $user): void
    {
        $refreshTokenInfo = self::generateRefreshToken();
        $expiresIn = Config::get('auth')['refreshTokenExpiresIn'];
        $this->authTokenStorage->addRefreshToken($user, $refreshTokenInfo['selector'], $refreshTokenInfo['verifier_hash'], $expiresIn);
        CookieHelper::setCookie(
            "refresh_token",
            $refreshTokenInfo['token'],
            60 * 60 * 24 * 30,
            httponly: true,
            sameSite: "Strict" // todo добавить проверку, на одном ли домене фронт и бэк
        );
    }

    public function getAndDeleteRefreshToken(string $selector): array {
        return $this->authTokenStorage->getAndDeleteRefreshToken($selector);
    }

    /**
     * @param UserAuthI $user
     * @return void
     */
    public function sendNewJwtToken(UserAuthI $user): void
    {
        $accessToken = self::generateJwt($user);
        header("Authorization: Bearer $accessToken");
    }


    /**
     * @throws RandomException
     * @throws DateMalformedStringException
     * @throws AuthorizationException
     */
    public function updateTokens(string $refreshToken): ?UserAuthI
    {

        list($selectorHex, $verifier) = explode('.', $refreshToken, 2);
        $selectorBinary = hex2bin($selectorHex);

        $refreshTokenInfo = $this->getAndDeleteRefreshToken($selectorBinary);

        if (AuthTokenService::checkRefreshToken($refreshTokenInfo, $verifier)) {
            $userId = $refreshTokenInfo['user_id'];

            // если в header нет AccessToken, то получаем пользователя из базы
            $user = Auth::getUnsafe() ?? $this->usersService->getById($userId);

            $this->addNewRefreshToken($user);
            $this->sendNewJwtToken($user);

        }

        return $user ?? null;
    }




}