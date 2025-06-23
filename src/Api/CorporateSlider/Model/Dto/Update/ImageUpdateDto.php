<?php

namespace SpsFW\Api\CorporateSlider\Model\Dto\Update;

use OpenApi\Attributes as OA;

#[OA\Schema(description: "DTO для изображения")]
class ImageUpdateDto
{

    #[OA\Property(description: "Uuid изображения", example: 'uuid')]
    public ?int $image_uuid;

    #[OA\Property(description: "Позиция изображения", example: 0)]
    public int $sort;

    #[OA\Property(description: "Файл изображения", format: "binary")]
    public string $file;
}