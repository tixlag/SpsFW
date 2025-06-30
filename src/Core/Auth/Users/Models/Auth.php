<?php

namespace SpsFW\Core\Auth\Users\Models;

use DateTime;
use Exception;
use Firebase\JWT\ExpiredException;
use SpsFW\Core\AccessRules\Dto\AccessRulesArrayDto;
use SpsFW\Core\Auth\AuthToken\AccessRulesService;
use SpsFW\Core\Exceptions\AuthorizationException;

class Auth implements UserAuthI
{
    private(set) string $id;
    private(set) string $refreshToken;
    private(set) array $accessRules;

    private static ?self $userAuth = null;

    private function __construct(string $id, array $accessRules)
    {
        $this->id = $id;
        $this->accessRules = $accessRules;
    }

    public function setRefreshToken(string $refreshToken): self
    {
        $this->refreshToken = $refreshToken;
        return $this;
    }

    public function setTimeLogin(DateTime $timeLogin): self
    {
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
                        $jwtDecoded = AccessRulesService::decodeJwt($authToken);
                    } catch (Exception $e) {
                        throw new AuthorizationException('JWT problem', 401, $e);
                    }
                    self::$userAuth = new self($jwtDecoded->id, is_array($jwtDecoded->accessRules) ? $jwtDecoded->accessRules : get_object_vars($jwtDecoded->accessRules));
                }
            }
        }
        return self::$userAuth;
    }

    public static function getOrNull()
    {
        try {
            self::getOrThrow();
        } catch (Exception $e) {
            return null;
        }

    }

    public static function getUnsafe(): ?Auth
    {
        try {
            self::getOrThrow();
        } catch (AuthorizationException $e) {
            $jwtException = $e->getPrevious();
            // Вернем только если токен просрочен
            if ($jwtException instanceof ExpiredException) {
                $payload = $jwtException->getPayload();
                return new self($payload['id'], $payload['accessRules']);
            } else {
                return null;
            }
        } catch (Exception $e) {
            return null;
        }
        return self::$userAuth;
    }




}