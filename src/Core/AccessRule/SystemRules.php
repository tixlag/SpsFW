<?php
namespace SpsFW\Core\AccessRule;

class SystemRules extends BaseAccessRules
{
    public const RULES = [
        300 => "Управление лимитами трафика (Radius)",
        301 => "Работа с обращениями",
        302 => "Контроль документов (QR)",
        303 => "Редактор новостей",
        304 => "Выдача сигарет",
        305 => "Контроль билетов",
    ];

    // Константы
    public const RADIUS_TRAFFIC_LIMIT = 300;
    public const FEEDBACK_MANAGER = 301;
    public const DOCUMENT_CONTROLLER = 302;
    public const EDITOR = 303;
    public const SALE_CIGARETTE = 304;
    public const TICKETS_CONTROL = 305;

    public static function getRules(): array
    {
        return self::RULES;
    }

    public static function getPrefix(): string
    {
        return 'SYSTEM';
    }
}
