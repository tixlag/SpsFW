<?php

namespace SpsFW\Core\Auth\Dto;

use OpenApi\Attributes as OA;

#[OA\Schema(
    description: 'Dto для выдачи прав пользователю',

)]
class AccessRulesArrayDto
{

    #[OA\Property(property: 'user_uuid', type: 'string')]
    private(set) string $userUuid;
    /**
     * @var array<AccessRulesDto>
     */
    #[OA\Property(property: 'rules', ref: AccessRulesDto::class, type: 'array')]
    private(set) array $rules;

}