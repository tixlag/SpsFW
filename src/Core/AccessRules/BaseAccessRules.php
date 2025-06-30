<?php
namespace SpsFW\Core\AccessRules;

abstract class BaseAccessRules
{
    /**
     * Получить все правила группы
     */
    public static function getRules(): array
    {
        return static::RULES;
    }

    /**
     * Получить префикс для констант
     */
    public static function getRole(): string
    {
        return static::ROLE;
    }

    /**
     * Получить описание правила по ID
     */
    public static function getRuleDescription(int $ruleId): ?string
    {
        return static::getRules()[$ruleId] ?? null;
    }

    /**
     * Получить все ID правил группы
     */
    public static function getRuleIds(): array
    {
        return array_keys(static::getRules());
    }

    public static function getRuleConstant($value): ?string
    {
        $constants = new \ReflectionClass(static::class)->getConstants();
        return array_find_key($constants, fn($constantValue) => $constantValue === $value);
    }
}
