<?php

namespace SpsFW\Core\AccessRules\Dto;


use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AccessRulesDto',
)]
class AccessRulesDto implements \JsonSerializable
{
    #[OA\Property(property: 'id', type: 'integer')]
    private(set) int $id;

    #[OA\Property(property: 'value', type: 'array')]
    private(set) array $value;


    public function jsonSerialize(): mixed
    {
        return [
            'id' => $this->id,
            'value' => $this->value,
        ];
    }

    public function toArray(): array
    {
        return [
            $this->id => $this->value,
        ];
    }
}