<?php
/**
 * Класс обработки авторизации пользователя, выполняет сохранение в сессию авторизацию и получение текущего авторизованного пользователя
 *
 * @package SPSUsers
 * @since 2.0.0
 */
namespace SpsFW\Core\Auth;


use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use SpsFW\Core\Auth\AuthToken\AuthTokenUtils;


class Auth
{
    /**
     * Свойство хранит текущего пользователя
     * @var User|null
     */
    protected static ?User $user = null;

    /**
     * Устанавливает авторизацию для пользователя
     * @param User $user
     * @return void
     */
    public static function set(User $user): void
    {
        self::$user = $user;
    }

    /**
     * Возвращает текущего авторизованного пользователя
     * @return User|null
     */
    public static function get(): ?User
    {
        if (isset(self::$user)) {
            return self::$user;
        }

        if (isset($_COOKIE['_refresh_token'])) {
            $refresh_token = (new AuthTokenUtils(User::get($_SESSION['id']), true))->create();
            (new RefreshTokenStore($refresh_token))->add();
            CookieHelper::setCookie("_refresh_token", $refresh_token->getTokenUuid(), isset($_COOKIE['user_id']) && $_COOKIE['user_id'] ? 60 * 60 * 24 * 365 : null, true);
        }

        $auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? null;

        // Авторизация по GWT токену
        $token = explode(" ", (string)$auth_header);

        if (sizeof($token) > 1) {
            // Проверяем авторизацию по токену
            for ($i = 0; $i < count($token); $i = $i + 2) {

                $token_type = $token[$i] ?? null;
                $token_value = $token[$i + 1] ?? null;

                if ($token_type === "Bearer" && $token_value && $token_value !== "null") {

                    try {

                        $decoded = JWT::decode($token_value, new Key(AccessToken::$secret, AccessToken::$alg));
                        self::$user = User::get($decoded->user_id);
                        return self::$user;

                    } catch (\Exception $e) {
                        // Используем \Exception т.е. там очень много исключений
                        throw new HttpError401Exception($e->getMessage());
                    }
                }
            }
        }

        // Авторизация по сессии и cookie
        if (session_status() !== PHP_SESSION_ACTIVE) {
            trigger_error(sprintf("Session is not active while reading authorization on %s %s", __FILE__, __LINE__), E_USER_WARNING);
            return null;
        }

        if (isset($_SESSION['id'])) {

            self::$user = User::get($_SESSION['id']);

            //if (self::$user) {
            // if (self::$user->getCode1c() && (self::$user->getTimeUpdate() < time() - 60 * 60 * 24 || self::$user->getCurrentStatus() === null)) {
            //    // (new UpdateUserInformationUsing1C(self::$user))->update();
            // }
            //}

        } elseif (isset($_COOKIE['user_id']) && $_COOKIE['user_id'] && isset($_COOKIE['password']) && $_COOKIE['password']) {

            $user = User::get($_COOKIE['user_id']);

            if (password_verify($user->getPassword(), $_COOKIE['password'])) {
                (new Logs\UserLoginWakeUp($user, $_COOKIE['user_id'], $_COOKIE['password']))
                    ->add();

                self::set($user);

                if (!isset($_COOKIE['_refresh_token']) && !headers_sent()) {
                    $refresh_token = (new AuthTokenUtils($user, true))->create();
                    (new RefreshTokenStore($refresh_token))->add();
                    CookieHelper::setCookie("_refresh_token", $refresh_token->getTokenUuid(), 60 * 60 * 24 * 365, true);
                }

                // if ($user->getCode1c() && ($user->getTimeUpdate() < time() - 60 * 60 * 24 || $user->getCurrentStatus() === null)) {
                //    // (new UpdateUserInformationUsing1C($user))->update();
                // }
            }
        }

        if (self::$user) {
            // Обновление даты входа
            self::$user->setTimeLogin(time());
            $store = new UserStore(self::$user);
            $store->update();
        }

        return self::$user;
    }

    /**
     * Возвращает текущего пользователя
     * @return User|null
     */
    public static function getCurrentUser(): ?User
    {
        return self::$user ?: null;
    }
}