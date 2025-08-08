<?php

namespace SpsFW\Core\Auth\Instances;

use Exception;
use Firebase\JWT\ExpiredException;
use SpsFW\Core\Auth\AuthToken\AuthTokenUtil;
use SpsFW\Core\Exceptions\AuthorizationException;

class Auth extends UserAbstract
{


    private static ?self $userAuth = null;

    private function __construct(string $uuid, ?string $code_1c = null, array $accessRules = [])
    {
        parent::__construct($uuid, $code_1c, $accessRules);
    }

    /**
     * @throws AuthorizationException
     */
    public static function getOrThrow(): ?Auth
    {
        if (self::$userAuth === null) {
            if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
                $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
                if (preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
                    $authToken = $matches[1];
                    try {
                        $jwtDecoded = AuthTokenUtil::decodeJwt($authToken);
                    } catch (Exception $e) {
                        throw new AuthorizationException($e->getMessage(), 401, $e);
                    }
                    self::$userAuth = new self($jwtDecoded->uuid, $jwtDecoded->code_1c, is_array($jwtDecoded->accessRules) ? $jwtDecoded->accessRules : get_object_vars($jwtDecoded->accessRules));
                } else {
                    throw new AuthorizationException('Требуется аутентификация', 401);
                }
            } else {
                throw new AuthorizationException('Требуется аутентификация', 401);
            }
        }
        return self::$userAuth;
    }

    /**
     * @return Auth|null
     */
    public static function getOrNull(): ?Auth
    {
        try {
            return self::getOrThrow();
        } catch (Exception $e) {
            return null;
        }

    }

    /**
     * Метод не безопасен! Вернет пользователя даже для просроченного AccessToken.
     * Используется как оптимизация лишних обращений в БД.
     * Если вызвать метод получения refresh-tokens, и в заголовке будет просроченный токен,
     * возьмет пользователя отсюда
     *
     * @return Auth|null
     */
    public static function getUnsafe(): ?Auth
    {
        try {
            self::getOrThrow();
        } catch (AuthorizationException $e) {
            $jwtException = $e->getPrevious();
            // Вернем только если токен просрочен
            if ($jwtException instanceof ExpiredException) {
                $payload = $jwtException->getPayload();
                return new self($payload['id'], $payload['code_1c'], $payload['accessRules']);
            } else {
                return null;
            }
        } catch (Exception $e) {
            return null;
        }
        return self::$userAuth;
    }


}