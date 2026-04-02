# Начало работы с SpsFW

## Требования

- PHP 8.2+
- Расширения: `ext-pdo`, `ext-mbstring`, `ext-pcntl`, `ext-posix`, `ext-redis` (для Redis-функционала)
- Composer
- PostgreSQL 14+ или MySQL/MariaDB

---

## Установка фреймворка

SpsFW подключается как composer-зависимость (локальный путь или VCS-репозиторий):

```json
// composer.json вашего проекта
{
  "require": {
    "tixlag/php-framework": "dev-master"
  },
  "repositories": [
    {
      "type": "path",
      "url": "../SpsFW"
    }
  ],
  "autoload": {
    "psr-4": {
      "App\\": "src/"
    }
  }
}
```

```bash
composer install
```

---

## Структура нового проекта

```
my-project/
├── src/                        # Код приложения
│   └── Users/                  # Пример раздела (chapter)
│       ├── Controllers/
│       │   └── UsersController.php
│       ├── Services/
│       │   └── UsersService.php
│       ├── Storages/
│       │   └── UsersStorage.php
│       ├── Entities/
│       │   ├── migrations/     # Phinx-миграции раздела
│       │   └── MigrationRequiredClass.php
│       ├── DTOs/
│       └── Enums/
├── config/
│   └── di_config.php           # DI-биндинги
├── .cache/                     # Кеш роутов и DI (авто, в .gitignore)
├── .env                        # Переменные окружения
├── .env.dev                    # Переопределения для dev
├── .env.prod                   # Переопределения для prod
├── bootstrap.php               # Инициализация приложения
├── index.php                   # Точка входа HTTP
└── preload.php                 # Прогрев кеша (деплой/перезапуск)
```

---

## Шаг 1 — `.env`

Создайте `.env` в корне проекта (скопируйте из `vendor/tixlag/php-framework/example/.env.template`):

```dotenv
# Среда (используется для загрузки .env.<ENV>)
ENV=dev

# HTTP
HTTP_SCHEME=https
HOST=localhost
PORT=

# Приложение
APP_NAME=my-project
APP_VERSION=1.0.0
APP_ENV=dev
DEBUG_MODE=true
MASTER_PASSWORD=change_me

# База данных
DB_ADAPTER=pgsql          # pgsql | mysql | mariadb
DB_HOST=127.0.0.1
DB_PORT=5432
DB_NAME=myapp
DB_USER=myapp
DB_PASS=secret

# Redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=
REDIS_DB=0
REDIS_TIMEOUT=2.0

# Auth / JWT
REFRESH_TOKEN_EXPIRES_IN=2592000
JWT_SECRET=change_me_to_long_random_string
JWT_EXP_SECONDS=3600
JWT_ALG=HS256
```

> `.env.dev` и `.env.prod` содержат только **отличия** от базового `.env` — они загружаются поверх него.

---

## Шаг 2 — `bootstrap.php`

```php
<?php

use SpsFW\Core\Bootstrap;
use SpsFW\Core\Config;

require_once __DIR__ . '/vendor/autoload.php';

// Загружаем переменные окружения
Bootstrap::loadEnv(__DIR__ . '/.env');
Bootstrap::loadEnv(__DIR__ . '/.env.' . ($_ENV['ENV'] ?? 'dev'));

// CORS (адаптируйте под свой проект)
$allowedOrigins = $_ENV['DEBUG_MODE'] === 'true'
    ? ['http://localhost:5173', 'http://localhost:3000']
    : ['https://myapp.example.com'];

$origin = $_SERVER['HTTP_X_ORIGIN'] ?? $_SERVER['HTTP_ORIGIN'] ?? null;
if ($origin && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Expose-Headers: Content-Disposition, Authorization');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS');
    header('Access-Control-Allow-Headers: Origin, Content-Type, Accept, Authorization, X-Origin');
    header('Access-Control-Max-Age: 3600');
    exit();
}

// Инициализация конфигурации
// Базовые секции (app, db, auth, redis) автоматически читаются из $_ENV.
// Здесь передаём только дополнительные секции или переопределения.
Config::init([
    // Дополнительная БД (опционально)
    // 'db_legacy' => [
    //     'adapter'   => $_ENV['LEGACY_DB_ADAPTER'],
    //     'host'      => $_ENV['LEGACY_DB_HOST'],
    //     'port'      => $_ENV['LEGACY_DB_PORT'],
    //     'user'      => $_ENV['LEGACY_DB_USER'],
    //     'password'  => $_ENV['LEGACY_DB_PASS'],
    //     'dbname'    => $_ENV['LEGACY_DB_NAME'],
    //     'debugMode' => $_ENV['DEBUG_MODE'],
    // ],
]);

// DI-биндинги
$diBindings = require __DIR__ . '/config/di_config.php';
Config::setDIBindings($diBindings);
```

---


## Шаг 3 — `config/di_config.php`

```php
<?php

use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use SpsFW\Core\Auth\AuthToken\AuthTokenStorageI;
use SpsFW\Core\Psr\Cache\FileCache;
use SpsFW\Core\Psr\MonologLogger;

// Здесь — только ваши переопределения дефолтных биндингов фреймворка.
// Биндинги по умолчанию (AuthTokenStorage, AccessRuleService и др.) уже
// прописаны в Config::$bindings.
return [
    LoggerInterface::class => [
        'class' => MonologLogger::class,
        'args'  => [__DIR__ . '/../app.log'],
    ],
    CacheInterface::class => [
        'class' => FileCache::class,
        'args'  => [__DIR__ . '/../.cache', 3600],
    ],
    // AuthTokenStorageI::class => MyRedisTokenStorage::class,
];
```

---

## Шаг 4 — `index.php`

```php
<?php

use SpsFW\Core\Router\Router;

date_default_timezone_set('UTC');
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');

require_once __DIR__ . '/bootstrap.php';

$router = new Router();
$response = $router->dispatch();
$response->send();
```

---

## Шаг 5 — первый контроллер

Используйте `spsfw` CLI для генерации (см. [документацию CLI](14_spsfw_cli.md)) или создайте вручную:

```php
<?php

declare(strict_types=1);

namespace App\Users\Controllers;

use OpenApi\Attributes as OA;
use SpsFW\Core\Attributes\Controller;
use SpsFW\Core\Attributes\Inject;
use SpsFW\Core\Attributes\NoAuthAccess;
use SpsFW\Core\Attributes\Route;
use SpsFW\Core\Http\Response;
use SpsFW\Core\Route\RestController;
use App\Users\Services\UsersService;

#[Controller]
#[OA\Tag(name: 'users', description: 'Пользователи')]
class UsersController extends RestController
{
    public function __construct(
        #[Inject] private UsersService $usersService
    ) {
        parent::__construct();
    }

    #[Route('/api/users', httpMethods: ['GET'])]
    #[NoAuthAccess]
    public function getAll(): Response
    {
        return Response::json($this->usersService->getAll());
    }
}
```

---

## Шаг 6 — запуск

```bash
# Встроенный PHP-сервер (dev)
php -S localhost:8080 index.php

# Или через nginx + php-fpm (prod)
# Все запросы → index.php
```

---

## Nginx — минимальный конфиг

```nginx
server {
    listen 80;
    server_name myapp.example.com;
    root /var/www/myapp;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

---

## Следующие шаги

- [Маршрутизация](2_routing.md) — `#[Route]`, параметры пути, HTTP-методы
- [Контроллеры](3_controllers.md) — `RestController`, DI, атрибуты
- [Валидация](4_validation.md) — DTO, `#[JsonBody]`, `#[QueryParams]`
- [DI-контейнер](6_dependency_injection.md) — биндинги, `#[Inject]`
- [Конфигурация](7_configuration.md) — Config, секции, Redis
- [Миграции](8_database_migrations.md) — Phinx, доменные миграции
- [CLI-утилита spsfw](14_spsfw_cli.md) — генерация разделов и роутов
- [Preload и OPcache](15_preload_opcache.md) — прогрев кеша при деплое
