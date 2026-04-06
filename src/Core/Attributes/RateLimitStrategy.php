<?php

namespace SpsFW\Core\Attributes;

/**
 * Стратегия идентификации для rate limiting.
 *
 * - Ip: только по IP (обратная совместимость)
 * - User: авторизованные по UUID, неавторизованные — IP + IP:UserAgent
 * - IpAndUser: всегда двойная проверка (IP + IP:UserAgent)
 */
enum RateLimitStrategy: string
{
    case Ip = 'ip';
    case User = 'user';
    case IpAndUser = 'ip_and_user';
}
