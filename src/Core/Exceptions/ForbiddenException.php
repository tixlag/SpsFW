<?php

namespace SpsFW\Core\Exceptions;

class ForbiddenException extends BaseException
{
    public function __construct(string $message = 'Forbidden')
    {
        parent::__construct($message, 403);
    }
}
