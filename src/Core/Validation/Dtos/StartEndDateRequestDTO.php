<?php

namespace SpsFW\Core\Validation\Dtos;

use Sps\DateTimeHelper;
use SpsFW\Core\Exceptions\ValidationException;

class StartEndDateRequestDTO
{

     private(set) string $date_start {
        set (string $value) {
            $date = DateTimeHelper::try_create($value);
            if ($date) {
                $this->date_start = $date->format('Y-m-d');
            } else {
                throw new ValidationException('Передана невалидная дата в start_date');
            }
        }
    }

    private(set) string $date_end {
        set (string $value) {
            $date = DateTimeHelper::try_create($value);
            if ($date) {
                $this->date_end = $date->format('Y-m-d');
            } else {
                throw new ValidationException('Передана невалидная дата в end_date');
            }
        }
    }
}