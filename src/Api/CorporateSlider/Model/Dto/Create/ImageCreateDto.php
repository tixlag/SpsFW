<?php

namespace SpsFW\Api\CorporateSlider\Model\Dto\Create;

use OpenApi\Attributes as OA;

#[OA\Schema(description: "DTO для изображения")]
class ImageCreateDto
{
    #[OA\Property(description: "Позиция изображения", example: 0)]
    public int $sort;

    #[OA\Property(description: "Файл изображения", format: "binary", nullable: true)]
    public string $file;
}