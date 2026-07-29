<?php

declare(strict_types=1);

/**
 * Step 9.5 fixture for {@see \SpsFW\Core\Compile\Introspection\JsonSerializeShapeAnalyzerTest}: one class per
 * jsonSerialize() pattern. The analyzer locates each method by FILE + line range and projects its wire key set
 * statically. Only the analyzer reads these (no routing / no schema eligibility assumed).
 */

namespace SpsFW\Tests\Compile\Introspection\Fixtures;

class JsLitDto implements \JsonSerializable
{
    public string $id = '';
    public string $name = '';
    public \DateTime $createdAt;
    public ?\DateTime $flag = null;

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'created' => $this->createdAt->format('Y-m-d'),
            'maybe' => $this->flag?->format('Y-m-d'),
            'kind' => 'user',
            'count' => 5,
            'ratio' => 1.5,
            'active' => true,
            'nothing' => null,
        ];
    }
}

class JsParentDto implements \JsonSerializable
{
    public string $base = '';

    public function jsonSerialize(): array
    {
        return ['base' => $this->base];
    }
}

class JsChildDto extends JsParentDto
{
    public string $extra = '';

    public function jsonSerialize(): array
    {
        $result = parent::jsonSerialize();
        $result += ['extra' => $this->extra];
        return $result;
    }
}

class JsChildPlusDto extends JsParentDto
{
    public string $extra2 = '';

    public function jsonSerialize(): array
    {
        return parent::jsonSerialize() + ['extra2' => $this->extra2];
    }
}

class JsMergeDto extends JsParentDto
{
    public string $merged = '';

    public function jsonSerialize(): array
    {
        return array_merge(parent::jsonSerialize(), ['merged' => $this->merged]);
    }
}

class JsSpreadDto extends JsParentDto
{
    public string $spread = '';

    public function jsonSerialize(): array
    {
        return [...parent::jsonSerialize(), 'spread' => $this->spread];
    }
}

class JsEntity implements \JsonSerializable
{
    public string $entityId = '';

    public function jsonSerialize(): array
    {
        return ['entity_id' => $this->entityId];
    }
}

class JsDelegateDto implements \JsonSerializable
{
    public JsEntity $entity;

    public function jsonSerialize(): array
    {
        return $this->entity->jsonSerialize();
    }
}

class JsDynamicDto implements \JsonSerializable
{
    public string $id = '';

    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}

class JsCondDto implements \JsonSerializable
{
    public bool $flag = false;
    public string $id = '';

    public function jsonSerialize(): array
    {
        $result = ['id' => $this->id];
        if ($this->flag) {
            $result['extra'] = 'yes';
        }
        return $result;
    }
}

class JsOpaqueValueDto implements \JsonSerializable
{
    public string $ok = '';

    public function compute(): string
    {
        return 'x';
    }

    public function jsonSerialize(): array
    {
        return ['ok' => $this->ok, 'bad' => $this->compute()];
    }
}

/**
 * §8 sensitive-field guard: a serializer that (wrongly) returns a credential field. The OUTPUT projection must
 * DROP `password` and surface a compile ERROR — credentials never reach a response schema, even when provable.
 */
class JsCredentialDto implements \JsonSerializable
{
    public string $login = '';
    public string $password = '';
    public string $hashedPassword = '';

    public function jsonSerialize(): array
    {
        return [
            'login' => $this->login,
            'password' => $this->password,
            'hashedPassword' => $this->hashedPassword,
        ];
    }
}
