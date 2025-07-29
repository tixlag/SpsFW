# Конфигурация (Configuration)

Основная конфигурация загружается из переменных окружения (`.env`) и устанавливается через `SpsFW\Core\Config::init()`.

## Файл `.env`

Используется для хранения конфигурации среды выполнения.

### Пример `.env`

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

## Класс `Config`

### Метод `Config::init`

```php
Config::init(array $customConfig = [])
```

Загружает конфигурацию из `$_ENV` и объединяет её с `$customConfig`.

### Метод `Config::get`

```php
Config::get($key)
```

Получает значение конфигурации по ключу (например, `Config::get('app.name')`).

### Метод `Config::setDIBindings`

Описан в разделе [Внедрение зависимостей](6_dependency_injection.md).