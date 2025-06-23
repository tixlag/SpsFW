<?php

namespace SpsFW\Api\WorkSheets\Masters\Characteristics\Dto;

use SpsFW\Core\Exceptions\ValidationException;
use SpsFW\Core\Validation\Dtos\StartEndDateRequestDTO;

class GetAllMasterCharacteristicsRequestDTO extends StartEndDateRequestDTO
{

    private(set) string $employee_name = "";

    private(set) string $code_task_name = "";

    private(set) int $code_task_id = 0 {
        set (string|int $value) {
            $this->code_task_id = (int)$value;

        }
    }
    private(set) array $object_ids = [] {
        set (array|string $object_ids) {
            if (!is_array($object_ids)) {
                $this->object_ids = explode(',', $object_ids);
            } else {
                throw new ValidationException('Передан невалидный массив в object_ids. Пример: "1,5,152"');
            }
        }
    }


}