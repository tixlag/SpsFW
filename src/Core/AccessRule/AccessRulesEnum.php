<?php

namespace SpsFW\Core\AccessRule;

enum AccessRulesEnum: int
{
    /**
     * Управление лимитами трафика (Radius)
     */
    case Sections_RadiusTrafficLimit = 0;
    /**
     * Работа с обращениями
     */
    case Sections_FeedbackManager = 1;
    /**
     * Контроль документов (QR)
     */
    case Sections_DocumentController = 2;
    /**
     * Редактор новостей
     */
    case Sections_Editor = 3;
    /**
     * Выдача сигарет
     */
    case Sections_SaleCigarette = 4;
    /**
     * Контроль билетов
     */
    case Sections_TicketsControl = 5;
    /**
     * Тестирование и опросы
     */
    case Sections_ManageTestsExams = 6;
    /**
     * Опрос улучшений рабоче среды
     */
    case Sections_SurveyWorkEnvironment = 7;
    /**
     * Отображать ФИО в опросах улучшения рабочей среды
     */
    case Sections_SurveyWorkEnvironmentShowName = 8;
    /**
     * Просмотр отчетов по Кадровому резерву
     */
    case Sections_Talents = 9;
    /**
     * Доступ к разделу ДО
     */
    case Sections_DO = 10;
    /**
     * Доступ к разделу "Подбор персонала"
     */
    case Hr_Access = 11;
    /**
     * Руководитель отдела "Подбор персонала"
     */
    case Hr_Head = 12;
    /**
     * Доступ к разделу "Инфостенд"
     */
    case InfoStand_View = 13;
    /**
     * Редактирование раздела "Инфостенд"
     */
    case InfoStand_Edit = 14;
    /**
     * Доступ к разделу "Мастера" цифрового звена
     */
    case DigitalLinkUnit_Master_Section = 15;
    /**
     * Доступ к разделу "Диспетчер" цифрового звена
     */
    case DigitalLinkUnit_Dispatcher_Section = 16;
    /**
     * Доступ к разделу "ПТО" цифрового звена
     */
    case DigitalLinkUnit_ProductionAndTechnicalDepartment_Access = 17;

    /**
     * Доступ к разделу "ПТО" цифрового звена и подтверждение
     */
    case DigitalLinkUnit_ProductionAndTechnicalDepartment_Access_and_confirm = 18;


    /**
     * Разрешить редактирование объемов задач в ПТО
     */
    case DigitalLinkUnit_ProductionAndTechnicalDepartment_Edit_task_volume = 19;

    /**
     * Разрешить создание задач в ПТО
     */
    case DigitalLinkUnit_ProductionAndTechnicalDepartment_Create_tasks = 20;

    /**
     * Разрешить редактирование кодов задач в ПТО
     */
    case DigitalLinkUnit_ProductionAndTechnicalDepartment_Edit_task_code = 21;

    /**
     * Разрешить полный доступ к ПТО
     */
    case DigitalLinkUnit_ProductionAndTechnicalDepartment_full = 22;

    case DigitalLinkUnit_ProductionAndTechnicalDepartment_update_task_code = 23;

    /**
     * Возможность "ПТО" ставить лайки/дизлайки за смену мастеру
     */
    case DigitalLinkUnit_ProductionAndTechnicalDepartment_Like_Dislike_Ability = 24;
    /**
     * Разрешить доступ к цифровым звеньям на всех участках
     */
    case DigitalLinkUnit_AllLocations = 25;
    /**
     * Разрешить сканирование постоянной карты СКУД
     */
    case DigitalLinkUnit_ScanPermanentAccessCard = 26;
    /**
     * Режим только чтения (часы/задачи/объемы и т.п.)
     */
    case DigitalLinkUnit_ReadOnlyMode = 27;
    /**
     * Администратор звеньев (возможность открывать смены, добавлять пользователей и т.п.)
     */
    case DigitalLinkUnit_LinkAdministrator = 28;
    /**
     * Доступ к звеньям на участках (раздел мастера)
     */
    case DigitalLinkUnit_Master_AllowedLocations = 29;
    /**
     * Доступ к дополнительным звеньям (раздел мастера)
     */
    case DigitalLinkUnit_Master_AllowedLinks = 30;
    /**
     * Поиску звеньев по мастеру или по старшему звена на выбранных участках
     */
    case DigitalLinkUnit_Master_AllowSearch = 31;
    /**
     * Создание и редактирование задач для выбранных участков
     */
    case DigitalLinkUnit_Master_AllowManageTasks = 32;
    /**
     * Разрешить отбор (фильтр) по выбранным участкам (Мастер)
     */
    case DigitalLinkUnit_Master_AllowFilter = 33;
    /**
     * Максимальное количество часов (в сутки) в звеньях доступных мастеру
     */
    case DigitalLinkUnit_Master_MaxHouse = 34;
    /**
     * % на который мастер может изменить количество часов сотрудника в смене
     */
    case DigitalLinkUnit_Master_MaxHouse_Percent = 35;
    /**
     * Доступ к звеньям на участках (раздел диспетчера)
     */
    case DigitalLinkUnit_Dispatcher_AllowedLocations = 36;
    /**
     * Доступ к просмотру звеньев на выбранных участках
     */
    case DigitalLinkUnit_Dispatcher_AllowViewUnits = 37;
    /**
     * Доступ к управлению звеньями на выбранных участках
     */
    case DigitalLinkUnit_Dispatcher_AllowManageUnits = 38;
    /**
     * Доступ к отбору (фильтру) по выбранным участкам (Диспетчер)
     */
    case DigitalLinkUnit_Dispatcher_AllowFilter = 39;
    /**
     * Отключить временной лимит ограничения корректировки данных в борде диспетчера (текущий месяц)
     */
    case DigitalLinkUnit_Dispatcher_CorrectionDaysLimitOff = 40;
    /**
     * Разрешить доступ к разделам на всех объектах
     */
    case DigitalLinkUnit_ProductionAndTechnicalDepartment_AllLocationAccess = 41;
    /**
     * Разрешить редактирование объемов на всех участках
     */
    case DigitalLinkUnit_ProductionAndTechnicalDepartment_AllowVolumeEdit = 42;
    /**
     * Доступ к разделу ПТО на выбранных участках
     */
    case DigitalLinkUnit_ProductionAndTechnicalDepartment_AllowedLocations = 43;
    /**
     * Администратор (доступны все функции)
     */
    case Support_Administrator = 44;
    /**
     * Чат технической поддержки
     */
    case Support_SupportChat = 45;
    /**
     * Смена пароля
     */
    case Support_ChangePassword = 46;
    /**
     * Управление правами доступа
     */
    case Support_ManageAccessRules = 47;
    /**
     * Просмотр системных журнала
     */
    case Support_JournalView = 48;


    public function getId(): int
    {
        return $this->value;
    }


    public function label(): string
    {
        return match($this) {
            self::Sections_RadiusTrafficLimit => "Управление лимитами трафика (Radius)",
            self::Sections_FeedbackManager => "Работа с обращениями",
            self::Sections_DocumentController => "Контроль документов (QR)",
            self::Sections_Editor => "Редактор новостей",
            self::Sections_SaleCigarette => "Выдача сигарет",
            self::Sections_TicketsControl => "Контроль билетов",
            self::Sections_ManageTestsExams => "Тестирование и опросы",
            self::Sections_SurveyWorkEnvironment => "Опрос улучшений рабоче среды",
            self::Sections_SurveyWorkEnvironmentShowName => "Отображать ФИО в опросах улучшения рабочей среды",
            self::Sections_DO => "Отображать раздел Документооборота",
            self::Sections_Talents => "Просмотр Кадровый резерв",
            self::Hr_Access => "Доступ к разделу \"Подбор персонала\"",
            self::Hr_Head => "Руководитель отдела \"Подбор персонала\"",
            self::InfoStand_View => "Доступ к разделу \"Инфостенд\"",
            self::InfoStand_Edit => "Редактирование раздела \"Инфостенд\"",
            self::DigitalLinkUnit_Master_Section => "Доступ к разделу \"Мастера\" цифрового звена",
            self::DigitalLinkUnit_Dispatcher_Section => "Доступ к разделу \"Диспетчер\" цифрового звена",
            self::DigitalLinkUnit_ProductionAndTechnicalDepartment_Access => "Доступ к разделу \"ПТО\" цифрового звена",
            self::DigitalLinkUnit_ProductionAndTechnicalDepartment_Like_Dislike_Ability => "Возможность \"ПТО\" ставить лайки/дизлайки за смену мастеру",
            self::DigitalLinkUnit_AllLocations => "Разрешить доступ к цифровым звеньям на всех участках",
            self::DigitalLinkUnit_ScanPermanentAccessCard => "Разрешить сканирование постоянной карты СКУД",
            self::DigitalLinkUnit_ReadOnlyMode => "Режим только чтения (часы/задачи/объемы и т.п.)",
            self::DigitalLinkUnit_LinkAdministrator => "Администратор звеньев (возможность открывать смены, добавлять пользователей и т.п.)",
            self::DigitalLinkUnit_Master_AllowedLocations => "Доступ к звеньям на участках (раздел мастера)",
            self::DigitalLinkUnit_Master_AllowedLinks => "Доступ к дополнительным звеньям (раздел мастера)",
            self::DigitalLinkUnit_Master_AllowSearch => "Поиску звеньев по мастеру или по старшему звена на выбранных участках",
            self::DigitalLinkUnit_Master_AllowManageTasks => "Создание и редактирование задач для выбранных участков",
            self::DigitalLinkUnit_Master_AllowFilter => "Разрешить отбор (фильтр) по выбранным участкам (Мастер)",
            self::DigitalLinkUnit_Master_MaxHouse => "Максимальное количество часов (в сутки) в звеньях доступных мастеру",
            self::DigitalLinkUnit_Master_MaxHouse_Percent => "% на который может изменить количество часов сотрудника в смене",
            self::DigitalLinkUnit_Dispatcher_AllowedLocations => "Доступ к звеньям на участках (раздел диспетчера)",
            self::DigitalLinkUnit_Dispatcher_AllowViewUnits => "Доступ к просмотру звеньев на выбранных участках",
            self::DigitalLinkUnit_Dispatcher_AllowManageUnits => "Доступ к управлению звеньями на выбранных участках",
            self::DigitalLinkUnit_Dispatcher_AllowFilter => "Доступ к отбору (фильтру) по выбранным участкам (Диспетчер)",
            self::DigitalLinkUnit_Dispatcher_CorrectionDaysLimitOff => "Отключить временной лимит ограничения корректировки данных в борде диспетчера (текущий месяц)",
            self::DigitalLinkUnit_ProductionAndTechnicalDepartment_AllLocationAccess => "Разрешить доступ к разделам на всех объектах",
            self::DigitalLinkUnit_ProductionAndTechnicalDepartment_AllowVolumeEdit => "Разрешить редактирование объемов на всех участках",
            self::DigitalLinkUnit_ProductionAndTechnicalDepartment_AllowedLocations => "Доступ к разделу ПТО на выбранных участках",
            self::Support_Administrator => "Администратор (доступны все функции)",
            self::Support_SupportChat => "Чат технической поддержки",
            self::Support_ChangePassword => "Смена пароля",
            self::Support_ManageAccessRules => "Управление правами доступа",
            self::Support_JournalView => "Просмотр системных журнала",
            self::DigitalLinkUnit_ProductionAndTechnicalDepartment_Access_and_confirm => "ПТО - подтверждение задач",
            self::DigitalLinkUnit_ProductionAndTechnicalDepartment_Create_tasks=> "Создание задач",
            self::DigitalLinkUnit_ProductionAndTechnicalDepartment_Edit_task_code=> "Редактирование кода задачи",
            self::DigitalLinkUnit_ProductionAndTechnicalDepartment_full=> "Полный доступ к разделу ПТО",
            self::DigitalLinkUnit_ProductionAndTechnicalDepartment_Edit_task_volume => "Редактирование объемов задач",
            self::DigitalLinkUnit_ProductionAndTechnicalDepartment_update_task_code => "Редактирование кода задачи в редакторе",
        };
    }
}
