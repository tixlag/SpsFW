# 17. Rate Limiting (ограничение частоты запросов)

## Зачем

При избыточной нагрузке на API нужно возвращать `429 Too Many Requests`, а не падать или перегружать БД. SpsFW предоставляет `RateLimitMiddleware` на основе Redis, которое можно подключить глобально или через атрибут `#[RateLimit]`.

---

## Требования

- Redis (расширение `ext-redis`)
- Настроенный `RedisClient` (секция `redis` в `.env`)

---

## Текущая семантика лимитов

Фреймворк использует NAT-friendly схему:

| Сценарий | Проверяемые bucket'ы | Зачем |
|----------|----------------------|-------|
| Неавторизованный запрос | `network(IP)` + `fingerprint(IP + User-Agent)` | Общая защита от всплеска с IP и грубое разделение клиентов за NAT |
| Авторизованный запрос | `user(UUID)` + `fingerprint(IP + User-Agent)` | Персональный лимит пользователя без влияния общего IP |

### Что означают ключи

- `network` — общий лимит на IP. Используется только для anonymous-запросов.
- `fingerprint` — лимит на связку `IP + User-Agent`. Это не идентификатор человека, а coarse fingerprint.
- `user` — лимит на `UUID` авторизованного пользователя.

Если превышен хотя бы один активный bucket, middleware выбрасывает `TooManyRequestsException`, и Router возвращает `429`.

### Почему `IP + User-Agent` не считается идентификатором пользователя

- одинаковый браузер у многих пользователей за одним NAT даст одинаковый `User-Agent`
- `User-Agent` легко подделать
- поэтому `fingerprint` нужен только как дополнительный слой rate limit, а не как user identity

---

## Whitelist IP

Для доверенных IP можно задать более мягкие лимиты через отдельный whitelist-профиль.

- whitelist не отключает rate limit полностью
- если IP входит в whitelist, используются `whitelist`-лимиты
- глобальный whitelist задаётся в `addGlobalMiddleware(...)`
- `whitelistIps` в `#[RateLimit]` дополняет глобальный список, без дублей

---

## Использование через атрибут `#[RateLimit]`

Атрибут можно ставить на класс контроллера и на отдельный метод.

```php
use SpsFW\Core\Attributes\RateLimit;
use SpsFW\Core\Http\Response;

class AuthController
{
    #[RateLimit(
        requests: ['network' => 5, 'fingerprint' => 3],
        window: 60,
        prefix: 'rl:login:'
    )]
    public function login(): Response
    {
        // ...
    }
}
```

```php
#[RateLimit(
    requests: ['network' => 300, 'fingerprint' => 120, 'user' => 600],
    whitelistRequests: ['network' => 1500, 'fingerprint' => 400, 'user' => 2000],
    whitelistIps: ['10.0.0.10']
)]
class ProductController
{
    public function list(): Response { /* ... */ }
    public function get(): Response  { /* ... */ }
}
```

### Параметры `#[RateLimit]`

| Параметр | Тип | По умолчанию | Описание |
|----------|-----|--------------|----------|
| `requests` | `array{network?: int, fingerprint?: int, user?: int}` | `[]` | Базовые лимиты. Пустой массив значит "взять global defaults" |
| `whitelistRequests` | `array{network?: int, fingerprint?: int, user?: int}` | `[]` | Более мягкие лимиты для whitelist IP |
| `window` | `?int` | `null` | Размер окна. `null` значит взять глобальное значение |
| `prefix` | `?string` | `null` | Префикс Redis-ключей. `null` значит взять глобальное значение |
| `whitelistIps` | `string[]` | `[]` | IP-адреса, которые нужно добавить к глобальному whitelist |

### Правило merge

Для route-level limiter параметры вычисляются так:

1. global middleware defaults
2. `#[RateLimit]` на классе
3. `#[RateLimit]` на методе

Поля из более узкого уровня переопределяют более широкий. `whitelistIps` всегда объединяется, а не заменяется.

Если какой-то ключ в `requests` или `whitelistRequests` не передан, используется значение из глобального `RateLimitMiddleware`.

---

## Глобальное подключение

Чтобы применить limiter ко всем маршрутам:

```php
use SpsFW\Core\Middleware\RateLimitMiddleware;

$router->addGlobalMiddleware(RateLimitMiddleware::class, [
    'windowSeconds' => 60,

    'requests' => ['network' => 300, 'fingerprint' => 120, 'user' => 600],
    'whitelistRequests' => ['network' => 1500, 'fingerprint' => 400, 'user' => 2000],

    'whitelistIps' => [
        '10.0.0.10',
        '10.0.0.11',
    ],

    'keyPrefix' => 'rl:',
]);
```

### Как это работает вместе с атрибутами

- global middleware задаёт defaults для всего приложения
- `#[RateLimit]` на классе переопределяет defaults для контроллера
- `#[RateLimit]` на методе переопределяет class-level настройки
- `whitelistIps` из атрибутов дополняет global whitelist

---

## Поведение при превышении лимита

При превышении выбрасывается `TooManyRequestsException` (HTTP 429). Router автоматически превращает его в JSON-ответ:

```json
{
  "error": "Rate limit exceeded: 60 requests per 60 seconds"
}
```

---

## Определение IP клиента

Middleware проверяет источники IP в порядке приоритета:

1. `X-Forwarded-For` (первый IP из цепочки)
2. `X-Real-IP`
3. `REMOTE_ADDR`

При использовании nginx или балансировщика убедитесь, что эти заголовки проксируются корректно.

---

## Конструктор `RateLimitMiddleware`

Если используете middleware напрямую:

```php
use SpsFW\Core\Middleware\RateLimitMiddleware;
use SpsFW\Core\Redis\RedisClient;

$middleware = new RateLimitMiddleware(
    requests: ['network' => 300, 'fingerprint' => 120, 'user' => 600],
    whitelistRequests: ['network' => 1500, 'fingerprint' => 400, 'user' => 2000],
    windowSeconds: 60,
    keyPrefix: 'rl:',
    whitelistIps: ['10.0.0.10'],
    redis: RedisClient::getInstance(),
);
```

---

## Ограничение fallback-режима

Для Redis `RedisClient::incrWithTtl()` теперь выполняется атомарно через Lua.

Если Redis недоступен и активируется FileCache-fallback, rate limit продолжает работать, но уже без строгих конкурентных гарантий. Это аварийный degraded mode, а не полноценная замена Redis для high-load rate limiting.
