<?php

namespace SpsFW\Api\Additional\EmployeesRecommendations\Dto;


readonly class ResponseEmployeesRecommendationsDto
{
    public function __construct(
        public string $day_of_vote,
        public string $employee_name,
        public string $employee_code_1c,
        public string $employee_office,
        public string $employee_current_post,
        public float $employee_current_post_exp,
        public float $employee_common_exp,
        public string $employee_class,
        public string $leadership_score,
        public string $skill_score,
        public string $comment,
        public string $recommender_name,
        public string $recommender_code_1c,
        public string $recommender_office,
        public string $recommender_current_post,
    ) {}
}
