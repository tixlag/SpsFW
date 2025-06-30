<?php
namespace SpsFW\Core\AccessRules;

class AccessRulesRegistry
{
    private static array $ruleGroups = [
        SystemRules::class,
        MasterRules::class,
        PtoRules::class,
    ];

    /**
     * Получить все правила из всех групп
     */
    public static function getAllRules(): array
    {
        $allRules = [];
        foreach (self::$ruleGroups as $groupClass) {
            $allRules = array_merge($allRules, $groupClass::getRules());
        }
        return $allRules;
    }

    /**
     * Получить роль правила по ID
     */
    public static function getRole(int $ruleId): ?string
    {
        foreach (self::$ruleGroups as $groupClass) {
            $label = $groupClass::getRole($ruleId);
            if ($label !== null) {
                return $label;
            }
        }
        return null;
    }

    /**
     * Получить описание правила по ID
     */
    public static function getRuleDescription(int $ruleId): ?string
    {
        foreach (self::$ruleGroups as $groupClass) {
            $label = $groupClass::getRuleDescription($ruleId);
            if ($label !== null) {
                return $label;
            }
        }
        return null;
    }

    /**
     * Получить константу правила по ID
     */
    public static function getRuleConstant(int $ruleId): ?string
    {
        foreach (self::$ruleGroups as $groupClass) {
            $label = $groupClass::getRuleConstant($ruleId);
            if ($label !== null) {
                return $label;
            }
        }
        return null;
    }

    /**
     * Получить правила определенной группы
     * @var class-string $groupClass
     */
    public static function getRulesByGroup(string $groupClass): array
    {
        if (!in_array($groupClass, self::$ruleGroups)) {
            throw new \InvalidArgumentException("Unknown rule group: {$groupClass}");
        }

        return $groupClass::getRules();
    }

    /**
     * Получить все группы правил
     */
    public static function getRuleGroups(): array
    {
        return self::$ruleGroups;
    }

}
