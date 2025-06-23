<?php

namespace SpsFW\Api\CorporateSlider\Model\Dto\Response;

use OpenApi\Attributes as OA;

#[OA\Schema(description: "DTO для ответа с изображением")]
class ImageResponseDto
{
    #[OA\Property(description: "UUID изображения")]
    public string $image_uuid;

    #[OA\Property(description: "Путь к изображению")]
    public string $path;

    #[OA\Property(description: "Название изображения")]
    public string $name;

    #[OA\Property(description: "Позиция изображения")]
    public int $sort;
}