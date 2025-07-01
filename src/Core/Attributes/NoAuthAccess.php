<?php

namespace SpsFW\Core\Attributes;

use Attribute;


#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
class NoAuthAccess
{
    /**
     * Устанавливается над методом контроллера или класса,
     * показывая, что для доступа не нужен аутентификационный токен
     */
    public function __construct(
    ) {
    }

}