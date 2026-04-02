<?php

namespace SpsFW\Core\Attributes;

use Attribute;

/**
 * Ограничивает количество запросов к методу или контроллеру.
 *
 * Пример:
 *   #[RateLimit(requests: 60, window: 60)]          // 60 req/min по IP
 *   #[RateLimit(requests: 5, window: 1, prefix: 'rl:login:')]  // 5 req/sec
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
readonly class RateLimit
{
    /**
     * @param int    $requests Максимальное количество запросов за окно
     * @param int    $window   Размер окна в секундах
     * @param string $prefix   Префикс Redis-ключа (по умолчанию 'rl:ip:')
     */
    public function __construct(
        public int    $requests = 60,
        public int    $window   = 60,
        public string $prefix   = 'rl:ip:',
    ) {}
}
