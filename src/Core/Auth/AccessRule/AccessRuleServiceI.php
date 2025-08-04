<?php

namespace SpsFW\Core\Auth\AccessRule;

use SpsFW\Core\Auth\Dto\AccessRulesArrayDto;
use SpsFW\Core\Auth\Instances\UserAbstract;

interface AccessRuleServiceI
{
    public function extractAccessRules(string $userCode1C): array;

    public function addAccessRules(AccessRulesArrayDto $accessRulesDto): ?UserAbstract;

    public function setAccessRules(AccessRulesArrayDto $accessRulesDto): ?UserAbstract;
}