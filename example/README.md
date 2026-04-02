В этой папке пример корневой директории проекта, который использует этот фреймворк.

## Что перенесено из примера в фреймворк

### `Bootstrap::loadEnv(string $filePath)` (src/Core/Bootstrap.php)

Загружает переменные окружения из `.env` файла в `$_ENV`. Ранее этот функционал определялся как `easyEnv()` прямо в `bootstrap.php` клиентского проекта.

Использование в `bootstrap.php` проекта:
```php
use SpsFW\Core\Bootstrap;

Bootstrap::loadEnv(__DIR__ . '/.env');
Bootstrap::loadEnv(__DIR__ . '/.env.' . ($_ENV['APP_ENV'] ?? 'dev'));
```

## Что остаётся на стороне клиентского проекта

- CORS-заголовки и обработка OPTIONS-запросов (специфичны для каждого проекта)
- DI-биндинги (`config/di_config.php`)
- Регистрация глобальных обработчиков ошибок (`set_exception_handler`, `set_error_handler`)
- Конфигурация воркеров, очередей и прочих сервисов
