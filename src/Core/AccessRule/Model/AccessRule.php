<?php

namespace SpsFW\Core\AccessRule\Model;

use SpsFW\Core\Models\BaseModel;

class AccessRule extends BaseModel
{
    const TABLE = 'access_rules';

    private string $id;
    private string $name;
    private string $description;

}