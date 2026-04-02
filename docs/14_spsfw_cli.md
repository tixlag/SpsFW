# CLI-утилита spsfw

`spsfw` — Bash-скрипт для генерации стандартных разделов (chapters) и API-маршрутов. Избавляет от ручного создания однотипных файлов.

---

## Установка

Скрипт находится в `vendor/tixlag/php-framework/example/bin/spsfw/spsfw` (или в `bin/spsfw/spsfw` вашей копии).

**Вариант 1 — симлинк в PATH (рекомендуется):**
```bash
sudo ln -s /absolute/path/to/bin/spsfw/spsfw /usr/local/bin/spsfw
chmod +x /absolute/path/to/bin/spsfw/spsfw
```

**Вариант 2 — запуск напрямую из корня проекта:**
```bash
./vendor/tixlag/php-framework/example/bin/spsfw/spsfw --help
```

**Вариант 3 — добавить в `composer.json` как скрипт:**
```json
{
  "scripts": {
    "spsfw": "./vendor/tixlag/php-framework/example/bin/spsfw/spsfw"
  }
}
```
```bash
composer run spsfw -- make:chapter Users
```

---

## Первоначальная настройка (`--init`)

Перед первым использованием укажите корень проекта и неймспейс:

```bash
# Указать корень проекта (где находится папка src/)
spsfw --set-root /var/www/my-project

# Указать базовый namespace (соответствует autoload.psr-4 в composer.json)
spsfw --set-namespace App

# Проверить текущие настройки
spsfw --show-paths
```

Настройки сохраняются в файл `.spsfw-config` рядом со скриптом и переиспользуются при следующих запусках.

> **Пример:** если в `composer.json` у вас `"App\\": "src/"`, то `--set-namespace App`.

---

## `make:chapter` — создание раздела

Создаёт стандартную структуру директорий и файлов для нового бизнес-раздела.

### Синтаксис

```bash
spsfw make:chapter <Name>
spsfw make:chapter <Parent/Child>
```

### Что создаётся

```
src/
└── Users/                          # namespace: App\Users
    ├── Controllers/
    │   └── UsersController.php
    ├── Services/
    │   └── UsersService.php
    ├── Storages/
    │   └── UsersStorage.php
    ├── Entities/
    │   ├── migrations/             # пустая папка для Phinx-миграций
    │   ├── MigrationRequiredClass.php
    │   └── .gitkeep
    ├── DTOs/
    │   └── .gitkeep
    └── Enums/
        └── .gitkeep
```

### Примеры

```bash
# Одиночный раздел
spsfw make:chapter Users

# Вложенный (namespace: App\Products\Catalog)
spsfw make:chapter Products/Catalog

# Глубокий (namespace: App\Shop\Cart\Items)
spsfw make:chapter Shop/Cart/Items
```

### Содержимое генерируемых файлов

**`UsersController.php`:**
```php
#[Controller]
#[OA\Tag(name: 'users', description: 'API для работы с users')]
class UsersController extends RestController
{
    public function __construct(
        #[Inject] private UsersService $usersService
    ) {
        parent::__construct();
    }

    // TODO: Add routes here
}
```

**`UsersService.php`:**
```php
class UsersService
{
    public function __construct(
        #[Inject] private UsersStorage $usersStorage
    ) {}
}
```

**`UsersStorage.php`:**
```php
class UsersStorage extends PdoStorage
{
    // TODO: Add storage methods here
}
```

---

## `make:api` — добавление роута

Добавляет метод в существующий контроллер **и** соответствующий метод в сервис.

### Синтаксис

```bash
spsfw make:api <Chapter> '<route>' <methodName> <HTTP_METHOD> [-v]
```

| Аргумент | Описание |
|---|---|
| `Chapter` | Название раздела (совпадает с `make:chapter`) |
| `route` | URL маршрута, параметры в фигурных скобках |
| `methodName` | Название метода (camelCase) |
| `HTTP_METHOD` | GET, POST, PUT, DELETE, PATCH или через запятую |
| `-v` | Verbose — генерирует шаблонный ответ с `status` и `msg` |

### Примеры

```bash
# GET /api/users — вернуть список
spsfw make:api Users '/api/users' getAll GET

# GET /api/users/{id} — по ID (с параметром в сигнатуре)
spsfw make:api Users '/api/users/{id}' getById GET

# POST /api/users — с verbose-шаблоном ответа
spsfw make:api Users '/api/users' create POST -v

# PUT /api/users/{id} с типизированным параметром
spsfw make:api Users '/api/users/{id:int}' update PUT

# DELETE /api/users/{id}
spsfw make:api Users '/api/users/{id}' delete DELETE

# Вложенный раздел
spsfw make:api Products/Catalog '/api/catalog/{uuid}' getItem GET

# Несколько HTTP-методов
spsfw make:api Users '/api/users/{id}' upsert 'PUT,PATCH'
```

### Результат `make:api`

Для `spsfw make:api Users '/api/users/{id}' getById GET`:

**В контроллере добавляется:**
```php
#[Route('/api/users/{id}', httpMethods: ['GET'])]
public function getById(string $id): Response
{
    return Response::json(
        $this->usersService->getById($id)
    );
}
```

**В сервисе добавляется:**
```php
public function getById(string $id)
{
    // TODO: Implement getById() method
}
```

### Интерактивный режим

Запуск без аргументов переходит в интерактивный режим:
```bash
spsfw make:api
# Введите название раздела: Users
# Введите маршрут: /api/users/{id}
# Введите имя метода: getById
# Введите HTTP методы: GET
# Добавить шаблонный ответ? (y/N): n
```

---

## Вспомогательные команды

```bash
spsfw --help              # Справка и примеры
spsfw --version           # Версия утилиты
spsfw --show-paths        # Текущий корень проекта, target dir и namespace
spsfw --set-root <path>   # Задать корень проекта
spsfw --set-namespace <ns> # Задать базовый namespace
```

---

## Типичный рабочий процесс

```bash
# 1. Настроить (один раз)
spsfw --set-namespace App
spsfw --set-root /var/www/my-project

# 2. Создать новый раздел
spsfw make:chapter Deals

# 3. Добавить роуты
spsfw make:api Deals '/api/deals' getAll GET
spsfw make:api Deals '/api/deals' create POST -v
spsfw make:api Deals '/api/deals/{id}' getById GET
spsfw make:api Deals '/api/deals/{id}' update PUT
spsfw make:api Deals '/api/deals/{id}' delete DELETE

# 4. Создать миграцию для сущностей раздела
vendor/bin/phinx create CreateDealsTable -c phinx.php
# Phinx предложит выбрать путь — выбрать migrations/ внутри Deals/Entities/

# 5. Применить миграцию
vendor/bin/phinx migrate -c phinx.php

# 6. Прогреть кеш (сброс route/DI кеша)
php preload.php
```

---

## Примечания

- Если раздел не существует при вызове `make:api` — утилита предложит его создать
- Если метод уже существует в контроллере или сервисе — вернёт ошибку (не перезаписывает)
- Параметры типа `{id:int}` превращаются в `int $id` в сигнатуре метода
- Конфиг `.spsfw-config` хранится рядом со скриптом — при обновлении фреймворка он сохраняется
