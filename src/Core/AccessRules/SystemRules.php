<?php
namespace SpsFW\Core\AccessRules;

class SystemRules extends BaseAccessRules
{
    public const string ROLE = 'SYSTEM';
    public const array RULES = [
        300 => "Управление лимитами трафика (Radius)",
        301 => "Работа с обращениями",
        302 => "Контроль документов (QR)",
        303 => "Редактор новостей",
        304 => "Выдача сигарет",
        305 => "Контроль билетов",
    ];

    public const int RADIUS_TRAFFIC_LIMIT = 300;
    public const int FEEDBACK_MANAGER = 301;
    public const int DOCUMENT_CONTROLLER = 302;
    public const int EDITOR = 303;
    public const int SALE_CIGARETTE = 304;
    public const int TICKETS_CONTROL = 305;

}
