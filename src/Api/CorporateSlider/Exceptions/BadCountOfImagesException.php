<?php

namespace SpsFW\Api\CorporateSlider\Exceptions;

use SpsFW\Core\Exceptions\BaseException;

class BadCountOfImagesException extends BaseException
{

    public function __construct()
    {
        parent::__construct("Картинок может быть 0, 1 или 4. Другое количество неприемлемо");
    }
}