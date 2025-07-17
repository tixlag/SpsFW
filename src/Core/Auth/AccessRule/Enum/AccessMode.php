<?php

namespace SpsFW\Core\Auth\AccessRule\Enum;

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
