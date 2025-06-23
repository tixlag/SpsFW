<?php

namespace SpsFW\Core\Exceptions;

use Throwable;

class UserNotFoundException extends BaseException
{

    public function __construct(string $message, int $code = 400, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}