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
│   │   ├── Subdomain/            # Поддомен
│   │   │   ├── Controllers/     # Контроллеры
│   │   │   ├── Services/        # Сервисы
│   │   │   ├── Storages/        # Репозитории
│   │   │   ├── DTOs/            # DTO для валидации
│   │   │   └── ...
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

use SpsFW\Core\Router\Router;

date_default_timezone_set('UTC');
const allowedOrigins = [
    'http://localhost:5173',
    'https://localhost:5173',
    'https://next.localhost:12443',
    'https://next.sps38.pro'
];
$origin = $_SERVER['HTTP_X_ORIGIN'] ?? $_SERVER['HTTP_ORIGIN'] ?? null;
if ($origin && in_array($origin, allowedOrigins)) {
    header("Access-Control-Allow-Origin: " . $origin);
    header('Access-Control-Allow-Credentials: true');
}


if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === "OPTIONS") {
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS");
    header('Access-Control-Allow-Credentials: true');
    header("Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept, Authorization, WithCredentials, Set-Cookie, X-Origin");
    header("Access-Control-Max-Age: 3600");
    exit();
}

require_once __DIR__ . '/bootstrap.php';

$router = new Router();

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
