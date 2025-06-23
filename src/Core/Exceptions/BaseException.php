<?php

namespace SpsFW\Core\Exceptions;

use Exception;
use Throwable;

class BaseException extends Exception
{

    /**
     * @param string $message
     */
    public function __construct(string $message, int $code = 500, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}