# Внедрение зависимостей (DI)

Фреймворк использует собственный контейнер DI (`DIContainer`) с поддержкой кэширования.

## Конфигурация DI

Связывание интерфейсов с их реализациями происходит в `SpsFW\Core\Config::$bindings`.

### Свойство `Config::$bindings`

Статический массив, где ключи - это интерфейсы, а значения - их конкретные реализации.

### Метод `Config::setDIBindings`

```
Config::setDIBindings(array $bindings)
```

Позволяет добавить или переопределить биндинги.

### Пример конфигурации

```php
// В preload.php или index.php
use SpsFW\Core\Config;

// Инициализация конфига из .env
Config::init();

// Устанавливаем DI-биндинги
Config::setDIBindings([
    App\Services\UserServiceInterface::class => App\Services\UserService::class,
    App\Repositories\UserRepositoryInterface::class => App\Repositories\DatabaseUserRepository::class,
]);
```

## Использование DI

### В контроллерах

Через конструктор с атрибутом `#[Inject]`.

### В Middleware

Через конструктор.

### Автоматическое разрешение

Контейнер пытается автоматически разрешить зависимости по типу в конструкторах, используя настроенные биндинги.
