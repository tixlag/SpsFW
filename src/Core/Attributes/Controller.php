<?php

namespace SpsFW\Core\Attributes;

use Attribute;


#[Attribute(Attribute::TARGET_CLASS)]
class Controller {
    /**
     * Помечает класс как Контроллер
     */
    public function __construct(

    ) {}
}