<?php

namespace SpsFW\Core\Validation\ValidationRules;

use SpsFW\Core\Exceptions\ValidationException;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
class Required implements ValidationRule
{

    public function validate($value, $propertyName): mixed
    {
        if (empty($value)) {
            throw new ValidationException("Поле $propertyName обязательно к заполнению");
        }
        return $value;
    }
}