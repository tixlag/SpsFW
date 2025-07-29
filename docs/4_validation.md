# Валидация данных (Validation)

Валидация входящих данных (из тела запроса, query-параметров и т.д.) осуществляется с помощью DTO (Data Transfer Objects) и специальных атрибутов.

## DTO и атрибуты валидации

DTO используют атрибуты `OpenApi\Attributes\Property` (или аналогичные из фреймворка) для описания полей и правил валидации.

### Атрибут `Property`

```
#[Property(property: 'name', type: 'string', maxLength: 255, example: 'John Doe', ...)]
```

Определяет свойства DTO и правила валидации.

### Пример DTO

```php
<?php
namespace App\DTOs;

use OpenApi\Attributes\Property; // Или ваш внутренний аналог

// Пример DTO для тела запроса JSON
class CreateUserDto
{
    #[Property(property: 'name', type: 'string', maxLength: 255, example: 'John Doe')]
    public string $name;

    #[Property(property: 'email', type: 'string', format: 'email', example: 'john.doe@example.com')]
    public string $email;

    #[Property(property: 'age', type: 'integer', minimum: 0, maximum: 150, example: 30)]
    public int $age;
}

// Пример DTO для Query-параметров
class UserFilterDto
{
    #[Property(property: 'limit', type: 'integer', minimum: 1, maximum: 100, example: 10)]
    public int $limit = 10;

    #[Property(property: 'offset', type: 'integer', minimum: 0, example: 0)]
    public int $offset = 0;
}
?>
```

## Атрибуты источника данных

Для указания источника данных, который нужно валидировать, используются специальные атрибуты на параметрах метода контроллера:

*   `#[JsonBody]`: Источник - тело запроса в формате JSON.
*   `#[QueryParams]`: Источник - query-параметры URL.
*   `#[PostBody]`: Источник - тело запроса в формате `application/x-www-form-urlencoded`.
*   `#[FormDataBody]`: Источник - тело запроса в формате `multipart/form-data`.

### Пример использования

```php
<?php
namespace App\Controllers;

use SpsFW\Core\Attributes\Route;
use SpsFW\Core\Attributes\JsonBody;
use SpsFW\Core\Attributes\QueryParams;
use SpsFW\Core\Http\Response;
use App\DTOs\CreateUserDto;
use App\DTOs\UserFilterDto;

class UserController // extends RestController
{
    #[Route('/api/users', ['POST'])]
    public function createUser(#[JsonBody] CreateUserDto $dto): Response
    {
        // $dto уже провалидирован и заполнен данными из JSON тела запроса
        // ...
    }

    #[Route('/api/users', ['GET'])]
    public function listUsers(#[QueryParams] UserFilterDto $filter): Response
    {
        // $filter уже провалидирован и заполнен данными из query-параметров
        // ...
    }
}
?>
```

DTO будет автоматически создан, заполнен данными из указанного источника и провалидирован перед вызовом метода контроллера. Если валидация не пройдена, будет выброшено исключение.
