# Контроллеры (Controllers)

Контроллеры - это классы, содержащие методы, обрабатывающие запросы по определенным маршрутам.

## Создание контроллера

Обычно контроллеры наследуются от базового класса (например, `RestController`, хотя он не показан в коде ядра фреймворка, но предполагается его использование).

```php
<?php
namespace App\Controllers;

use SpsFW\Core\RestController; // Предполагаемый базовый класс

class MyController extends RestController
{
    // Методы контроллера
}
?>
```

## Методы контроллера

Методы контроллера соответствуют маршрутам и могут принимать:

1.  **Параметры маршрута:** Переменные из пути URL (`{id}`).
2.  **DTO для валидации:** Объекты данных, провалидированные и заполненные из тела запроса или других источников.

Методы контроллера должны возвращать объект `SpsFW\Core\Http\Response` или данные, которые будут автоматически преобразованы:

*   `Response` -> Возвращается как есть.
*   `string` -> Тело ответа `Response`.
*   `array`/`object` -> JSON тело ответа `Response::json()`.

## Внедрение зависимостей в контроллерах

Зависимости (сервисы) внедряются через конструктор контроллера с использованием атрибута `#[Inject]`.

### Атрибут `#[Inject]`

Используется для указания параметров конструктора, которые должны быть разрешены через DI-контейнер.

### Пример

```php
<?php
namespace App\Controllers;

use SpsFW\Core\Attributes\Inject;
use SpsFW\Core\RestController;
use App\Services\UserServiceInterface;
use App\Services\LoggerInterface; // Пример

class UserController extends RestController
{
    // Предполагается, что в Config::$bindings есть UserServiceInterface::class => UserService::class
    public function __construct(
        #[Inject] private UserServiceInterface $userService,
        #[Inject] private LoggerInterface $logger // Пример другой зависимости
    ) {
        parent::__construct(); // если нужно
    }

    #[Route('/api/users/{id}', ['GET'])]
    public function getUser(int $id): Response
    {
        $user = $this->userService->find($id);
        $this->logger->info("User $id retrieved");
        return Response::json($user);
    }
}
?>
```