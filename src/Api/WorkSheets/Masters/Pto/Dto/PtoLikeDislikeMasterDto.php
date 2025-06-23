<?php

namespace SpsFW\Api\WorkSheets\Masters\Pto\Dto;

use Sps\DateTimeHelper;
use SpsFW\Core\Exceptions\ValidationException;
use OpenApi\Attributes as OA;

#[OA\Schema(
    description: 'Json для установки лайка/дизлайка мастеру за день от ПТО'
)]
class PtoLikeDislikeMasterDto
{
    #[OA\Property(
        property: "date",
        required: [true],
        type: "string",
        format: "date",
        example: "2025-06-05"
    )]
    private(set) string $date;

    #[OA\Property(
        property: "master_code_1c",
        required: [true],
        type: "string",
        example: "УП00024589"
    )]
    private(set) string $master_code_1c;

    #[OA\Property(
        property: "score",
        description: "1 - лайк, 0 - дизлайк",
        type: "integer",
        example: "1"
    )]
    private(set) int $score;

}