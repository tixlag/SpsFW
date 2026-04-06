<?php

namespace SpsFW\Core\Attributes;

use Attribute;

/**
 * Ограничивает количество запросов к методу или контроллеру.
 *
 * Примеры:
 *   #[RateLimit(requests: ['network' => 60, 'fingerprint' => 20])]   // anonymous: IP + IP:UA
 *   #[RateLimit(requests: ['user' => 120, 'fingerprint' => 30])]     // authorized: UUID + IP:UA
 *   #[RateLimit(
 *       requests: ['network' => 10],
 *       whitelistRequests: ['network' => 100],
 *       whitelistIps: ['10.0.0.10']
 *   )]
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
readonly class RateLimit
{
    /**
     * Пустой массив означает "взять значения из глобального RateLimitMiddleware".
     *
     * Поддерживаемые ключи:
     * - network
     * - fingerprint
     * - user
     *
     * @param array{network?: int, fingerprint?: int, user?: int} $requests
     * @param array{network?: int, fingerprint?: int, user?: int} $whitelistRequests
     */
    public function __construct(
        public array $requests = [],
        public array $whitelistRequests = [],
        public ?int $window = null,
        public ?string $prefix = null,
        public array $whitelistIps = [],
    ) {}
}
