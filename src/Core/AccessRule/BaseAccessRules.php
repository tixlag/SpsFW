<?php
namespace SpsFW\Core\AccessRule;

abstract class BaseAccessRules
{
    /**
     * Получить все правила группы
     */
    abstract public static function getRules(): array;

    /**
     * Получить префикс для констант
     */
    abstract public static function getPrefix(): string;

    /**
     * Получить описание правила по ID
     */
    public static function getRuleLabel(int $ruleId): ?string
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
}
