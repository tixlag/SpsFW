<?php

namespace SpsFW\Core\Auth\AccessRules;

interface AccessRulesStorageI
{
    /**
     * @param string $userId
     * @return array<int, mixed>
     */
    public function extractAccessRules(string $userId): array;

    public function addAccessRules(string $userId, array $accessRules): bool;

    public function setAccessRules(string $userId, array $accessRules): bool;
}