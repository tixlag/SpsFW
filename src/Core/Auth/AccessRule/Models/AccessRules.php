<?php

namespace SpsFW\Core\Auth\AccessRule\Models;

class AccessRules extends \SpsFW\Core\Db\Models\BaseModel
{
    const TABLE = 'access_rules';

    private int $id;
    private string $name;
    private string $description;
    private string $role;

}