<?php

namespace SpsFW\Core\Attributes;

use Attribute;

/**
 * Ограничивает количество запросов к методу или контроллеру.
 *
 * Примеры:
 *   #[RateLimit(requests: 60, window: 60)]                          // 60 req/min, авторизованные по UUID, остальные — IP + UA
 *   #[RateLimit(requests: 5, window: 1, prefix: 'rl:login:')]       // 5 req/sec на login
 *   #[RateLimit(requests: 100, window: 60, strategy: RateLimitStrategy::Ip)] // 100 req/min только по IP
 *   #[RateLimit(requests: 30, window: 60, strategy: RateLimitStrategy::IpAndUser)] // всегда IP + UA
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
readonly class RateLimit
{
    /**
     * @param int              $requests Максимальное количество запросов за окно
     * @param int              $window   Размер окна в секундах
     * @param string           $prefix   Префикс Redis-ключа (по умолчанию 'rl:')
     * @param RateLimitStrategy $strategy Стратегия идентификации (по умолчанию User)
     */
    public function __construct(
        public int              $requests = 60,
        public int              $window   = 60,
        public string           $prefix   = 'rl:',
        public RateLimitStrategy $strategy = RateLimitStrategy::User,
    ) {}
}
