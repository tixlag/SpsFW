<?php

namespace SpsFW\Api\CorporateSlider\Model\Dto\Response;

use OpenApi\Attributes as OA;

#[OA\Schema(description: "DTO для ответа с категорией")]
class CategoryResponseDto
{
    #[OA\Property(description: "UUID категории")]
    public string $category_uuid;

    #[OA\Property(description: "Название категории")]
    public string $name;

    /** @var SlideResponseDto[] */
    #[OA\Property(
        description: "Слайды категории",
        type: "array",
        items: new OA\Items(ref: '#/components/schemas/SlideResponseDto'),
        nullable: true,

    )]
    public array $slides;
}

