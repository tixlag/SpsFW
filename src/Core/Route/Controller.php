<?php

namespace SpsFW\Core\Route;

use Attribute;
use SpsFW\Core\Http\HttpMethod;


#[Attribute(Attribute::TARGET_CLASS)]
class Controller {
    /**
     * Помечает класс как Контроллер
     */
    public function __construct(

    ) {}
}