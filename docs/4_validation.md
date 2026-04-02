# Валидация данных (Validation)

Валидация входящих данных реализована через DTO с атрибутами `OpenApi\Attributes\Property`. Правила извлекаются из атрибутов при сборке кеша маршрутов и применяются автоматически перед вызовом метода контроллера.

---

## Источники данных

| Атрибут | Источник |
|---|---|
| `#[JsonBody]` | `php://input` (JSON) |
| `#[QueryParams]` | `$_GET` |
| `#[PostBody]` | `$_POST` |
| `#[FormDataBody]` | `$_POST` + `$_FILES` |

```php
#[Route('/api/deals', httpMethods: ['POST'])]
public function create(#[JsonBody] CreateDealDto $dto): Response { ... }

#[Route('/api/deals', httpMethods: ['GET'])]
public function list(#[QueryParams] DealsFilterDto $filter): Response { ... }
```

---

## Правила валидации

Все правила берутся из аргументов атрибута `#[OA\Property]`:

| Правило | Тип значения | Описание |
|---|---|---|
| `required` | `[true]` | Поле обязательно |
| `type` | `string` | `'string'`, `'integer'`, `'number'`, `'boolean'`, `'array'` |
| `minimum` | `int\|float` | Минимальное числовое значение |
| `maximum` | `int\|float` | Максимальное числовое значение |
| `minLength` | `int` | Минимальная длина строки |
| `maxLength` | `int` | Максимальная длина строки |
| `enum` | `array` | Допустимые значения |
| `format` | `string` | `'uuid'`, `'email'`, `'date'`, `'date-time'` |
| `nullable` | `true` | Поле может быть `null` |

---

## Примеры DTO

### Полное DTO с разными правилами

```php
<?php

namespace App\Deals\DTOs;

use OpenApi\Attributes as OA;

class CreateDealDto
{
    // Обязательная строка
    #[OA\Property(property: 'title', type: 'string', required: [true], maxLength: 255)]
    public string $title;

    // UUID — обязательный, проверяется формат
    #[OA\Property(property: 'client_id', type: 'string', format: 'uuid', required: [true])]
    public string $client_id;

    // Nullable UUID — поле необязательно, но если передано — должно быть UUID
    #[OA\Property(property: 'course_id', type: 'string', format: 'uuid', nullable: true)]
    public ?string $course_id = null;

    // Enum — только из списка
    #[OA\Property(property: 'status', type: 'string', enum: ['new', 'in_progress', 'done', 'cancelled'])]
    public string $status;

    // Целое число с диапазоном
    #[OA\Property(property: 'amount', type: 'integer', minimum: 0, maximum: 10_000_000)]
    public int $amount;

    // Email
    #[OA\Property(property: 'contact_email', type: 'string', format: 'email', nullable: true)]
    public ?string $contact_email = null;

    // Дата (формат Y-m-d)
    #[OA\Property(property: 'due_date', type: 'string', format: 'date', nullable: true)]
    public ?string $due_date = null;

    // Дата-время (ISO 8601)
    #[OA\Property(property: 'scheduled_at', type: 'string', format: 'date-time', nullable: true)]
    public ?string $scheduled_at = null;

    // Массив строк
    #[OA\Property(property: 'tags', type: 'array', nullable: true)]
    public ?array $tags = null;
}
```

### DTO с дефолтными значениями (query-параметры)

```php
class DealsFilterDto
{
    #[OA\Property(property: 'limit', type: 'integer', minimum: 1, maximum: 100)]
    public int $limit = 20;

    #[OA\Property(property: 'offset', type: 'integer', minimum: 0)]
    public int $offset = 0;

    #[OA\Property(property: 'status', type: 'string', enum: ['new', 'done'], nullable: true)]
    public ?string $status = null;
}
```

### DTO с вложенными объектами

```php
class CreateOrderDto
{
    #[OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: OrderItemDto::class))]
    public array $items;
}

class OrderItemDto
{
    #[OA\Property(property: 'product_id', type: 'string', format: 'uuid', required: [true])]
    public string $product_id;

    #[OA\Property(property: 'quantity', type: 'integer', minimum: 1, required: [true])]
    public int $quantity;
}
```

---

## Поведение валидации

### `nullable: true`

- Если поле **отсутствует** в запросе или равно `null` — устанавливается `null`, остальные правила (`type`, `format`, `enum`) **не проверяются**
- Если поле **присутствует** и не `null` — применяются все остальные правила

```php
// course_id не передан → $dto->course_id === null ✓
// course_id = "not-uuid" → ValidationException ✓
// course_id = "6ba7b810-9dad-11d1-80b4-00c04fd430c8" → ok ✓
```

### `enum`

Значение должно быть строго (`===`) из списка допустимых:
```php
// status = "new" → ok
// status = "unknown" → ValidationException: "Поле 'status' должно быть одним из: new, in_progress, done, cancelled"
```

### `format: 'uuid'`

Проверяет UUID v1-v5 по regex:
```
/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i
```

### `format: 'email'`

Использует `filter_var($value, FILTER_VALIDATE_EMAIL)`.

### `format: 'date'`

Парсит строку в UTC и возвращает `Y-m-d`.

### `format: 'date-time'`

Парсит строку в UTC и возвращает ISO 8601 (`ATOM`).

---

## Использование в контроллере

```php
#[Route('/api/deals', httpMethods: ['POST'])]
public function create(#[JsonBody] CreateDealDto $dto): Response
{
    // $dto уже провалидирован
    // Если что-то не так — Router уже вернул 400 до вызова этого метода

    $deal = $this->dealsService->create($dto);
    return Response::created($deal);
}
```

---

## Ошибка валидации

При ошибке выбрасывается `ValidationException`, Router возвращает HTTP 400:

```json
{
  "error": {
    "status": 400,
    "message": "Поле 'client_id' должно быть валидным UUID"
  }
}
```
