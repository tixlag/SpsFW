<?php

namespace SpsFW\Core\Auth\AccessRules\Models;

use SpsFW\Core\Models\BaseModel;

abstract class UserAbstract extends BaseModel
{
    protected(set) ?string $id;
    protected(set) ?array $accessRules;
    protected(set) ?string $hashedPassword;

    public function __construct(?string $id = null, ?array $accessRules = null, ?string $hashedPassword = null)
    {
        $this->id = $id;
        $this->accessRules = $accessRules;
        $this->hashedPassword = $hashedPassword;
    }


    public function setAccessRules(array $accessRules): void
    {
        $this->accessRules = $accessRules;
    }

    public function setId(string $id): self
    {
        $this->id = $id;
        return $this;
    }

}