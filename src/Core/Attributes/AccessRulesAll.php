<?php

namespace SpsFW\Core\Attributes;

use Attribute;


#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
class AccessRulesAll
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