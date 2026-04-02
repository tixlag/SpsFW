# Preload и OPcache

SpsFW использует два вида compile-time кеша:

| Файл | Содержимое | Когда обновлять |
|---|---|---|
| `.cache/compiled_routes.php` | Маршруты всех контроллеров | Добавление/изменение `#[Route]` |
| `.cache/compiled_di.php` | Граф DI-зависимостей | Изменение биндингов или конструкторов |

При **первом запросе** кеши создаются автоматически. В **prod-среде** их надо прогревать явно при деплое — чтобы первый реальный запрос не тратил время на сканирование файлов.

---

## `preload.php` — прогрев кеша

`preload.php` — скрипт, который запускается **вне HTTP-цикла**: при деплое, перезапуске, CI/CD.

### Содержимое

```php
<?php

use SpsFW\Core\Bootstrap;
use SpsFW\Core\Config;
use SpsFW\Core\DI\DIContainer;
use SpsFW\Core\Router\DICacheBuilder;
use SpsFW\Core\Router\Router;

require_once __DIR__ . '/vendor/autoload.php';

// Загружаем окружение
Bootstrap::loadEnv(__DIR__ . '/.env');
Bootstrap::loadEnv(__DIR__ . '/.env.' . ($_ENV['ENV'] ?? 'dev'));

// Инициализируем конфиг (нужен для DI-сканирования)
Config::init();
$diBindings = require __DIR__ . '/config/di_config.php';
Config::setDIBindings($diBindings);

// Удаляем старый кеш
$cacheDir = __DIR__ . '/.cache';
@unlink($cacheDir . '/compiled_routes.php');
@unlink($cacheDir . '/compiled_di.php');

// Пересоздаём кеш маршрутов и DI
$router = new Router(useCache: true);          // сканирует контроллеры, пишет compiled_routes.php
DICacheBuilder::compileDI($router->container); // пишет compiled_di.php

// Компилируем кеши в OPcache (опционально, ускоряет первый боевой запрос)
if (function_exists('opcache_compile_file')) {
    opcache_compile_file($cacheDir . '/compiled_routes.php');
    opcache_compile_file($cacheDir . '/compiled_di.php');
    echo "OPcache: кеши скомпилированы\n";
}

echo "Preload: OK\n";
```

### Запуск

```bash
php preload.php
```

> Запускать **от того же пользователя**, что и php-fpm процесс, иначе OPcache-компиляция может быть недоступна.

---

## OPcache — `php.ini`

### Базовая конфигурация

```ini
; /etc/php/8.3/fpm/conf.d/10-opcache.ini

opcache.enable=1
opcache.enable_cli=0           ; CLI не нужен в проде
opcache.memory_consumption=256 ; МБ — увеличьте для больших проектов
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=20000
opcache.validate_timestamps=0  ; ВАЖНО: в проде отключить, кеш не будет инвалидирован
opcache.revalidate_freq=0
opcache.fast_shutdown=1
opcache.jit=tracing             ; PHP 8+ JIT (опционально)
opcache.jit_buffer_size=128M
```

> В **dev-среде** используйте `validate_timestamps=1` и `revalidate_freq=1` — OPcache будет проверять изменения файлов каждую секунду.

### `opcache.preload` (PHP 7.4+)

Позволяет PHP загрузить набор классов **один раз при старте php-fpm** и держать их в памяти:

```ini
opcache.preload=/var/www/my-project/opcache_preload.php
opcache.preload_user=www-data
```

**`opcache_preload.php`** — отдельный от `preload.php` скрипт, вызывается **php-fpm при старте**:

```php
<?php
// opcache_preload.php — загружается при старте php-fpm

$vendorAutoload = __DIR__ . '/vendor/autoload.php';
require_once $vendorAutoload;

// Список классов фреймворка, которые нагружены в каждом запросе
$classes = [
    \SpsFW\Core\Router\Router::class,
    \SpsFW\Core\DI\DIContainer::class,
    \SpsFW\Core\Http\Request::class,
    \SpsFW\Core\Http\Response::class,
    \SpsFW\Core\Config::class,
    \SpsFW\Core\Validation\Validator::class,
];

foreach ($classes as $class) {
    if (!class_exists($class, false)) {
        opcache_compile_file((new ReflectionClass($class))->getFileName());
    }
}

// Компилируем кеши роутов и DI (если уже созданы preload.php)
$cacheDir = __DIR__ . '/.cache';
foreach (['compiled_routes.php', 'compiled_di.php'] as $file) {
    $path = $cacheDir . '/' . $file;
    if (file_exists($path)) {
        opcache_compile_file($path);
    }
}
```

---

## CI/CD — правильный порядок деплоя

```bash
# 1. Деплой кода
git pull origin main
composer install --no-dev --optimize-autoloader

# 2. Прогрев кеша (сборка route/DI кешей)
php preload.php

# 3. Перезапуск php-fpm (инвалидирует OPcache, загружает opcache_preload.php)
sudo systemctl reload php8.3-fpm

# 4. Прогрев OPcache первым запросом (опционально)
curl -sf https://myapp.example.com/healthz > /dev/null
```

> Порядок важен: сначала `preload.php` (создаёт `.cache/`), потом reload php-fpm (OPcache-preload компилирует эти файлы).

---

## Инвалидация кеша вручную

Если после изменения контроллеров маршруты не обновляются:

```bash
# Удалить кеш вручную
rm -f .cache/compiled_routes.php .cache/compiled_di.php

# Перезапустить php-fpm (OPcache сбрасывается)
sudo systemctl reload php8.3-fpm

# Или только сбросить OPcache без перезапуска
php -r "opcache_reset();"
```

В **dev-режиме** можно отключить кеширование в Router:

```php
// index.php — dev only
$router = new Router(useCache: false);
```

Или через переменную окружения:

```php
$useCache = ($_ENV['APP_ENV'] ?? 'dev') !== 'dev';
$router = new Router(useCache: $useCache);
```

---

## `DEBUG_MODE` и трассировка ошибок

| `DEBUG_MODE` | В ответе | В логах |
|---|---|---|
| `true` | Exception class, message, file, line, trace | Полный stack trace |
| `false` | Только message (для `BaseException`) / `Internal server error` (для остальных) | Полный stack trace |

Для prod-среды всегда устанавливайте `DEBUG_MODE=false`.
