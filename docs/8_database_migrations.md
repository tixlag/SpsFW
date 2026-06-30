# Миграции базы данных

SpsFW использует [Phinx](https://phinx.org/) для управления миграциями. Генерация схем через код отсутствует — миграции пишутся вручную на чистом SQL, что даёт полный контроль над схемой.

---

## Быстрый старт

### 1. phinx.php — три строки

В корне проекта создайте `phinx.php`:

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';
return \SpsFW\Core\Phinx\PhinxConfigFactory::create(__DIR__);
```

`PhinxConfigFactory` автоматически:
- загружает `.env` и `.env.{APP_ENV}`
- находит все папки `migrations/` — в проекте и во фреймворке — через маркер `MigrationRequiredClass.php`
- возвращает готовый конфиг Phinx

Переменные окружения в `.env`:
```dotenv
DB_ADAPTER=mysql       # mysql | pgsql
DB_HOST=localhost
DB_PORT=3306
DB_NAME=myapp
DB_USER=root
DB_PASS=secret
DB_CHARSET=utf8mb4
APP_ENV=dev
```

### 2. Структура директорий

```
src/
  Orders/
    MigrationRequiredClass.php   ← маркер: здесь живёт доменная область
    migrations/
      20240101120000_create_orders_table.php
  Users/
    MigrationRequiredClass.php
    migrations/
      20240201080000_create_users_table.php
db/
  migrations/                    ← общие миграции проекта (всегда сканируется)
  seeds/
phinx.php
```

`MigrationRequiredClass.php` — пустой PHP-класс, его единственная роль — указать Phinx на соседнюю папку `migrations/`. Фабрика находит его рекурсивно по всему `src/`.

```php
<?php
// src/Orders/MigrationRequiredClass.php
namespace App\Orders;

class MigrationRequiredClass {}
```

### 3. Создать миграцию

```bash
composer create:dev CreateOrdersTable
```

Phinx предложит выбрать папку — выберите нужную доменную область. Если нужна общая миграция — выберите `db/migrations`.

### 4. Написать миграцию

```php
<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateOrdersTable extends AbstractMigration
{
    public function change(): void
    {
        $this->execute('
            CREATE TABLE IF NOT EXISTS orders (
                id          BINARY(16) NOT NULL PRIMARY KEY,
                user_id     BINARY(16) NOT NULL,
                total       DECIMAL(10,2) NOT NULL DEFAULT 0,
                status      ENUM("pending","paid","cancelled") NOT NULL DEFAULT "pending",
                created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_user_id (user_id),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }
}
```

Для необратимых изменений используйте `up()` / `down()` вместо `change()`:

```php
public function up(): void
{
    $this->execute('ALTER TABLE orders ADD COLUMN notes TEXT NULL');
}

public function down(): void
{
    $this->execute('ALTER TABLE orders DROP COLUMN notes');
}
```

### 5. Запустить миграции

```bash
composer migrate:dev       # dev-окружение
composer migrate:prod      # prod-окружение
composer status:dev        # посмотреть статус
composer rollback:dev      # откатить последнюю
```

---

## Команды

| Команда | Описание |
|---|---|
| `composer migrate:dev` | Применить все ожидающие миграции (dev) |
| `composer migrate:prod` | Применить все ожидающие миграции (prod) |
| `composer rollback:dev` | Откатить последнюю миграцию (dev) |
| `composer rollback:prod` | Откатить последнюю миграцию (prod) |
| `composer create:dev <Name>` | Создать файл новой миграции (dev) |
| `composer create:prod <Name>` | Создать файл новой миграции (prod) |
| `composer status:dev` | Показать статус миграций (dev) |
| `composer status:prod` | Показать статус миграций (prod) |

---

## Миграции фреймворка

Фреймворк включает собственные миграции для таблиц:

| Таблица | Модуль |
|---|---|
| `users__refresh_tokens` | Auth / JWT refresh tokens |
| `users__access_rules` | Auth / Access control |
| `access_rules` | Auth / Access control |
| `queue_outbox` | Queue / transactional outbox |

Они применяются автоматически вместе с миграциями проекта — `PhinxConfigFactory` находит их по маркерам внутри фреймворка.

---

## Смена окружения

Окружение определяется переменной `APP_ENV`. Phinx использует секцию `default` в конфиге, значения берутся из `$_ENV`. Чтобы запустить миграции на prod-базе с dev-машины:

```bash
APP_ENV=prod composer migrate:prod
```

---

## Типичные паттерны

### UUID как PRIMARY KEY

В MySQL/MariaDB UUID хранится в `BINARY(16)` для эффективного хранения и индексирования.
Для конвертации используются встроенные функции `UUID_TO_BIN()` и `BIN_TO_UUID()`.

```sql
-- MySQL / MariaDB
id BINARY(16) NOT NULL PRIMARY KEY

-- Вставка:
INSERT INTO orders (id, ...) VALUES (UUID_TO_BIN(:id), ...)
-- Чтение (возвращает строку вида "550e8400-e29b-41d4-a716-446655440000"):
SELECT BIN_TO_UUID(id) AS id, ... FROM orders WHERE id = UUID_TO_BIN(:id)
```

В PHP передаём обычную UUID-строку, конвертацию делает сам SQL:

```php
$stmt = $pdo->prepare('SELECT BIN_TO_UUID(id) AS id, name FROM orders WHERE id = UUID_TO_BIN(:id)');
$stmt->execute(['id' => $uuid]); // $uuid = '550e8400-e29b-41d4-a716-446655440000'
```

В PostgreSQL UUID хранится нативно — никаких обёрток не нужно:

```sql
-- PostgreSQL
id UUID PRIMARY KEY DEFAULT gen_random_uuid()
```

### Soft delete

```sql
deleted_at DATETIME NULL,
INDEX idx_deleted_at (deleted_at)
```

Все запросы должны добавлять `WHERE deleted_at IS NULL`.

### Версионирование таблицы (updated_at)

```sql
created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
```
