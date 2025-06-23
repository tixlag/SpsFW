<?php

namespace SpsFW\Api\WorkSheets\Masters\Pto\Dto;

use Sps\DateTimeHelper;
use SpsFW\Core\Exceptions\ValidationException;
use OpenApi\Attributes as OA;

#[OA\Schema(
    description: 'Json для установки комментария к лайку/дизлайку мастеру за день от ПТО'
)]
class PtoLikeDislikeMasterCommentDto
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
        property: "comment",
        required: [true],
        type: "string",
        minLength: 2,
        example: "Комментарий к оценке",
    )]
    private(set) string $comment;




}