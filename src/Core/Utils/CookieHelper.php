<?php

namespace SpsFW\Core\Utils;

class CookieHelper
{
    /**
     * Устанавливает Cookie
     * @param string $name
     * @param string $value
     * @param int|null $expire
     * @param bool $httponly
     * @param string $sameSite
     * @return bool
     */
    public static function setCookie(string $name, string $value, ?int $expire = 0, bool $httponly = false, string $sameSite = "None"): bool
    {
        $options = array(
            'path' => '/',
            'secure' => true,
            'httponly' => $httponly,
            'samesite' => $sameSite,
        );

        if (isset($expire)) {
            $options['expires'] = $expire > 0 ? time() + $expire : $expire;
        }

        return setcookie($name, $value, $options);
    }

    /**
     * Удаляет Cookie
     * @param string $name
     * @return bool
     */
    public static function clearCookie(string $name): bool
    {
        return setcookie($name, '', array(
            'expires' => -1,
            'path' => '/',
            'secure' => true,
            'httponly' => false,
            'samesite' => 'None',
        ));
    }
}