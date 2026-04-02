<?php

namespace SpsFW\Core\Exceptions;

class NotFoundException extends BaseException
{
    public function __construct(string $message = 'Not found')
    {
        parent::__construct($message, 404);
    }
}
