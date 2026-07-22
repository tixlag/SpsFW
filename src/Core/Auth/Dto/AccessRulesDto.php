<?php

namespace SpsFW\Core\Auth\Dto;


use OpenApi\Attributes as OA;
use SpsFW\Core\Attributes\OpenApi\Items;

#[OA\Schema(
    schema: 'AccessRuleDto',
)]
class AccessRulesDto implements \JsonSerializable
{
    #[OA\Property(property: 'id', required: [true], type: 'integer')]
    private(set) int $id;

    /** Opaque per-rule value payload (no fixed element shape); encoded as a list of free-form objects. */
    #[Items(type: 'object')]
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