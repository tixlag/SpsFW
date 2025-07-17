<?php

namespace SpsFW\Core\Utils;

use DateInvalidTimeZoneException;
use DateMalformedStringException;
use DateTime;
use DateTimeZone;
use SpsFW\Core\Exceptions\ValidationException;

class DateTimeHelper
{


    /**
     * @throws ValidationException
     */
    public static function toUserTimezone(string $utcDateTime): DateTime
    {
        $userTimezone = $SERVER['HTTP-X-TIMEZONE'] ?? 'UTC';
        try {
            $dt = new DateTime($utcDateTime, new DateTimeZone('UTC'));
        } catch (DateMalformedStringException $e) {
            throw new ValidationException("Неверная дата: $utcDateTime");
        }
        try {
            $dt->setTimezone(new DateTimeZone($userTimezone));
        } catch (DateInvalidTimeZoneException $e) {
            throw new ValidationException("Неверная timezone: $userTimezone");
        }
        return $dt;
    }


    /**
     * @throws ValidationException
     */
    public static function toUTC(string $localDateTime): DateTime
    {
        $userTimezone = $SERVER['HTTP-X-TIMEZONE'] ?? 'UTC';
        try {
            $dt = new DateTime($localDateTime, new DateTimeZone($userTimezone));
        } catch (DateMalformedStringException $e) {
            throw new ValidationException("Неверная дата: $localDateTime");
        } catch (DateInvalidTimeZoneException $e) {
            throw new ValidationException("Неверная timezone: $userTimezone");
        }
            $dt->setTimezone(new DateTimeZone('UTC'));

        return $dt;
    }

    public static function try_create(string $value)
    {
        return new DateTime($value);
    }
}