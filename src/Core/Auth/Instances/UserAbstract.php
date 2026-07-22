<?php

namespace SpsFW\Core\Auth\Instances;

use SpsFW\Core\Attributes\OpenApi\Field;
use SpsFW\Core\Db\Models\BaseModel;

abstract class UserAbstract extends BaseModel
{
    protected(set) ?string $uuid;

    // A free-form MAP of access rules keyed by rule id (read/written as $accessRules[$ruleId]); the PHP `array`
    // type alone cannot prove that, so the object shape is declared explicitly here — never inferred.
    #[Field(objectMap: true)]
    protected(set) ?array $accessRules = [];
    protected(set) ?string $hashedPassword;

    public function __construct(?string $uuid = null, ?array $accessRules = null, ?string $hashedPassword = null)
    {
        $this->uuid = $uuid;
        $this->accessRules = $accessRules ?? [];
        $this->hashedPassword = $hashedPassword;
    }


    public function setAccessRules(array $accessRules): void
    {
        $this->accessRules = $accessRules;
    }

    public function setUuid(string $uuid): self
    {
        $this->uuid = $uuid;
        return $this;
    }

    public function addAccessRules(array $accessRules): self
    {
        $this->accessRules += $accessRules;

        return $this;
    }

}