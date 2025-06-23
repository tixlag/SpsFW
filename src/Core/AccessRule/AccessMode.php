<?php

namespace SpsFW\Core\AccessRule;

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
