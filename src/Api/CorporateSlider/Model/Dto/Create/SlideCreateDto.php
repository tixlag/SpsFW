<?php

namespace SpsFW\Api\CorporateSlider\Model\Dto\Create;

use OpenApi\Attributes as OA;
use Sps\CorporateSlider\Model\Dto\Update\ImageUpdateDto;

#[OA\Schema(description: "DTO для слайда")]
class SlideCreateDto
{

    #[OA\Property(description: "Позиция слайда", example: 0)]
    public int $sort;

    /** @var ImageCreateDto[] */
    #[OA\Property(
        description: "Изображения слайда. Либо 1, либо 4",
        type: 'array',
        items: new OA\Items(ref: '#/components/schemas/ImageCreateDto')
    )]
    public array $images;
}