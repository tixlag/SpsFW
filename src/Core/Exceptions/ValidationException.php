<?php

namespace SpsFW\Core\Exceptions;

use Throwable;

class ValidationException extends BaseException
{

    public function __construct(string $message, int $code = 500, ?Throwable $previous = null)
    {
        parent::__construct($message, 400, $previous);
    }
}