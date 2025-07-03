<?php

namespace SpsFW\Core\Auth\AccessRules;

use SpsFW\Core\Auth\AccessRules\Models\UserAbstract;

interface AccessRulesServiceI
{
    public function extractAccessRules(string $userId): array;

    public function addAccessRules(string $userId, array $accessRules): ?UserAbstract;

    public function setAccessRules(string $userId, array $accessRules): ?UserAbstract;
}