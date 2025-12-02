<?php

namespace SpsFW\Core\Auth\Util;



use SpsFW\Core\Auth\Instances\Auth;
use SpsFW\Core\Exceptions\AuthorizationException;

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
                fn($ruleId) => AccessRuleRegistry::getRole($ruleId),
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
            fn($ruleId) => AccessRuleRegistry::getRole($ruleId),
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

    /**
     * Получить значение правила доступа пользователя
     * @throws AuthorizationException
     */
    public static function getValue(int $ruleId): array|null
    {
        return Auth::getOrThrow()->accessRules[$ruleId];
    }

    /**
     * @throws AuthorizationException
     */
    public static function checkAccess(mixed $access_rules_arrays): void {
        if (isset($access_rules_arrays[0]) && $access_rules_arrays[0] == 'NO_AUTH_ACCESS') {
            return;
        }
        $user = Auth::getOrThrow();

        // 402 - id правила Admin
        if (key_exists(402, $user->accessRules) ) {
            return;
        }

        // 613 - id правила забаненного
        if (key_exists(613, $user->accessRules) ) {
            throw new AuthorizationException("Ведутся технические работы", 403);
        }

        $problemRules = [];
        if (isset($access_rules_arrays['any'])) {
            foreach ($access_rules_arrays['any'] as $requiredRules) {
                if (empty($requireRules = AccessChecker::getMissedRulesAnyMode($user->accessRules, $requiredRules))) {
                    return;
                } else {
                    $problemRules[] = "Нужно хотя бы одно из правил: [" . implode('; ', $requireRules) . "]";
                }
            }
        }

        if (isset($access_rules_arrays['all'])) {
            foreach ($access_rules_arrays['all'] as $requiredRules) {
                if (empty($missedRules = AccessChecker::getMissedRulesAllMode($user->accessRules, $requiredRules))) {
                    return;
                } else {
                    $problemRules[] = "Нужны все правила доступа: [" . implode('; ', $missedRules) . "]";
                }
            }
        }
        if (count($problemRules) > 0) {
            throw new AuthorizationException(implode(PHP_EOL, $problemRules), 403);
        }
    }
}
