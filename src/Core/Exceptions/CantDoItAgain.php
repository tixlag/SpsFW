<?php

namespace SpsFW\Core\Exceptions;

use Throwable;

class CantDoItAgain extends BaseException
{

    public function __construct(string $message, int $code = 500, ?Throwable $previous = null)
    {
        parent::__construct($message, 409, $previous);
    }
}