<?php

namespace SpsFW\Api\CorporateSlider\Model\Dto\Update;

use OpenApi\Attributes as OA;

#[OA\Schema(description: "DTO для слайда")]
class SlideUpdateDto
{
    #[OA\Property(description: "Uuid слайда", example: 'uuid')]
    public int $slide_uuid;

    #[OA\Property(description: "Позиция слайда", example: 0)]
    public int $sort;

    /** @var ImageUpdateDto[] */
    #[OA\Property(
        description: "Изображения слайда. Либо 1, либо 4",
        type: "array",
        items: new OA\Items(ref: '#/components/schemas/ImageUpdateDto'),
        nullable: true,

    )]
    public array $images;
}