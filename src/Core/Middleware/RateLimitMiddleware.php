<?php

namespace SpsFW\Core\Middleware;

use SpsFW\Core\Attributes\RateLimitStrategy;
use SpsFW\Core\Auth\Instances\Auth;
use SpsFW\Core\Exceptions\TooManyRequestsException;
use SpsFW\Core\Http\Request;
use SpsFW\Core\Http\Response;
use SpsFW\Core\Redis\RedisClient;

/**
 * Middleware для ограничения частоты запросов (rate limiting).
 *
 * Стратегии идентификации (RateLimitStrategy):
 *   - User (по умолчанию): авторизованные — по UUID, неавторизованные — IP + IP:UserAgent
 *   - Ip: только по IP (обратная совместимость)
 *   - IpAndUser: всегда IP + IP:UserAgent
 *
 * Для неавторизованных проверяются ДВА ключа одновременно:
 *   1. rl:ip:{ip} — общий лимит для всех запросов с этого IP
 *   2. rl:ip:{ip}:ua:{hash} — лимит для конкретного User-Agent
 *   Если хотя бы один превышен — блокировка.
 *
 * Подключение через #[RateLimit] или addGlobalMiddleware:
 *
 *   // Через атрибут (рекомендуется):
 *   #[RateLimit(requests: 30, window: 60)]
 *   public function someAction(): Response { ... }
 *
 *   // Только по IP:
 *   #[RateLimit(requests: 100, window: 60, strategy: RateLimitStrategy::Ip)]
 *
 *   // Глобально:
 *   $router->addGlobalMiddleware(RateLimitMiddleware::class, [
 *       'maxRequests'   => 100,
 *       'windowSeconds' => 60,
 *   ]);
 */
class RateLimitMiddleware implements MiddlewareInterface
{
    private RedisClient $redis;

    public function __construct(
        private readonly int               $maxRequests   = 60,
        private readonly int               $windowSeconds = 60,
        private readonly string            $keyPrefix     = 'rl:',
        private readonly RateLimitStrategy $strategy      = RateLimitStrategy::User,
        ?RedisClient                       $redis         = null,
    ) {
        $this->redis = $redis ?? RedisClient::getInstance();
    }

    public function handle(Request $request): Request
    {
        $keys = $this->resolveKeys($request);

        // Инкрементим все ключи и проверяем лимит
        foreach ($keys as $key) {
            $count = $this->redis->incrWithTtl($key, $this->windowSeconds);
            if ($count > $this->maxRequests) {
                throw new TooManyRequestsException(
                    sprintf('Rate limit exceeded: %d requests per %d seconds', $this->maxRequests, $this->windowSeconds)
                );
            }
        }

        return $request;
    }

    public function after(Response $response): Response
    {
        return $response;
    }

    /**
     * Возвращает массив ключей для проверки.
     *
     * - User (авторизованный): ['rl:user:{uuid}']
     * - User (неавторизованный): ['rl:ip:{ip}', 'rl:ip:{ip}:ua:{hash}']
     * - Ip: ['rl:ip:{ip}']
     * - IpAndUser: ['rl:ip:{ip}', 'rl:ip:{ip}:ua:{hash}']
     */
    private function resolveKeys(Request $request): array
    {
        // Стратегия User: авторизованные — персональный лимит по UUID
        if ($this->strategy === RateLimitStrategy::User) {
            $user = Auth::getOrNull();
            if ($user !== null) {
                return [$this->keyPrefix . 'user:' . $user->uuid];
            }
            // Неавторизованный — двойная проверка
            return $this->buildIpUaKeys($request);
        }

        // Стратегия IpAndUser: всегда двойная проверка
        if ($this->strategy === RateLimitStrategy::IpAndUser) {
            return $this->buildIpUaKeys($request);
        }

        // Стратегия Ip: только IP (обратная совместимость)
        return [$this->keyPrefix . 'ip:' . $this->resolveIp($request)];
    }

    /**
     * Строит ключи для IP + IP:UserAgent.
     *
     * @return array{string, string}
     */
    private function buildIpUaKeys(Request $request): array
    {
        $ip = $this->resolveIp($request);
        $ua = $this->resolveUserAgent($request);
        $uaHash = hash('crc32b', $ua);

        return [
            $this->keyPrefix . 'ip:' . $ip,
            $this->keyPrefix . 'ip:' . $ip . ':ua:' . $uaHash,
        ];
    }

    private function resolveIp(Request $request): string
    {
        // X-Forwarded-For может содержать цепочку IP (клиент, прокси1, прокси2, ...)
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($parts[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            $ip = trim($_SERVER['HTTP_X_REAL_IP']);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    private function resolveUserAgent(Request $request): string
    {
        return $request->getHeader('User-Agent') ?? $request->getHeader('user-agent') ?? '';
    }
}
