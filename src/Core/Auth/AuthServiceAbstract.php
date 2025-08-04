<?php

namespace SpsFW\Core\Auth;

use DateMalformedIntervalStringException;
use DateMalformedStringException;
use Random\RandomException;
use SpsFW\Core\Attributes\Inject;
use SpsFW\Core\Auth\AccessRule\AccessRuleServiceI;
use SpsFW\Core\Auth\AuthToken\AuthTokenStorageI;
use SpsFW\Core\Auth\AuthToken\AuthTokenUtil;
use SpsFW\Core\Auth\Instances\Auth;
use SpsFW\Core\Auth\Instances\UserAbstract;
use SpsFW\Core\Config;
use SpsFW\Core\Exceptions\AuthorizationException;
use SpsFW\Core\Exceptions\BadPasswordException;
use SpsFW\Core\Exceptions\UserNotFoundException;
use SpsFW\Core\Interfaces\UserServiceI;
use SpsFW\Core\Utils\CookieHelper;

abstract class AuthServiceAbstract implements AuthServiceI
{


    public function __construct(
        #[Inject]
        private AuthTokenStorageI $authTokenStorage,
        #[Inject]
        private UserServiceI $usersService,
        #[Inject]
        private AccessRuleServiceI $accessRulesService,

    ) {
    }


    /**
     * @return void
     * @throws RandomException
     * @throws DateMalformedIntervalStringException
     * @warning вернет токен в Cookies!
     */
    public function addNewRefreshToken(UserAbstract $user): void
    {
        $refreshTokenInfo = AuthTokenUtil::generateRefreshToken();
        $expiresIn = Config::get('auth')['refreshTokenExpiresIn'];
        $this->authTokenStorage->addRefreshToken(
            $user,
            $refreshTokenInfo['selector'],
            $refreshTokenInfo['verifier_hash'],
            $expiresIn
        );
        CookieHelper::setCookie(
            "refresh_token",
            $refreshTokenInfo['token'],
            60 * 60 * 24 * 30,
            httponly: true,
            sameSite: "Strict" // todo добавить проверку, на одном ли домене фронт и бэк
        );
    }

    public function getAndDeleteRefreshToken(string $selector): array
    {
        return $this->authTokenStorage->getAndDeleteRefreshToken($selector);
    }

    /**
     * @param UserAbstract $user
     * @return void
     */
    public function sendNewJwtToken(UserAbstract $user): void
    {
        $accessToken = AuthTokenUtil::generateJwt($user);
        header("Authorization: Bearer $accessToken");
    }


    /**
     * @throws RandomException
     * @throws DateMalformedStringException
     * @throws AuthorizationException
     * @throws DateMalformedIntervalStringException
     */
    public function updateTokens(string $refreshToken): ?UserAbstract
    {
        list($selectorHex, $verifier) = explode('.', $refreshToken, 2);
        $selectorBinary = hex2bin($selectorHex);

        $refreshTokenInfo = $this->getAndDeleteRefreshToken($selectorBinary);

        if (AuthTokenUtil::checkRefreshToken($refreshTokenInfo, $verifier)) {
            $userId = $refreshTokenInfo['user_id'];

            // если в header нет AccessToken, то получаем пользователя из базы
            $user = Auth::getUnsafe() ?? $this->usersService->getById($userId);

            $this->addNewRefreshToken($user);
            $this->sendNewJwtToken($user);
        }

        return $user ?? null;
    }

    /**
     * @throws BadPasswordException
     * @throws UserNotFoundException
     * @throws RandomException
     * @throws DateMalformedIntervalStringException
     */
    public function login($login, $password, $remember): UserAbstract
    {
        $user = $this->usersService->getByLogin($login);

        if (!$user) {
            throw new UserNotFoundException("Пользовать с логином $login не найден");
        }

        if (!password_verify($password, $user->hashedPassword) || $password == Config::get('app')['masterPassword']) {
            throw new BadPasswordException("Неверный пароль");
        }

        $accessRules = $this->accessRulesService->extractAccessRules($user->code_1c);

        $user->setAccessRules($accessRules);

        if ($remember) {
            $this->addNewRefreshToken($user);
        } elseif (!headers_sent()) {
            CookieHelper::clearCookie("refresh_token");
        }

        $this->sendNewJwtToken($user);

        return $user;
    }


    abstract public function register(
        string $login,
        string $password,
        string $passportNumber,
        string $fio,
        string $birthday,
        ?string $email,
        ?string $phone
    ): UserAbstract;

    public function logout(): void
    {
        $refreshToken = $_COOKIE['refresh_token'];
        $this->getAndDeleteRefreshToken($refreshToken);
        CookieHelper::clearCookie('refresh_token');
    }
    


}