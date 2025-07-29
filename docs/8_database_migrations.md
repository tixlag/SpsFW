# Миграции базы данных (Database Migrations)

Фреймворк предоставляет инструменты для управления схемой базы данных через миграции.

## Определение схемы

Схема таблицы определяется в классе, наследующем `SpsFW\Core\Db\Migration\MigrationsSchema`.

### Базовый класс `MigrationsSchema`

### Пример определения схемы

```php
<?php
namespace App\Database\Schemas;

use SpsFW\Core\Db\Migration\MigrationsSchema;

class UserSchema extends MigrationsSchema
{
    public const string TABLE_NAME = 'users';
    public const array VERSIONS = [
        '1.0' => [
            'description' => 'Create users table',
            'up' => /** @lang MariaDB */ '
                CREATE TABLE IF NOT EXISTS users (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(255) NOT NULL,
                    email VARCHAR(255) NOT NULL UNIQUE,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )',
            'down' => /** @lang MariaDB */ 'DROP TABLE users;'
        ],
        // Можно добавить новые версии для изменения схемы
    ];
}
?>
```

## Команды Composer

Для работы с миграциями используются скрипты Composer:

*   `composer migration:generate:dev` / `composer migration:generate:prod`: Генерирует SQL-скрипты миграций из схем.
*   `composer migration:run:dev` / `composer migration:run:prod`: Выполняет ожидающие миграции.
*   `composer migration:status:dev` / `composer migration:status:prod`: Показывает статус миграций.
*   `composer migration:auto:dev` / `composer migration:auto:prod`: Автоматически генерирует и выполняет миграции.
