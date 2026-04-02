<?php

namespace SpsFW\Core\Exceptions;

class UnauthorizedException extends BaseException
{
    public function __construct(string $message = 'Unauthorized')
    {
        parent::__construct($message, 401);
    }
}
