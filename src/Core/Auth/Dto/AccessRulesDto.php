<?php

namespace SpsFW\Core\Auth\Dto;


use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AccessRuleDto',
)]
class AccessRulesDto implements \JsonSerializable
{
    #[OA\Property(property: 'id', required: [true], type: 'integer')]
    private(set) int $id;

    #[OA\Property(property: 'value', required: [false], type: 'array')]
    private(set) ?array $value;


    public function jsonSerialize(): mixed
    {
        return [
            'id' => $this->id,
            'value' => $this->value ?? [],
        ];
    }

    public function toArray(): array
    {
        return [
            $this->id => $this->value ?? [],
        ];
    }
}