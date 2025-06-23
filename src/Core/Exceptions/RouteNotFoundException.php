<?php

namespace SpsFW\Core\Exceptions;

class RouteNotFoundException extends BaseException
{

    /**
     * @param string $path
     */
    public function __construct(string $path)
    {
        parent::__construct("Route '{$path}' not found", 404);
    }
}