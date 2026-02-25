<?php

namespace SpsFW\Core\DateTime;

use DateInvalidTimeZoneException;
use DateMalformedStringException;
use DateTime;
use DateTimeZone;

class DateTimeHelper
{

    /**
     * @throws DateInvalidTimeZoneException
     * @throws DateMalformedStringException
     */
    public static function toUserTimezone($utcDateTime, $userTimezone): DateTime
    {
        $dt = new DateTime($utcDateTime, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone($userTimezone));
        return $dt;
    }

    /**
     * @throws DateMalformedStringException
     * @throws DateInvalidTimeZoneException
     */
    public static function toUTC($localDateTime, $userTimezone): DateTime
    {
        $dt = new DateTime($localDateTime, new DateTimeZone($userTimezone));
        $dt->setTimezone(new DateTimeZone('UTC'));
        return $dt;
    }
}