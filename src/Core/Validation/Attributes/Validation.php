<?php

namespace SpsFW\Core\Validation\Attributes;

use Attribute;
use SpsFW\Core\Validation\Enums\ParamsIn;

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Validation
{
    /**
     * Наличие атрибута над методом контроллера означает, что в параметрах запроса ждем DTO.
     * В метод контроллера
     * @param ParamsIn $where
     * @param class-string $dtoClass
     */
    public function __construct(
        readonly ParamsIn $where,
        readonly string $dtoClass,
    )
    {

    }
}