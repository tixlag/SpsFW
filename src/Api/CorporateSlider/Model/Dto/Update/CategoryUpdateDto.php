<?php

namespace SpsFW\Api\CorporateSlider\Model\Dto\Update;

use OpenApi\Attributes as OA;

#[OA\Schema(description: "DTO категории")]
class CategoryUpdateDto
{
    #[OA\Property(description: "Название категории", example: "Новая категория")]
    public string $category_name;

    #[OA\Property(description: "Json строка с массивом uuids слайдеров для удаления", type: "string", example: '["uuid1", "uuid2"]')]
    public string $slide_uuids_to_remove;


    /** @var SlideUpdateDto[] */
    #[OA\Property(
        description: "Слайды категории",
        type: "array",
        items: new OA\Items(ref: '#/components/schemas/SlideUpdateDto'),
        nullable: true,

    )]
    public array $slides;
}

