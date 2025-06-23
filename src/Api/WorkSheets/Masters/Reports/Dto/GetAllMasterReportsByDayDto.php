<?php

namespace SpsFW\Api\WorkSheets\Masters\Reports\Dto;

use Sps\DateTimeHelper;
use SpsFW\Core\Exceptions\ValidationException;
use OpenApi\Attributes as OA;

class GetAllMasterReportsByDayDto
{
    private(set) string $date {
        set (string $value) {
            $date = DateTimeHelper::try_create($value);
            if ($date) {
                $this->date = $date->format('Y-m-d');
            } else {
                throw new ValidationException('Передана невалидная дата в date');
            }
        }
    }

    private(set) ?string $master_code_1c = null;

}