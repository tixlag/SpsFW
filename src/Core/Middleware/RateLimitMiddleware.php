<?php

namespace SpsFW\Core\Middleware;

use SpsFW\Core\Exceptions\TooManyRequestsException;
use SpsFW\Core\Http\Request;
use SpsFW\Core\Http\Response;
use SpsFW\Core\Redis\RedisClient;

/**
 * Middleware для ограничения частоты запросов (rate limiting) по IP.
 *
 * Использует Redis INCR + EXPIRE (через incrWithTtl).
 * При превышении лимита бросает TooManyRequestsException (HTTP 429).
 *
 * Подключение через #[RateLimit] или addGlobalMiddleware:
 *
 *   // Через атрибут (рекомендуется):
 *   #[RateLimit(requests: 30, window: 60)]
 *   public function someAction(): Response { ... }
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
        private readonly int    $maxRequests   = 60,
        private readonly int    $windowSeconds = 60,
        private readonly string $keyPrefix     = 'rl:ip:',
        ?RedisClient            $redis         = null,
    ) {
        $this->redis = $redis ?? RedisClient::getInstance();
    }

    public function handle(Request $request): Request
    {
        $ip  = $this->resolveIp($request);
        $key = $this->keyPrefix . $ip;

        $count = $this->redis->incrWithTtl($key, $this->windowSeconds);

        if ($count > $this->maxRequests) {
            throw new TooManyRequestsException(
                sprintf('Rate limit exceeded: %d requests per %d seconds', $this->maxRequests, $this->windowSeconds)
            );
        }

        return $request;
    }

    public function after(Response $response): Response
    {
        return $response;
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
}
