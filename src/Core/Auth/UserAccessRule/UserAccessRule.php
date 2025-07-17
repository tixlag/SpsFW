<?php

namespace SpsFW\Core\Auth\UserAccessRule;

use SpsFW\Core\Db\Models\BaseModel;

class UserAccessRule extends BaseModel
{
    const TABLE = 'user_access_rules';

    readonly string $access_rule_id;
    readonly string $user_id;
    readonly string $value;

    public function __construct(string $access_rule_id, string $user_id, string $value)
    {
        $this->access_rule_id = $access_rule_id;
        $this->user_id = $user_id;
        $this->value = $value;
    }

}