<?php

namespace SpsFW\Core\Validation\ValidationRules;

interface ValidationRule
{
    public function validate(mixed $value, string $propertyName): mixed;
}