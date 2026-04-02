# Конфигурация (Configuration)

Вся конфигурация хранится в `$_ENV` (загружается из `.env`) и инициализируется через `Config::init()`.

---

## Загрузка `.env`

Используйте `Bootstrap::loadEnv()` вместо inline-функции `easyEnv()`:

```php
use SpsFW\Core\Bootstrap;

Bootstrap::loadEnv(__DIR__ . '/.env');
Bootstrap::loadEnv(__DIR__ . '/.env.' . ($_ENV['ENV'] ?? 'dev'));
```

**Поведение:**
- Игнорирует строки-комментарии (`#`) и строки без `=`
- Не перезаписывает уже установленные переменные (сначала загружайте базовый `.env`, затем env-специфичный)
- Безопасен для вызова с несуществующим файлом

---

## `Config::init()`

```php
Config::init(array $customConfig = []): void
```

Читает стандартные секции из `$_ENV` и объединяет с `$customConfig`. Должен вызываться **один раз** в `bootstrap.php` после загрузки `.env`.

### Автоматически заполняемые секции

#### `app`
| Ключ | Переменная | Описание |
|---|---|---|
| `name` | `APP_NAME` | Название приложения |
| `version` | `APP_VERSION` | Версия |
| `env` | `APP_ENV` | Среда (`dev`, `prod`, ...) |
| `host` | `HOST` + `PORT` | Хост с портом |
| `url` | `HTTP_SCHEME` + `HOST` + `PORT` | Полный URL |
| `debugMode` | `DEBUG_MODE` | `'true'` / `'false'` |
| `masterPassword` | `MASTER_PASSWORD` | Мастер-пароль |

#### `db`
| Ключ | Переменная | Описание |
|---|---|---|
| `adapter` | `DB_ADAPTER` | `pgsql` / `mysql` / `mariadb` |
| `host` | `DB_HOST` | |
| `port` | `DB_PORT` | |
| `user` | `DB_USER` | |
| `password` | `DB_PASS` | |
| `dbname` | `DB_NAME` | |
| `debugMode` | `DEBUG_MODE` | |

#### `auth`
| Ключ | Переменная | Описание |
|---|---|---|
| `refreshTokenExpiresIn` | `REFRESH_TOKEN_EXPIRES_IN` | TTL refresh-токена (сек) |
| `jwt.secret` | `JWT_SECRET` | Секрет подписи |
| `jwt.header.alg` | `JWT_ALG` | Алгоритм (`HS256`) |
| `jwt.payload.exp` | `JWT_EXP_SECONDS` | TTL access-токена (сек) |

#### `redis`
| Ключ | Переменная | По умолчанию |
|---|---|---|
| `host` | `REDIS_HOST` | `127.0.0.1` |
| `port` | `REDIS_PORT` | `6379` |
| `password` | `REDIS_PASSWORD` | `null` |
| `database` | `REDIS_DB` | `0` |
| `timeout` | `REDIS_TIMEOUT` | `2.0` |

### Добавление собственных секций

Передайте их в `$customConfig`:

```php
Config::init([
    'db_legacy' => [
        'adapter'   => $_ENV['LEGACY_DB_ADAPTER'],
        'host'      => $_ENV['LEGACY_DB_HOST'],
        'port'      => $_ENV['LEGACY_DB_PORT'],
        'user'      => $_ENV['LEGACY_DB_USER'],
        'password'  => $_ENV['LEGACY_DB_PASS'],
        'dbname'    => $_ENV['LEGACY_DB_NAME'],
        'debugMode' => $_ENV['DEBUG_MODE'],
    ],
    'payments' => [
        'api_key' => $_ENV['PAYMENT_API_KEY'],
        'sandbox' => $_ENV['PAYMENT_SANDBOX'] === 'true',
    ],
]);
```

Получение:
```php
$legacyPdo = Db::getByConfig('db_legacy');
$apiKey = Config::get('payments')['api_key'];
```

---

## `Config::get(string $key)`

```php
$appConfig   = Config::get('app');       // array
$dbConfig    = Config::get('db');        // array
$redisConfig = Config::get('redis');     // array
$isDebug     = Config::get('app')['debugMode'] === 'true';
```

---

## `Config::setDIBindings(array $bindings)`

Переопределяет или добавляет DI-биндинги. Вызывается после `Config::init()`:

```php
Config::setDIBindings([
    AuthTokenStorageI::class => RedisTokenStorage::class,
    LoggerInterface::class   => [
        'class' => MonologLogger::class,
        'args'  => ['/var/log/myapp.log'],
    ],
]);
```

Подробнее — в разделе [DI-контейнер](6_dependency_injection.md).

---

## Полный пример `.env`

```dotenv
ENV=dev

# HTTP
HTTP_SCHEME=https
HOST=myapp.example.com
PORT=

# Приложение
APP_NAME=MyApp
APP_VERSION=1.0.0
APP_ENV=dev
DEBUG_MODE=true
MASTER_PASSWORD=change_me_in_production

# PostgreSQL
DB_ADAPTER=pgsql
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
JWT_SECRET=minimum_32_chars_random_string_here
JWT_EXP_SECONDS=3600
JWT_ALG=HS256
```

---

## Несколько баз данных

```php
Config::init([
    'db_reporting' => [
        'adapter'   => 'pgsql',
        'host'      => '10.0.0.5',
        'port'      => 5432,
        'user'      => 'reporter',
        'password'  => 'secret',
        'dbname'    => 'reports',
        'debugMode' => false,
    ],
]);

// В Storage:
$pdo = $this->getPdo('db_reporting');
```

---

## Redis-клиент

`RedisClient` — singleton с ленивым подключением. Конфигурируется из секции `redis`:

```php
use SpsFW\Core\Redis\RedisClient;

$redis = RedisClient::getInstance();

$redis->set('key', 'value');
$redis->setex('session:123', 3600, json_encode($data));
$value = $redis->get('key');
$redis->del('key');

// Атомарный rate limiting
$count = $redis->incrWithTtl("rl:login:{$ip}", 60);
if ($count > 5) {
    throw new TooManyRequestsException('Слишком много попыток входа');
}

// Атомарный GETDEL (для refresh-токенов)
$tokenData = $redis->getdel("rt:{$selector}");

// В воркерах — закрыть перед fork, пересоздать после
$redis->close();
pcntl_fork();
// Новый процесс: $redis->connection() установит новое соединение автоматически
```

Через DI:
```php
use SpsFW\Core\Attributes\Inject;
use SpsFW\Core\Redis\RedisClient;

class MyService
{
    public function __construct(
        #[Inject] private RedisClient $redis,
    ) {}
}
```

Биндинг в `di_config.php`:
```php
use SpsFW\Core\Redis\RedisClient;

return [
    RedisClient::class => [
        'class'  => RedisClient::class,
        'shared' => true,  // singleton
    ],
];
```
