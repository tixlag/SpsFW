# Исключения

Router автоматически перехватывает любое исключение из контроллера и возвращает JSON-ответ с соответствующим HTTP-кодом.

---

## Иерархия

```
\Exception
└── BaseException (500)
    ├── AuthorizationException (401/403)
    ├── BadPasswordException
    ├── CantDoItAgain
    ├── ConflictException (409)        ← новый
    ├── ForbiddenException (403)       ← новый
    ├── NotFoundException (404)        ← новый
    ├── RouteNotFoundException (404)
    ├── TokenExpiredException
    ├── TooManyRequestsException (429) ← новый
    ├── UnauthorizedException (401)    ← новый
    ├── UserNotFoundException
    └── ValidationException
```

---

## Встроенные исключения

| Класс | HTTP-код | Описание |
|---|---|---|
| `BaseException` | 500 | Базовый класс |
| `AuthorizationException` | 401/403 | Ошибка авторизации |
| `BadPasswordException` | — | Неверный пароль |
| `CantDoItAgain` | — | Действие нельзя повторить |
| `ConflictException` | 409 | Конфликт данных (дубликат и т.п.) |
| `ForbiddenException` | 403 | Нет прав |
| `NotFoundException` | 404 | Ресурс не найден |
| `RouteNotFoundException` | 404 | Маршрут не найден (фреймворк) |
| `TokenExpiredException` | — | Токен истёк |
| `TooManyRequestsException` | 429 | Rate limiting |
| `UnauthorizedException` | 401 | Не аутентифицирован |
| `UserNotFoundException` | — | Пользователь не найден |
| `ValidationException` | 400 | Ошибка валидации |

---

## Использование

```php
use SpsFW\Core\Exceptions\NotFoundException;
use SpsFW\Core\Exceptions\ForbiddenException;
use SpsFW\Core\Exceptions\ConflictException;
use SpsFW\Core\Exceptions\TooManyRequestsException;
use SpsFW\Core\Exceptions\UnauthorizedException;

// 404 — ресурс не найден
$deal = $this->dealsStorage->findById($id);
if ($deal === null) {
    throw new NotFoundException("Сделка $id не найдена");
}

// 403 — нет прав на действие
if ($deal['tenant_id'] !== $currentTenantId) {
    throw new ForbiddenException();
}

// 409 — конфликт (дубликат email)
if ($this->usersStorage->existsByEmail($email)) {
    throw new ConflictException("Пользователь с email $email уже существует");
}

// 429 — rate limiting
$count = $this->redis->incrWithTtl("rl:login:{$ip}", 60);
if ($count > 5) {
    throw new TooManyRequestsException();
}

// 401 — не аутентифицирован
if (!$token) {
    throw new UnauthorizedException();
}
```

---

## Поведение Router

**`BaseException` и потомки** → Router возвращает JSON с кодом исключения:
```json
{
  "error": {
    "status": 404,
    "uri": "/api/deals/unknown-id",
    "message": "Сделка unknown-id не найдена",
    "trace": []
  }
}
```

**Любой другой `\Throwable`** → Router логирует stack trace, возвращает 500 без деталей:
```json
{ "error": { "status": 500, "message": "Internal server error" } }
```

В **dev-режиме** (`DEBUG_MODE=true`) ответ содержит `exception`, `file`, `line`, `trace`.
В **prod-режиме** (`DEBUG_MODE=false`) эти поля скрыты — stack trace только в логах.

---

## Собственные исключения

```php
namespace App\Exceptions;

use SpsFW\Core\Exceptions\BaseException;

// Исключение со своим кодом
class PaymentDeclinedException extends BaseException
{
    public function __construct(string $reason = 'Payment declined')
    {
        parent::__construct($reason, 402);
    }
}

// Исключение с дополнительными данными
class ValidationFailedException extends BaseException
{
    public function __construct(
        public readonly array $errors,
        string $message = 'Validation failed'
    ) {
        parent::__construct($message, 422);
    }
}
```

```php
throw new PaymentDeclinedException('Insufficient funds');
throw new ValidationFailedException(['email' => 'Required', 'name' => 'Too short']);
```
