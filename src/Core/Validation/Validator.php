<?php

namespace SpsFW\Core\Validation;

use OpenApi\Attributes\Property;
use ReflectionClass;
use SpsFW\Core\Exceptions\ValidationException;
use SpsFW\Core\Http\Request;
use SpsFW\Core\Utils\DateTimeHelper;
use SpsFW\Core\Validation\Enum\ParamsIn;
use Symfony\Component\Config\Definition\Exception\Exception;

class Validator
{

    public static array $attributesOpenApi = [
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
     * @param array|null $cachedRules Кэшированные правила валидации
     * @return T
     */
    public static function validate(ParamsIn $in, string $dtoClass, ?array $cachedRules = null): object
    {
        /** @var array $reqParams */
        $reqParams = Request::getInstance()->{$in->value}();

        if ($cachedRules !== null) {

            if (count($cachedRules) === 1 and array_is_list($reqParams)) {
                foreach (array_keys($cachedRules) as $key) {
                    if ($cachedRules[$key]['type'] === 'array') {
                        return self::validateDtoWithCachedRules($dtoClass, [$key => $reqParams], $cachedRules);
                    }
                }

            }

            return self::validateDtoWithCachedRules($dtoClass, $reqParams, $cachedRules);
        }

        // Fallback на старый метод, если правила не кэшированы
        return self::validateDto($dtoClass, $reqParams);
    }

    /**
     * Валидация с использованием кэшированных правил
     */
    private static function validateDtoWithCachedRules(string $dtoClass,  ?array $reqParams, array $cachedRules): object
    {
//        /** @var $dtoClass $dto */
        $dtoReflection = new ReflectionClass($dtoClass);
        $dto = $dtoReflection->newInstanceWithoutConstructor();


        foreach ($cachedRules as $propertyName => $rules) {
            $rawValue = $reqParams[$propertyName] ?? null;
            if ( $rawValue === null and (($rules['required'] ?? null) !== [true] ) or ($rules['nullable'] ?? null) === true)  {
                try {
                    self::setPropertyValue($dto, $dtoReflection, $rules['real_name'], $rules['default'] ?? null); // если не пришло значение, устанавливаем дефолтное или null
                    if (empty($reqParams)) throw new \Exception(); // бросаем, если вообще пустое тело, а мы что-то ждем
                } catch (Exception $e) {
                    throw new ValidationException("$propertyName не может быть пустым");
                }
                continue;
            }
            // Обработка вложенных объектов
            if (isset($rules['ref'])) {

                if (isset($rules['type']) && $rules['type'] === 'array') {
                    $nestedDtos = [];

                    if (!is_array($rawValue)) {
                        throw new ValidationException("$propertyName ожидает массив");
                    }
                    foreach ($rawValue as $rawNestedDto) {
                        if (!is_array($rawNestedDto)) {
                            throw new ValidationException("$propertyName ожидает массив");
                        }
                        $nestedDtos[] = self::validateDtoWithCachedRules(
                            $rules['ref'],
                            $rawNestedDto,
                            $rules['nested_rules']
                        );
                    }
                    self::setPropertyValue($dto, $dtoReflection, $rules['real_name'], $nestedDtos);
                } else {
                    $nestedDto = self::validateDtoWithCachedRules(
                        $rules['ref'],
                        $rawValue,
                        $rules['nested_rules']
                    );
                    self::setPropertyValue($dto, $dtoReflection, $rules['real_name'], $nestedDto);
                }
                continue;
            }

            // Применяем правила валидации
            $validatedValue = $rawValue;
            foreach ($rules as $ruleName => $ruleValue) {
                if (isset(self::$attributesOpenApi[$ruleName])) {
                    $validatedValue = self::validateOpenApi(
                        $propertyName,
                        $validatedValue,
                        $ruleName,
                        $ruleValue
                    );
                }
            }

            self::setPropertyValue($dto, $dtoReflection, $rules['real_name'], $validatedValue);
        }

        return $dto;
    }

    /**
     * Установка значения свойства через рефлексию
     */
    private static function setPropertyValue(object $dto, ReflectionClass $reflection, string $propertyName, mixed $value): void
    {
//        $reflection = new \ReflectionClass($dto);
        $property = $reflection->getProperty($propertyName);
        $property->setValue($dto, $value);
    }

    /**
     * Старый метод для обратной совместимости
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
                $rawValue = isset($reqParams[$propertyName]) ? $reqParams[$propertyName] : null;
                foreach ($attributesOpenApi as $attributeOpenApiKey => $attributeOpenApiValue) {
                    if ($attributeOpenApiKey === 'ref') {
                        if (isset($attributesOpenApi['type']) && $attributesOpenApi['type'] == 'array') {
                            $nestedDtos = [];
                            foreach ($rawValue as $rawNestedDto) {
                                if (!is_array($rawNestedDto)) {
                                    throw new ValidationException(
                                        "$propertyName ожидает массив"
                                    );
                                }
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

    private static $notRequired = [];

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
                if ($ruleValue[0] === true && empty($rawValue) && $rawValue !== [] && $rawValue !== 0) {
                    throw new ValidationException("Не передан обязательный параметр: $propertyName");
                } else {
                    self::$notRequired[$propertyName] = true;
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
                        if (is_bool($rawValue) || $rawValue == 1 || $rawValue == 0) {
                            return (bool)$rawValue;
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
                        if (!isset($rawValue) and self::$notRequired[$propertyName]) return null;

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
                if (mb_strlen($rawValue) > (int)$ruleValue) {
                    throw new ValidationException("Длина $propertyName максимум $ruleValue символов");
                }
                return $rawValue;
            case 'minLength':
                if (mb_strlen($rawValue) < (int)$ruleValue) {
                    throw new ValidationException("Длина $propertyName минимум $ruleValue символов");
                }
                return $rawValue;
            case 'format':
                if ($ruleValue === 'date') {
                    $date = DateTimeHelper::toUTC($rawValue);
                    return $date->format('Y-m-d');
                }
        }
        throw new ValidationException("Ошибку вызвал $propertyName проверьте параметр");
    }

}
