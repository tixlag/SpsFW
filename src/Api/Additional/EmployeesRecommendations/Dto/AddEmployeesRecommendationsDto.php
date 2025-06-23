<?php

namespace SpsFW\Api\Additional\EmployeesRecommendations\Dto;

use OpenApi\Attributes as OA;
use SpsFW\Core\Exceptions\ValidationException;

#[OA\Schema(
    description: "DTO для создания рекомендации сотруднику"
)]
class AddEmployeesRecommendationsDto
{
    #[OA\Property(
        property: "employee_code_1c",
        required: [true],
        type: "string",
        example: "УП00035198"
    )]
    private(set) string $employee_code_1c;

    #[OA\Property(
        property: "leadership_score",
        required: [true],
        type: "integer",
        maximum: 5,
        minimum: 0,
        example: 5
    )]
    private(set) int $leadership_score;

    #[OA\Property(
        property: "skill_score",
        required: [true],
        type: "integer",
        maximum: 5,
        minimum: 0,
        example: 5
    )]
    private(set) int $skill_score;

    #[OA\Property(
        property: "comment",
        required: [true],
        type: "string",
        minLength: 30,
        example: "Это пример комментария, который содержит более 30 символов и соответствует требованиям."
    )]
    private(set) string $comment;

    /**
     * Устанавливаем из сессии. Запрещено принимать из параметров запроса
     * @var string
     */
    private(set) string $recommender_code_1c = "";

    public function setRecommenderCode1c(string $value): self
    {
        $this->recommender_code_1c = $value;
        return $this;
    }
}