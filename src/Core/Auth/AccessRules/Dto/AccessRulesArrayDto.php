<?php

namespace SpsFW\Core\Auth\AccessRules\Dto;

use OpenApi\Attributes as OA;

#[OA\Schema(
    description: 'Dto для выдачи прав пользователю',
    schema: 'AccessRulesArrayDto',
)]
class AccessRulesArrayDto
{

    #[OA\Property(property: 'user_id', type: 'string')]
    private(set) string $userId;
    /**
     * @var array<AccessRulesDto>
     */
    #[OA\Property(property: 'rules', ref: AccessRulesDto::class, type: 'array')]
    private(set) array $rules;

}