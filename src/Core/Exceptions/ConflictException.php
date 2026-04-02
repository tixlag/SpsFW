<?php

namespace SpsFW\Core\Exceptions;

class ConflictException extends BaseException
{
    public function __construct(string $message = 'Conflict')
    {
        parent::__construct($message, 409);
    }
}
