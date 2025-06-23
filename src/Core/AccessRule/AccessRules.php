<?php

namespace SpsFW\Core\AccessRule;

use Attribute;
use Sps\UserAccess\AccessRulesEnum;


#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
class AccessRules
{
    /**
     * Устанавливается над методом контроллера или класса,
     * показывая необходимые AccessRules для вызова метода
     * @param enum-string<AccessRulesEnum>[] $requiredRules
     */
    public function __construct(
        public array $requiredRules = [],
        public AccessMode $accessMode = AccessMode::ALL,
    ) {
    }

    public function getRequiredRules(): array
    {
        return $this->requiredRules;
    }

    public function getAccessMode(): AccessMode
    {
        return $this->accessMode;
    }
}