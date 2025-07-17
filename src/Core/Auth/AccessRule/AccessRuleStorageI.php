<?php

namespace SpsFW\Core\Auth\AccessRule;

interface AccessRuleStorageI
{
    /**
     * @param string $userUuid
     * @return array<int, mixed>
     */
    public function extractAccessRules(string $userUuid): array;

    public function addAccessRules(string $userUuid, array $accessRules): bool;

    public function setAccessRules(string $userUuid, array $accessRules): bool;
}