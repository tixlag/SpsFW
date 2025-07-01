<?php

namespace SpsFW\Core\Auth\AccessRules;

class AccessChecker
{
    /**
     * Проверить, есть ли у пользователя определенное правило
     * @param array $userRoles
     * @param int $ruleId передаем int вида PtoRules::DIGITAL_LINK_PTO_ACCESS
     * @return bool
     */
    public static function hasRule(array $userRoles, int $ruleId): bool
    {
        return key_exists($ruleId, $userRoles);
    }

    /**
     * Проверить, есть ли у пользователя хотя бы одно из правил
     * @param array $userRoles
     * @param array $requiredRules передаем array вида [ProRules::DIGITAL_LINK_PTO_ACCESS]
     * @return bool
     */
    public static function hasAnyRule(array $userRoles, array $requiredRules): bool
    {
        return !empty(array_intersect(array_keys($userRoles), $requiredRules));
    }

    /**
     * Если нет не одной требуемое роли, вернет массив с требуемыми ролями
     * @param array $userRoles
     * @param array $requiredRules передаем array вида [ProRules::DIGITAL_LINK_PTO_ACCESS]
     * @return array
     */
    public static function getMissedRulesAnyMode(array $userRoles, array $requiredRules): array
    {
        $roles = array_intersect(array_keys($userRoles), $requiredRules);

        if (empty($roles)) {
            return array_map(
                fn($ruleId) => AccessRulesRegistry::getRole($ruleId),
                $requiredRules
            );
        }

        return [];
    }

    /**
     * Проверить, есть ли у пользователя все указанные правила
     * @param array $userRoles
     * @param array $requiredRules передаем array вида [ProRules::DIGITAL_LINK_PTO_ACCESS]
     * @return bool
     */
    public static function hasAllRules(array $userRoles, array $requiredRules): bool
    {
        return empty(array_diff($requiredRules, array_keys($userRoles)));
    }

    /**
     * Получить отсутствующие у пользователя права
     * @param array $userRoles
     * @param array $requiredRules передаем array вида [ProRules::DIGITAL_LINK_PTO_ACCESS]
     * @return array<int>
     */
    public static function getMissedRulesAllMode(array $userRoles, array $requiredRules): array
    {
        return array_map(
            fn($ruleId) => AccessRulesRegistry::getRole($ruleId),
            array_diff($requiredRules, array_keys($userRoles))
        );
    }

    /**
     * Получить правила пользователя, относящиеся к определенной группе
     * @param array $userRoles
     * @param class-string $groupClass
     * @return array
     */
    public static function getUserRulesByGroup(array $userRoles, string $groupClass): array
    {
        $groupRuleIds = $groupClass::getRuleIds();
        return array_intersect(array_keys($userRoles), $groupRuleIds);
    }
}
