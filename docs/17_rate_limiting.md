# 17. Rate Limiting (ограничение частоты запросов)

## Зачем

При избыточной нагрузке на API нужно возвращать `429 Too Many Requests`, а не падать или перегружать БД. SpsFW предоставляет `RateLimitMiddleware` на основе Redis, которое легко подключить через атрибут `#[RateLimit]` или глобально.

---

## Требования

- Redis (расширение `ext-redis`)
- Настроенный `RedisClient` (секция `redis` в `.env`)

---

## Стратегии идентификации

По умолчанию (`RateLimitStrategy::User`) middleware использует умную идентификаацию:

| Сценарий | Ключи | Зачем |
|----------|-------|-------|
| Авторизованный пользователь | `rl:user:{uuid}` | Персональный лимит, не зависит от IP |
| Неавторизованный | `rl:ip:{ip}` **и** `rl:ip:{ip}:ua:{hash}` | Двойная защита |

### Почему двойная проверка для неавторизованных?

Для неавторизованных запросов проверяются **два ключа одновременно**:

1. **`rl:ip:{ip}`** — общий лимит для всех запросов с этого IP. Защищает от бот-фермы с одного IP, где каждый запрос с разным User-Agent.
2. **`rl:ip:{ip}:ua:{hash}`** — лимит для конкретного User-Agent. Защищает от одного бота.

Если **хотя бы один** ключ превысил лимит — запрос блокируется.

#### Пример: брутфорс `/login`

Бот с IP `1.2.3.4` делает 100 запросов, каждый раз меняя User-Agent:
- Ключи `rl:ip:1.2.3.4:ua:hash1`, `rl:ip:1.2.3.4:ua:hash2`... — каждый по 1 запросу
- Но ключ `rl:ip:1.2.3.4` = 100 → **сработает общий лимит IP**

Легитимные пользователи с одного Wi-Fi (NAT):
- Авторизованные → `rl:user:{uuid}` — у каждого свой персональный лимит
- Неавторизованные → общий `rl:ip:{ip}` + персональный `rl:ip:{ip}:ua:{hash}`

---

## Использование через атрибут `#[RateLimit]`

Атрибут можно ставить на метод контроллера или на весь класс.

```php
use SpsFW\Core\Attributes\RateLimit;
use SpsFW\Core\Http\Response;

class AuthController
{
    // 5 попыток в 60 секунд — авторизованные по UUID, остальные — IP + UA
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

### Только по IP (обратная совместимость)

```php
use SpsFW\Core\Attributes\RateLimitStrategy;

// Считает только по IP — как было раньше
#[RateLimit(requests: 60, window: 60, strategy: RateLimitStrategy::Ip)]
public function publicEndpoint(): Response
{
    // ...
}
```

### Всегда IP + User-Agent

```php
// Даже авторизованные считаются по IP + UA
#[RateLimit(requests: 30, window: 60, strategy: RateLimitStrategy::IpAndUser)]
public function sensitiveAction(): Response
{
    // ...
}
```

### Параметры `#[RateLimit]`

| Параметр     | Тип                 | По умолчанию                    | Описание                                          |
|--------------|---------------------|---------------------------------|---------------------------------------------------|
| `requests`   | `int`               | `60`                            | Максимальное число запросов за `window`           |
| `window`     | `int`               | `60`                            | Размер скользящего окна в секундах                |
| `prefix`     | `string`            | `'rl:'`                         | Префикс Redis-ключа                               |
| `strategy`   | `RateLimitStrategy` | `RateLimitStrategy::User`       | Стратегия идентификации                           |

### `RateLimitStrategy`

| Значение       | Поведение                                                    |
|----------------|--------------------------------------------------------------|
| `User`         | Авторизованные → UUID, неавторизованные → IP + IP:UserAgent  |
| `Ip`           | Только IP (обратная совместимость)                           |
| `IpAndUser`    | Всегда IP + IP:UserAgent                                     |

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
use SpsFW\Core\Attributes\RateLimitStrategy;
use SpsFW\Core\Redis\RedisClient;

$middleware = new RateLimitMiddleware(
    maxRequests:   100,
    windowSeconds: 60,
    keyPrefix:     'rl:',
    strategy:      RateLimitStrategy::User,  // по умолчанию
    redis:         RedisClient::getInstance(), // опционально, по умолчанию singleton
);
```
