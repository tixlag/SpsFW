<?php

namespace SpsFW\Core\Exceptions;

use Throwable;

class TokenExpiredException extends BaseException
{

    public function __construct(string $message, int $code = 401, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}