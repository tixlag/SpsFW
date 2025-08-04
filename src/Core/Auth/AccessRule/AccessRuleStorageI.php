<?php

namespace SpsFW\Core\Auth\AccessRule;

interface AccessRuleStorageI
{
    /**
     * @param string $userCode1C
     * @return array<int, mixed>
     */
    public function extractAccessRules(string $userCode1C): array;

    public function addAccessRules(string $userCode1C, array $accessRules): bool;

    public function setAccessRules(string $userCode1C, array $accessRules): bool;
}