# AGENTS.md — SpsFW

Инструкции для AI-агентов, работающих с этим репозиторием.

---

## Что это за проект

**SpsFW** — PHP-фреймворк для REST API. Архитектура атрибут-ориентированная: роутинг, валидация, DI и контроль доступа описываются через PHP 8 атрибуты прямо на методах контроллеров.

Фреймворк используется как зависимость в клиентском проекте (CRM). Сам фреймворк — `src/Core/`. Пример подключения — `example/`.

**Стек:** PHP 8.2+, PDO (MySQL/MariaDB/PostgreSQL), Redis, RabbitMQ, Phinx (миграции), firebase/php-jwt, zircote/swagger-php, monolog.

---

## Структура `src/Core/`

```
Attributes/         — PHP-атрибуты: Route, Controller, Inject, RateLimit, NoAuthAccess,
                       AccessRulesAll/Any, Middleware, PhpIni, Validation/*
Auth/               — JWT + refresh tokens, AccessRule, абстрактный AuthService
Bootstrap.php       — точка входа: loadEnv(), getRouter()
Config.php          — статический конфиг + DI bindings по умолчанию
Db/                 — PDO singleton (Db.php), DbHelper, BaseModel
DI/                 — DIContainer (singleton, кэш compiled_di.php)
Exceptions/         — иерархия исключений от BaseException (код = HTTP статус)
Http/               — Request (singleton), Response, HttpMethod enum
Middleware/         — MiddlewareInterface, RateLimitMiddleware, PerformanceMiddleware
Models/             — базовые модели
Psr/                — FileCache (PSR-16), MonologLogger (PSR-3), GuzzleHttpClientAdapter
Queue/              — RabbitMQ publisher/consumer, Outbox pattern, WorkerRunner, Heartbeat
Redis/              — RedisClient singleton
Route/              — RestController (базовый класс контроллеров)
Router/             — Router (главный класс), ClassScanner, DICacheBuilder, PathManager
Storage/            — PdoStorage (базовый класс репозиториев)
Swagger/            — SwaggerController, генерация OpenAPI
Validation/         — Validator, правила валидации, DTO-парсинг
Workers/            — базовые классы воркеров
```

---

## Ключевые паттерны

### Контроллер

```php
#[Controller]
class UserController extends RestController
{
    public function __construct(
        #[Inject] private UserServiceI $userService
    ) {}

    #[Route('/users/{id}', [HttpMethod::GET])]
    #[AccessRulesAll(['admin'])]
    public function getUser(#[QueryParams] GetUserDto $dto): Response
    {
        return Response::json($this->userService->find($dto->id));
    }
}
```

### DI

Зависимости инжектируются через `#[Inject]` в конструкторе. Биндинги интерфейс → реализация задаются в `Config::$bindings` или через `Config::setDIBindings()` из клиентского кода.

```php
// Config.php — биндинги по умолчанию
public static array $bindings = [
    AuthTokenStorageI::class => AuthTokenStorage::class,
    CacheInterface::class    => FileCache::class,
    LoggerInterface::class   => MonologLogger::class,
    // ...
];
```

### Валидация DTO

Параметры запроса описываются через `#[JsonBody]`, `#[QueryParams]`, `#[PostBody]`, `#[FormDataBody]` на аргументе метода контроллера. Правила валидации — через `#[Property]` из `zircote/swagger-php` на полях DTO.

### Репозиторий

Наследует `PdoStorage`, получает PDO через `$this->getPdo()` или `$this->getPdo('db_read')`. Транзакции через `beginTransaction()` / `commitTransaction()` / `rollbackTransaction()`.

### Исключения → HTTP коды

`BaseException($message, $code)` — `$code` = HTTP статус. Router перехватывает все `Throwable` и отдаёт JSON-ответ с соответствующим статусом.

### Очередь / Outbox

- `RabbitMQQueuePublisher` — прямая публикация в RabbitMQ
- `OutboxPublisher` — сохраняет в БД, `flush()` публикует батчем с `SELECT FOR UPDATE SKIP LOCKED`
- Воркеры наследуют от базового класса в `Workers/`, запускаются как отдельные процессы

---

## Известные баги (не трогать без понимания)

| Файл | Строка | Проблема |
|------|--------|---------|
| `Auth/AuthServiceAbstract.php` | ~129 | Баг с `\|\|` в проверке masterPassword — позволяет войти за любого пользователя |
| `Auth/AuthServiceAbstract.php` | ~129 | `==` вместо `hash_equals()` для masterPassword — timing attack |
| `Redis/RedisClient.php` | `incrWithTtl()` | INCR + EXPIRE не атомарны — race condition в rate limiting |
| `Storage/PdoStorage.php` | `insert()` | Метод сломан: не выполняет запрос и не использует prepared statements |
| `Http/Response.php` | конструктор | CORS заголовки `Access-Control-Allow-Origin` закомментированы |

Полный список задач — см. `TODO.md`.

---

## Соглашения

- **PHP 8.2+** — readonly properties, enum, fibers, атрибуты
- **Namespace** — `SpsFW\Core\*` для фреймворка, клиентский код использует свой namespace
- **Интерфейсы** — все внешние зависимости через интерфейс (`*I` или `*Interface` суффикс), реализация биндится в DI
- **Новый модуль** — создаётся в `src/Core/<ModuleName>/`, регистрируется через `Config::$bindings` если нужен DI
- **Миграции** — одна миграция на таблицу, с ветками для адаптера: `$this->getAdapter()->getAdapterType() === 'pgsql'`
- **HTTP-статусы** — через код исключения, не через `http_response_code()` напрямую
- **Кэш** — роутер и DI пишут кэш в `.cache/` (в `.gitignore`), при изменении контроллеров нужно сбросить вручную

---

## Чего нет в фреймворке (не реализовывать без обсуждения)

- ORM / query builder — только raw PDO
- Session management — только JWT
- Event dispatcher — пока нет
- Config hot reload — рестарт процесса

---

## Как запустить

Фреймворк — библиотека, не standalone-приложение. Смотри `example/` для примера подключения.

```bash
composer install
cp example/.env.template .env   # заполнить переменные
composer migrate:dev             # применить миграции
```

Тестов пока нет. `src/Test/TestMemoryLimitController.php` — тестовый контроллер для ручной проверки, не PHPUnit.

---

## Приоритеты при работе над кодом

1. **Обратная совместимость** — не ломать клиентский код при изменении интерфейсов
2. **Удобство разработчика** > производительность (конфигурация должна быть минимальной)
3. **Не добавлять зависимости** без обсуждения — фреймворк должен оставаться легковесным
4. **Безопасность** — все известные баги из TODO.md помечены как критические, исправлять в первую очередь
