# 17. Rate Limiting (ограничение частоты запросов)

## Зачем

При избыточной нагрузке на API нужно возвращать `429 Too Many Requests`, а не падать или перегружать БД. SpsFW предоставляет `RateLimitMiddleware` на основе Redis, которое легко подключить через атрибут `#[RateLimit]` или глобально.

---

## ТребованияRateLimit

- Redis (расширение `ext-redis`)
- Настроенный `RedisClient` (секция `redis` в `.env`)

---

## Использование через атрибут `#[RateLimit]`

Атрибут можно ставить на метод контроллера или на весь класс.

```php
use SpsFW\Core\Attributes\RateLimit;
use SpsFW\Core\Http\Response;

class AuthController
{
    // 5 попыток в 60 секунд на один IP — для эндпоинта логина
    #[RateLimit(requests: 5, window: 60, prefix: 'rl:login:')]
    public function login(): Response
    {
        // ...
    }
}
```

```php
// Ограничение для всего контроллера: 100 запросов в минуту
#[RateLimit(requests: 100, window: 60)]
class ProductController
{
    public function list(): Response { /* ... */ }
    public function get(): Response  { /* ... */ }
}
```

### Параметры `#[RateLimit]`

| Параметр   | Тип    | По умолчанию | Описание                                 |
|------------|--------|--------------|------------------------------------------|
| `requests` | `int`  | `60`         | Максимальное число запросов за `window`  |
| `window`   | `int`  | `60`         | Размер скользящего окна в секундах       |
| `prefix`   | `string` | `'rl:ip:'` | Префикс Redis-ключа                      |

Redis-ключ формируется как `{prefix}{ip}`, например `rl:ip:192.168.1.1`.

---

## Глобальное подключение

Чтобы применить ограничение ко всем маршрутам:

```php
$router->addGlobalMiddleware(\SpsFW\Core\Middleware\RateLimitMiddleware::class, [
    'maxRequests'   => 200,
    'windowSeconds' => 60,
]);
```

---

## Поведение при превышении лимита

При превышении бросается `TooManyRequestsException` (HTTP 429). Router автоматически превратит его в JSON-ответ:

```json
{
  "error": "Rate limit exceeded: 60 requests per 60 seconds"
}
```

---

## Определение IP клиента

Middleware проверяет заголовки в порядке приоритета:

1. `X-Forwarded-For` (первый IP из цепочки)
2. `X-Real-IP`
3. `REMOTE_ADDR`

При использовании nginx/балансировщика убедитесь, что `X-Forwarded-For` или `X-Real-IP` проксируются корректно.

---

## Несколько лимитов на один эндпоинт

PHP позволяет навесить несколько атрибутов одного типа — каждый создаёт отдельный middleware:

```php
// Общий лимит по IP + жёсткий лимит для конкретного ресурса
#[RateLimit(requests: 60, window: 60)]
#[RateLimit(requests: 10, window: 1, prefix: 'rl:burst:')]
public function expensiveAction(): Response
{
    // ...
}
```

---

## Конструктор `RateLimitMiddleware`

Если используете middleware напрямую (например, в тестах):

```php
use SpsFW\Core\Middleware\RateLimitMiddleware;
use SpsFW\Core\Redis\RedisClient;

$middleware = new RateLimitMiddleware(
    maxRequests:   100,
    windowSeconds: 60,
    keyPrefix:     'rl:ip:',
    redis:         RedisClient::getInstance(), // опционально, по умолчанию singleton
);
```
