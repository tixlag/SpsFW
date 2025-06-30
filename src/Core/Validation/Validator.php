<?php

namespace SpsFW\Core\Validation;

use OpenApi\Attributes\OpenApi;
use OpenApi\Attributes\Property;
use ReflectionAttribute;
use ReflectionClass;
use SpsFW\Core\Exceptions\ValidationException;
use SpsFW\Core\Http\Request;
use SpsFW\Core\Validation\Enums\ParamsIn;
use SpsFW\Core\Validation\ValidationRules\ValidationRule;

class Validator
{

    private static array $attributesOpenApi = [
        'required' => true,
        'type' => true,
        'minimum' => true,
        'maximum' => true,
        'minLength' => true,
        'maxLength' => true,
    ];

    /**
     * @template T of object
     * @param ParamsIn $in
     * @param class-string<T> $dtoClass
     * @return T
     */

    public static function validate(ParamsIn $in, string $dtoClass): object
    {
        /** @var array $reqParams */
        $reqParams = Request::getInstance()->{$in->value}();

        return self::validateDto($dtoClass, $reqParams);
    }

    /**
     * @param string $dtoClass
     * @param array $reqParams
     * @return mixed
     * @throws ValidationException
     * @throws \ReflectionException
     */
    private static function validateDto(string $dtoClass, array $reqParams): mixed
    {
        $dto = new $dtoClass();
        $reflection = new ReflectionClass($dto);
        $properties = $reflection->getProperties();
        /**
         * Закомментированный ниже код для кастомных атрибутов валидации
         */
//
//        //Валидация кастомными Атрибутами
//        foreach ($properties as $property) {
//            $propertyName = $property->getName();
//            $rawValue = $reqParams[$propertyName];
//            $propertyAttributes = $property->getAttributes(ValidationRule::class, ReflectionAttribute::IS_INSTANCEOF);
//
//            $isSet = false;
//            foreach ($propertyAttributes as $validationRule) {
//                $validationRuleInstance = $validationRule->newInstance();
//
//                $value = $validationRuleInstance->validate($rawValue, $propertyName);
//
//                $property->setValue($dto, $value);
//            }
//            if ($isSet) {
//                continue;
//            }
////            $property->setValue($dto, $rawValue);
//
//        }

        foreach ($properties as $property) {
            $propertyAttributes = $property->getAttributes(Property::class);

            $notSet = true; // избегаем лишних присваиваний через рефлексию

            // проходимся по аргементам OpenApi атрибута
            foreach ($propertyAttributes as $propertyAttribute) {
                $attributesOpenApi = $propertyAttribute->getArguments();
                $propertyName = $attributesOpenApi['property'] ?? $property->getName();
                $rawValue = $reqParams[$propertyName];
                foreach ($attributesOpenApi as $attributeOpenApiKey => $attributeOpenApiValue) {

                    if ($attributeOpenApiKey === 'ref') {
                        if (isset($attributesOpenApi['type']) && $attributesOpenApi['type'] == 'array') {
                            $nestedDtos = [];
                            foreach ($rawValue as $rawNestedDto) {
                                if (!is_array($rawNestedDto))
                                    throw new ValidationException(
                                        "$propertyName ожидает массив"
                                    );
                                $value = self::validateDto($attributeOpenApiValue, $rawNestedDto);
                                $nestedDtos[] = $value;
                            }
                            $property->setValue($dto, $nestedDtos);
                            break;
                        }
                        $value = self::validateDto($attributeOpenApiValue, $rawValue);
                        $property->setValue($dto, $value);
                        break;
                    }

                    // Если есть параметр для валидации, то валидируем
                    if (isset(self::$attributesOpenApi[$attributeOpenApiKey])) {
                        $value = self::validateOpenApi(
                            $propertyName,
                            $rawValue,
                            $attributeOpenApiKey,
                            $attributeOpenApiValue
                        );
                        if ($notSet || $value !== $rawValue) {
                            $property->setValue($dto, $value);
                            $notSet = false;
                        }
                    }
                }
            }
        }

        return $dto;
    }


    /**
     * @template T of object
     * @param ParamsIn $in
     * @param T $dto
     * @return T
     */

    public function validateLegacy(ParamsIn $in, $dto)
    {
        /** @var array $reqParams */
        $reqParams = Request::getInstance()->{$in->value}();

        $reflection = new ReflectionClass($dto);
        $properties = $reflection->getProperties();

        foreach ($properties as $property) {
            $propertyName = $property->getName();
            if (key_exists($propertyName, $reqParams)) {
                // $property->setAccessible(true); // Пробиваемся сквозь приватный сеттер
                $property->setValue($dto, $reqParams[$propertyName]);
            } elseif ($property->getValue($dto) === null) {
                throw new ValidationException("Не передан обязательный параметр: $propertyName");

            }
        }

        return $dto;
    }

    /**
     * @throws ValidationException
     */
    private static function validateOpenApi(
        string $propertyName,
        mixed $rawValue,
        string $ruleName,
        mixed $ruleValue
    ): mixed {
        switch ($ruleName) {
            case 'required':
                if ($ruleValue[0] === true && empty($rawValue) && $rawValue !== 0) {
                    throw new ValidationException("Не передан обязательный параметр: $propertyName");
                }
                return $rawValue;
            case 'type':
                switch ($ruleValue) {
                    case 'integer':
                        if (is_int($rawValue)) {
                            return $rawValue;
                        }
                        if (is_string($rawValue)) {
                            $trimmed = ltrim($rawValue, '-');
                            if (ctype_digit($trimmed)) {
                                return (int)$rawValue;
                            }
                        }
                        throw new ValidationException("В поле '$propertyName' ожидается целое число");
                    case 'boolean':
                        if (is_bool($rawValue)) {
                            return $rawValue;
                        }
                        throw new ValidationException("В поле '$propertyName' ожидается целое число");
                    case 'string':
                        if (!is_string($rawValue)) {
                            throw new ValidationException("В поле '$propertyName' ожидается строка");
                        }
                        return $rawValue;
                    case 'number':
                        if (!is_numeric($rawValue)) {
                            throw new ValidationException("В поле '$propertyName' ожидается число");
                        }
                        return $rawValue;
                    case 'array':
                        if (is_array($rawValue)) {
                            return $rawValue;
                        }
                        $value = json_decode($rawValue, true);
                        if (!is_array($value)) {
                            throw new ValidationException("В поле '$propertyName' ожидается массив");
                        }
                        return $value;
                }
                break;
            case 'minimum':
                if (!is_numeric($rawValue) || !is_numeric($ruleValue)) {
                    throw new ValidationException("Некорректные значения для minimum");
                }
                $value = (float)$rawValue;
                $min = (float)$ruleValue;
                if ($value < $min) {
                    throw new ValidationException("Ожидается $propertyName не меньше $ruleValue");
                }
                return $value;
            case 'maximum':
                if (!is_numeric($rawValue) || !is_numeric($ruleValue)) {
                    throw new ValidationException("Некорректные значения для maximum");
                }
                $value = (float)$rawValue;
                $max = (float)$ruleValue;
                if ($value > $max) {
                    throw new ValidationException("Ожидается $propertyName не больше $ruleValue");
                }
                return $value;
            case 'maxLength':
                if (strlen($rawValue) > (int)$ruleValue) {
                    throw new ValidationException("Длина $propertyName максимум $ruleValue символов");
                }
                return $rawValue;
            case 'minLength':
                if (strlen($rawValue) < (int)$ruleValue) {
                    throw new ValidationException("Длина $propertyName минимум $ruleValue символов");
                }
                return $rawValue;
            case 'format':
                if ($ruleValue === 'date') {
                    $date = DateTimeHelper::try_create($rawValue);
                    if ($date) {
                        return $date->format('Y-m-d');
                    } else {
                        throw new ValidationException('Передана невалидная дата в $propertyName');
                    }
                }

        }
        throw new ValidationException("Ошибку вызвал $propertyName проверьте параметр");
    }

}
