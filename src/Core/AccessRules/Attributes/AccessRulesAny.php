<?php

namespace SpsFW\Core\AccessRules\Attributes;

use Attribute;
use SpsFW\Core\AccessRules\AccessMode;


#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
class AccessRulesAny
{
    /**
     * Устанавливается над методом контроллера или класса,
     * показывая необходимые AccessRulesAny для вызова метода
     * @param int[] $requiredRules
     */
    public function __construct(
        public array $requiredRules = []
    ) {
    }

    public function getRequiredRules(): array
    {
        return $this->requiredRules;
    }

}