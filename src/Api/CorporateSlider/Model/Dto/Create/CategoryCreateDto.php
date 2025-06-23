<?php

namespace SpsFW\Api\CorporateSlider\Model\Dto\Create;

use OpenApi\Attributes as OA;

#[OA\Schema( description: "DTO категории")]
class CategoryCreateDto
{

    #[OA\Property(description: "Название категории", example: "Новая категория")]
    public string $category_name;

    /** @var SlideCreateDto[] */
    #[OA\Property(
        description: "Слайды категории",
        type: 'array',
        items: new OA\Items(ref: '#/components/schemas/SlideCreateDto'),
        nullable: true
    )
    ]
    public array $slides;
}

