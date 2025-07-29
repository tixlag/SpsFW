# Маршрутизация (Routing)

Маршруты в SpsFW определяются с помощью атрибутов PHP непосредственно в методах контроллеров.

## Основной класс: `SpsFW\Core\Router\Router`

### Конструктор

```php
new Router(
    ?string $controllersDir = null,
    bool $useCache = true,
    string $cacheDir = ..., // Путь к директории кэша по умолчанию
    array $dependencies = [] // Глобальные зависимости для Middleware
);
```

*   `$controllersDir`: Дополнительная директория для поиска контроллеров.
*   `$useCache`: Включить/выключить кэширование маршрутов и DI.
*   `$cacheDir`: Директория для хранения кэша.
*   `$dependencies`: Массив зависимостей для внедрения в Middleware.

## Определение маршрутов

Маршруты определяются с помощью атрибута `#[Route]` на методах контроллера.

### Атрибут `#[Route]`

```php
#[Route(string $path, array $httpMethods = ['GET'])]
```

*   `$path`: Путь маршрута, может содержать параметры в фигурных скобках (например, `/users/{id}`).
*   `$httpMethods`: Массив разрешенных HTTP-методов (например, `['GET', 'POST']`).

### Пример

```php
<?php
namespace App\Controllers;

use SpsFW\Core\Attributes\Route;
use SpsFW\Core\Http\Response;
use SpsFW\Core\RestController; // Предполагаемый базовый класс

class MyController extends RestController
{
    #[Route('/api/users/{id}', ['GET'])]
    public function getUser(int $id): Response
    {
        // Логика получения пользователя
        return Response::json(['id' => $id, 'name' => 'John Doe']);
    }

    #[Route('/api/users', ['POST'])]
    public function createUser(): Response
    {
        // Логика создания пользователя
        return Response::created(['id' => 123, 'name' => 'New User']);
    }
}
?>
```

## Параметры маршрута

Параметры в пути (например, `{id}`) автоматически передаются в метод контроллера как аргументы с соответствующими именами и типами.

```php
#[Route('/api/users/{userId}/posts/{postId}', ['GET'])]
public function getUserPost(int $userId, int $postId): Response
{
    // $userId и $postId заполняются из URL
    return Response::json(['user_id' => $userId, 'post_id' => $postId]);
}
```