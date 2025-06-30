<?php

namespace SpsFW\Core\AccessRules;

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
