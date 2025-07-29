# Middlewares
#### НЕ РАБОТАЕТ: функционал на время отключен
Middleware - это программные компоненты, которые выполняются до (`handle`) и после (`after`) обработки запроса контроллером.

## Создание Middleware

Middleware должен реализовывать интерфейс `SpsFW\Core\Middleware\MiddlewareInterface`.

### Интерфейс `MiddlewareInterface`

```php
interface MiddlewareInterface
{
    public function handle(Request $request): Request;
    public function after(Response $response): Response;
}
```

### Пример Middleware

```php
<?php
namespace App\Middleware;

use SpsFW\Core\Middleware\MiddlewareInterface;
use SpsFW\Core\Http\Request;
use SpsFW\Core\Http\Response;

class MyMiddleware implements MiddlewareInterface
{
    private $someDependency;

    public function __construct(SomeDependency $dep) // Зависимости внедряются
    {
        $this->someDependency = $dep;
    }

    public function handle(Request $request): Request
    {
        // Логика, выполняемая ДО контроллера
        // Можно модифицировать $request
        $this->someDependency->log('Before controller');
        return $request;
    }

    public function after(Response $response): Response
    {
        // Логика, выполняемая ПОСЛЕ контроллера
        // Можно модифицировать $response
        $response->addHeader('X-My-Middleware', 'Processed');
        $this->someDependency->log('After controller');
        return $response;
    }
}
?>
```

## Регистрация Middleware

### Глобальные Middleware

Применяются ко всем маршрутам. Регистрируются в `Router` с помощью метода `addGlobalMiddleware`.

#### Метод `addGlobalMiddleware`

```php
$router->addGlobalMiddleware(string $middlewareClass, array $params = []);
```

#### Пример

```php
// В index.php или preload.php
$router = new \SpsFW\Core\Router\Router();
$router->addGlobalMiddleware(\App\Middleware\MyMiddleware::class, ['param1' => 'value1']);
// ...
```

### Middleware для контроллера/метода

Применяются к конкретному контроллеру или методу. Регистрируются с помощью атрибута `#[Middleware]`.

#### Атрибут `#[Middleware]`

```php
#[Middleware(array $middlewares)]
```

`$middlewares` - ассоциативный массив, где ключи - это имена классов Middleware, а значения - массивы параметров для их конструкторов.

#### Пример

```php
<?php
namespace App\Controllers;

use SpsFW\Core\Attributes\Middleware;
use SpsFW\Core\Attributes\Route;
use SpsFW\Core\Http\Response;
use App\Middleware\MyMiddleware;
use App\Middleware\AnotherMiddleware;

#[Middleware([MyMiddleware::class => ['param_for_controller' => 'val']])]
class MyController extends RestController
{
    #[Route('/api/data', ['GET'])]
    #[Middleware([AnotherMiddleware::class])] // Дополнительный мидлвар для метода
    public function getData(): Response
    {
        // ...
    }
}
?>
```

Порядок выполнения: Глобальные Middleware -> Middleware контроллера -> Middleware метода (в `handle`). В `after` - в обратном порядке.
