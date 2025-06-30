<?php

namespace SpsFW\Core\AccessRules\Models;

use SpsFW\Core\Models\BaseModel;

class AccessRule extends BaseModel
{
    const TABLE = 'access_rules';

    private string $name;
    private string $description;

}