<?php

namespace SpsFW\Core\Middleware;

use SpsFW\Core\Auth\Instances\Auth;
use SpsFW\Core\Exceptions\TooManyRequestsException;
use SpsFW\Core\Http\Request;
use SpsFW\Core\Http\Response;
use SpsFW\Core\Redis\RedisClient;

/**
 * Middleware для ограничения частоты запросов (rate limiting).
 *
 * Семантика ключей:
 *   - anonymous: network(IP) + fingerprint(IP + User-Agent)
 *   - authorized: user(UUID) + fingerprint(IP + User-Agent)
 *
 * Если IP входит в whitelist, используются более мягкие whitelist-лимиты.
 *
 * Подключение через #[RateLimit] или addGlobalMiddleware:
 *
 *   // Глобально:
 *   $router->addGlobalMiddleware(RateLimitMiddleware::class, [
 *       'requests' => ['network' => 300, 'fingerprint' => 120, 'user' => 600],
 *       'whitelistRequests' => ['network' => 1500, 'fingerprint' => 400, 'user' => 2000],
 *       'windowSeconds' => 60,
 *   ]);
 */
class RateLimitMiddleware implements MiddlewareInterface
{
    private RedisClient $redis;

    public function __construct(
        private readonly array $requests = [
            'network' => 60,
            'fingerprint' => 60,
            'user' => 60,
        ],
        private readonly array $whitelistRequests = [],
        private readonly int $windowSeconds = 60,
        private readonly string $keyPrefix = 'rl:',
        private readonly array $whitelistIps = [],
        ?RedisClient $redis = null,
    ) {
        $this->redis = $redis ?? RedisClient::getInstance();
    }

    public function handle(Request $request): Request
    {
        $limits = $this->resolveLimits();
        $keys = $this->resolveKeys($request, $limits);

        foreach ($keys as $bucket => $key) {
            $count = $this->redis->incrWithTtl($key, $this->windowSeconds);
            if ($count > $limits[$bucket]) {
                throw new TooManyRequestsException(
                    sprintf('Rate limit exceeded: %d requests per %d seconds', $limits[$bucket], $this->windowSeconds)
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
     * @param array{network: ?int, fingerprint: ?int, user: ?int} $limits
     * @return array<string, string>
     */
    private function resolveKeys(Request $request, array $limits): array
    {
        $keys = [];
        $ip = $this->resolveIp();
        $fingerprint = $this->buildFingerprint($request, $ip);
        $user = Auth::getOrNull();

        if ($limits['fingerprint'] !== null) {
            $keys['fingerprint'] = $this->keyPrefix . 'fingerprint:' . $fingerprint;
        }

        if ($user !== null) {
            if ($limits['user'] !== null) {
                $keys['user'] = $this->keyPrefix . 'user:' . $user->uuid;
            }
            return $keys;
        }

        if ($limits['network'] !== null) {
            $keys['network'] = $this->keyPrefix . 'network:' . $ip;
        }

        return $keys;
    }

    /**
     * @return array{network: ?int, fingerprint: ?int, user: ?int}
     */
    private function resolveLimits(): array
    {
        $isWhitelistedIp = in_array($this->resolveIp(), $this->whitelistIps, true);

        return [
            'network' => $isWhitelistedIp
                ? ($this->whitelistRequests['network'] ?? ($this->requests['network'] ?? null))
                : ($this->requests['network'] ?? null),
            'fingerprint' => $isWhitelistedIp
                ? ($this->whitelistRequests['fingerprint'] ?? ($this->requests['fingerprint'] ?? null))
                : ($this->requests['fingerprint'] ?? null),
            'user' => $isWhitelistedIp
                ? ($this->whitelistRequests['user'] ?? ($this->requests['user'] ?? null))
                : ($this->requests['user'] ?? null),
        ];
    }

    private function resolveIp(): string
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

    private function buildFingerprint(Request $request, string $ip): string
    {
        return $ip . ':ua:' . hash('sha256', $this->resolveUserAgent($request));
    }

    private function resolveUserAgent(Request $request): string
    {
        return $request->getHeader('User-Agent') ?? $request->getHeader('user-agent') ?? '';
    }
}
