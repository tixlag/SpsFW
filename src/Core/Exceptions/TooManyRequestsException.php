<?php

namespace SpsFW\Core\Exceptions;

class TooManyRequestsException extends BaseException
{
    public function __construct(string $message = 'Too many requests')
    {
        parent::__construct($message, 429);
    }
}
