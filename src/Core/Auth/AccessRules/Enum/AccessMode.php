<?php

namespace SpsFW\Core\Auth\AccessRules\Enum;

enum AccessMode
{
    /**
     * Должно примениться одно из правил
     */
    case ANY;

    /**
     * Должны примениться все правила
     */
    case ALL;

}
