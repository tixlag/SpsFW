# SpsFW — обзор архитектуры

SpsFW — PHP 8.2+ фреймворк для построения REST API. **Не блокчейн. Не криптовалюта.** Серверный PHP-фреймворк для CRM, медицинских систем, B2B-приложений.

---

## Принцип работы

Фреймворк использует **атрибут-ориентированный подход**: роутинг, валидация, DI и контроль доступа описываются через PHP 8 атрибуты прямо на методах и конструкторах. Никаких роутинг-файлов — всё рядом с кодом.

```php
#[Controller]
class ClientController extends RestController
{
    public function __construct(
        #[Inject] private ClientService $service   // DI
    ) {}

    #[Route('/api/clients/{id}', ['GET'])]
    #[AccessRulesAny([ConsultantRules::CLIENTS_ACCESS])]   // контроль доступа
    public function getById(#[QueryParams] GetClientDto $dto): Response  // валидация
    {
        return Response::json($this->service->findById($dto->id));
    }
}
```

---

## Стек

| Компонент | Технология |
|---|---|
| Язык | PHP 8.2+ |
| БД | MySQL / MariaDB / PostgreSQL (PDO) |
| Кеш | Redis |
| Очереди | RabbitMQ (php-amqplib) |
| Миграции | Phinx |
| Логирование | Monolog (PSR-3) |
| Кеш PSR-16 | FileCache |
| JWT | firebase/php-jwt |
| OpenAPI | zircote/swagger-php |
| HTTP-клиент | Guzzle (PSR-18) |

---

## Структура `src/Core/`

```
Auth/               — JWT + refresh tokens, AccessRule, абстрактный AuthService
Attributes/         — PHP-атрибуты: Route, Controller, Inject, Middleware,
                       RateLimit, NoAuthAccess, AccessRulesAll/Any, Validation/*
Bootstrap.php       — loadEnv(), getRouter()
Config.php          — статический конфиг + DI-биндинги по умолчанию
Db/                 — PDO singleton (Db.php)
DI/                 — DIContainer (singleton, кеш compiled_di.php)
Exceptions/         — иерархия исключений от BaseException (код = HTTP статус)
Http/               — Request (singleton), Response, HttpMethod enum
Middleware/         — MiddlewareInterface, RateLimitMiddleware, PerformanceMiddleware
Phinx/              — PhinxConfigFactory (автодискавери миграций)
Psr/                — FileCache (PSR-16), MonologLogger (PSR-3), GuzzleHttpClientAdapter
Queue/              — RabbitMQ publisher/consumer, Outbox pattern, WorkerRunner
Redis/              — RedisClient singleton
Route/              — RestController (базовый класс контроллеров)
Router/             — Router, ClassScanner, DICacheBuilder, PathManager
Storage/            — PdoStorage (базовый класс репозиториев)
Swagger/            — SwaggerController, генерация OpenAPI
Validation/         — Validator, правила валидации, DTO-парсинг
Workers/            — базовые классы воркеров
```

---

## Жизненный цикл запроса

```
index.php
  └── bootstrap.php                Bootstrap::loadEnv(), Config::init(), DI bindings
        └── new Router()
              ├── ClassScanner      Находит все #[Controller] классы
              ├── DICacheBuilder    Строит граф зависимостей (кеш .cache/compiled_di.php)
              └── dispatch()
                    ├── Request::init()           Парсинг входящего запроса
                    ├── Middleware::handle()       Глобальные middleware (Rate Limit, Auth, ...)
                    ├── Auth::init()               Валидация JWT → $user->accessRules
                    ├── AccessChecker::checkAccess() Проверка #[AccessRulesAny/All]
                    ├── Validator::validate()      DTO (#[JsonBody], #[QueryParams], ...)
                    ├── Controller::method()       Бизнес-логика
                    └── Response::send()           JSON-ответ
```

---

## DI Container

Зависимости инжектируются через `#[Inject]` в конструкторе. Биндинги задаются в `Config::$bindings` (дефолты фреймворка) и переопределяются в `di_config.php` проекта.

```php
// Поддерживаемые форматы биндингов:
Config::setDIBindings([
    UserServiceI::class => UserService::class,                         // строка → класс
    CacheInterface::class => new FileCache('/tmp/cache'),              // инстанс
    HttpClientI::class => ['class' => GuzzleAdapter::class,
                            'args' => ['https://api.example.com']],   // с аргументами
    RedisClient::class => fn() => RedisClient::getInstance(),         // callable (lazy)
]);
```

Граф зависимостей компилируется в `.cache/compiled_di.php` при первом запросе. Инвалидация — ручная (удалить файл) или через `preload.php`.

---

## Валидация DTO

DTO-объект с `zircote/swagger-php` атрибутами описывает правила валидации. Фреймворк автоматически парсит входящий запрос и создаёт DTO.

```php
use OpenApi\Attributes as OA;
use SpsFW\Core\Attributes\Validation\Required;

class CreateClientDto
{
    #[OA\Property(type: 'string', minLength: 1, maxLength: 100)]
    #[Required]
    public string $lastName;

    #[OA\Property(type: 'string', format: 'date')]
    #[Required]
    public string $birthDate;

    #[OA\Property(type: 'string', nullable: true)]
    public ?string $phone = null;
}
```

```php
#[Route('/api/clients', ['POST'])]
public function create(#[JsonBody] CreateClientDto $dto): Response
{
    return Response::json($this->service->create($dto), 201);
}
```

---

## Auth / JWT

Фреймворк хранит `uuid` и `accessRules` пользователя в JWT payload. При каждом запросе токен верифицируется, данные помещаются в `Auth::getOrNull()`.

```php
$user = Auth::getOrThrow();  // UserAbstract — uuid, accessRules
$user = Auth::getOrNull();   // null если нет токена
```

Реализация хранилища токенов: клиент создаёт класс, реализующий `AuthTokenStorageI`, и биндит его в DI.

---

## Миграции

Все миграции — на чистом SQL через Phinx. `phinx.php` в корне проекта:

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';
return \SpsFW\Core\Phinx\PhinxConfigFactory::create(__DIR__);
```

Фабрика автоматически находит `migrations/` папки по маркеру `MigrationRequiredClass.php` в `src/` проекта и в самом фреймворке.

---

## Что фреймворк не предоставляет

- ORM / query builder — только raw PDO
- Session management — только JWT
- Frontend / Twig / Blade — только JSON API
- Event dispatcher
- Блокчейн / криптовалюты / токены / staking / validators
