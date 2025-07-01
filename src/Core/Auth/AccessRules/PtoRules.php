<?php
namespace SpsFW\Core\Auth\AccessRules;

class PtoRules extends BaseAccessRules
{
    public const string ROLE = 'PTO';
    public const array RULES = [
        200 => "Доступ к разделу \"ПТО\" цифрового звена",
        201 => "Доступ к разделу \"ПТО\" цифрового звена и подтверждение",
        202 => "Разрешить редактирование объемов задач в ПТО",
        203 => "Разрешить создание задач в ПТО",
        204 => "Разрешить редактирование кодов задач в ПТО",
        205 => "Разрешить полный доступ к ПТО",
        206 => "Разрешить обновление кода задачи в ПТО",
        207 => "Возможность \"ПТО\" ставить лайки/дизлайки за смену мастеру",
        208 => "Разрешить доступ к разделам на всех объектах",
        209 => "Разрешить редактирование объемов на всех участках",
        210 => "Доступ к разделу ПТО на выбранных участках",
    ];

    public const int DIGITAL_LINK_PTO_ACCESS = 200;
    public const int DIGITAL_LINK_PTO_ACCESS_AND_CONFIRM = 201;
    public const int DIGITAL_LINK_PTO_EDIT_TASK_VOLUME = 202;
    public const int DIGITAL_LINK_PTO_CREATE_TASKS = 203;
    public const int DIGITAL_LINK_PTO_EDIT_TASK_CODE = 204;
    public const int DIGITAL_LINK_PTO_FULL = 205;
    public const int DIGITAL_LINK_PTO_UPDATE_TASK_CODE = 206;
    public const int DIGITAL_LINK_PTO_LIKE_DISLIKE = 207;
    public const int DIGITAL_LINK_PTO_ALL_LOCATION_ACCESS = 208;
    public const int DIGITAL_LINK_PTO_ALLOW_VOLUME_EDIT = 209;
    public const int DIGITAL_LINK_PTO_ALLOWED_LOCATIONS = 210;

}
