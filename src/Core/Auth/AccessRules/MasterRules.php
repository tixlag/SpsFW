<?php
namespace SpsFW\Core\Auth\AccessRules;

class MasterRules extends BaseAccessRules
{
    public const string ROLE = 'MASTER';
    public const array RULES = [
        100 => "Доступ к разделу \"Мастера\" цифрового звена",
        101 => "Доступ к звеньям на участках (раздел мастера)",
        102 => "Доступ к дополнительным звеньям (раздел мастера)",
        103 => "Поиску звеньев по мастеру или по старшему звена на выбранных участках",
        104 => "Создание и редактирование задач для выбранных участков",
        105 => "Разрешить отбор (фильтр) по выбранным участкам (Мастер)",
        106 => "Максимальное количество часов (в сутки) в звеньях доступных мастеру",
        107 => "% на который мастер может изменить количество часов сотрудника в смене",
    ];

    public const int DIGITAL_LINK_MASTER_SECTION = 100;
    public const int DIGITAL_LINK_MASTER_ALLOWED_LOCATIONS = 101;
    public const int DIGITAL_LINK_MASTER_ALLOWED_LINKS = 102;
    public const int DIGITAL_LINK_MASTER_ALLOW_SEARCH = 103;
    public const int DIGITAL_LINK_MASTER_ALLOW_MANAGE_TASKS = 104;
    public const int DIGITAL_LINK_MASTER_ALLOW_FILTER = 105;
    public const int DIGITAL_LINK_MASTER_MAX_HOUSE = 106;
    public const int DIGITAL_LINK_MASTER_MAX_HOUSE_PERCENT = 107;

}
