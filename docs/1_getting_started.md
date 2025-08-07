# Начало работы с SpsFW

## Установка

1.  Создайте новый проект или используйте существующий.
2.  Добавьте SpsFW как зависимость через Composer:
    ```bash
    composer require your-vendor/spsfw
    ```
    *(Предполагается, что фреймворк опубликован в Packagist или доступен как приватный пакет)*

## Базовая структура проекта

Рекомендуемая структура проекта:

```
your-project/
├── src/                 # Ваш код приложения
│   ├── Domain/     # Доменная область
│   │   ├── Controllers/     # Контроллеры
│   │   ├── Services/        # Сервисы
│   │   ├── Storages/        # Репозитории
│   │   ├── DTOs/            # DTO для валидации
│   │   └── ...
├── .env                 # Файл конфигурации окружения
├── .env.dev             # Файл конфигурации окружения для dev среды
├── .env.prod            # Файл конфигурации окружения для prod среды
├── preload.php          # Предзагрузка (опционально, для кэширования)

├── vendor/              # Зависимости Composer
└── index.php/           # Точка входа
```

## Точка входа (`index.php`)

```php
<?php
require_once '../vendor/autoload.php';

// Инициализация конфигурации
\SpsFW\Core\Config::init([
     'custom' =>
        [
            'param1' => $_ENV['PARAM1'],
        ],
     'otherCustom' => $_ENV['PARAM2']
]);

// Настройка DI-биндингов 
// Здесь указываем, какая реализация абстракций должна быть использована
 \SpsFW\Core\Config::setDIBindings([
     App\Services\UserServiceInterface::class => App\Services\UserService::class,
 ]);

// Создание и запуск маршрутизатора
$router = new \SpsFW\Core\Router\Router();
$response = $router->dispatch();
$response->send();
?>
```

## Конфигурация (`.env`)

Создайте файл `.env` в корне проекта:

```dotenv
APP_NAME=MyApp
APP_VERSION=1.0.0
APP_ENV=local
DEBUG_MODE=true
HTTP_SCHEME=http
HOST=localhost
PORT=8080

DB_ADAPTER=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_USER=user
DB_PASS=password
DB_NAME=myapp_db

JWT_SECRET=my_jwt_secret_key
JWT_ALG=HS256
JWT_EXP_SECONDS=3600

REFRESH_TOKEN_EXPIRES_IN=86400
MASTER_PASSWORD=supersecretmasterpassword
```

## Запуск

Используйте встроенный сервер PHP для разработки:

```bash
cd public
php -S localhost:8080
```

Или настройте веб-сервер (Apache/Nginx) так, чтобы все запросы направлялись в `index.php`.
