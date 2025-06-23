<?php

namespace SpsFW\Api\CorporateSlider\Model\Dto\Response;

use OpenApi\Attributes as OA;

#[OA\Schema(description: "DTO для ответа со слайдом")]
class SlideResponseDto
{
    #[OA\Property(description: "UUID слайда")]
    public string $slide_uuid;

    #[OA\Property(description: "Позиция слайда")]
    public int $sort;

    /** @var ImageResponseDto[] */
    #[OA\Property(
        description: "Изображения слайда",
        type: "array",
        items: new OA\Items(ref: '#/components/schemas/ImageResponseDto'),
        nullable: true,

    )]
    public array $images;
}