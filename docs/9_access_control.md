# Управление доступом (Access Control)

SpsFW поддерживает два режима управления доступом:

- **Simple** — только проверка аутентификации. Достаточно, когда разграничение строится на логике в сервисах, а не на атрибутах маршрутов.
- **Full** — правила доступа (access rules), хранящиеся в БД. Используется, когда у пользователей разные наборы разрешений, которые нужно гибко менять без передеплоя.

---

## Быстрый старт: атрибуты маршрутов

Каждый маршрут по умолчанию **требует аутентификации**. Управление исключениями — через атрибуты:

| Атрибут | Смысл |
|---|---|
| `#[NoAuthAccess]` | Публичный маршрут, JWT не требуется |
| `#[AccessRulesAny([Rule::ID, ...])]` | Хотя бы одно из перечисленных правил |
| `#[AccessRulesAll([Rule::ID, ...])]` | Все перечисленные правила одновременно |

```php
use SpsFW\Core\Attributes\NoAuthAccess;
use SpsFW\Core\Attributes\AccessRulesAny;
use SpsFW\Core\Attributes\AccessRulesAll;
use App\Shared\AccessRules\AdminRules;
use App\Shared\AccessRules\ConsultantRules;

class ClientController extends RestController
{
    // Доступен всем, JWT не нужен
    #[Route('/api/public/info', ['GET'])]
    #[NoAuthAccess]
    public function publicInfo(): Response { ... }

    // Доступен любому аутентифицированному пользователю
    #[Route('/api/profile', ['GET'])]
    public function profile(): Response { ... }

    // Доступен, если есть хотя бы одно из правил
    #[Route('/api/clients', ['GET'])]
    #[AccessRulesAny([ConsultantRules::CLIENTS_ACCESS, AdminRules::FULL_ACCESS])]
    public function list(): Response { ... }

    // Доступен только при наличии ОБОИХ правил
    #[Route('/api/admin/users', ['DELETE'])]
    #[AccessRulesAll([AdminRules::FULL_ACCESS, AdminRules::USER_MANAGEMENT])]
    public function deleteUser(): Response { ... }
}
```

---

## Полный цикл: rule-based access control

### Шаг 1. Создать класс правил доступа

Создайте файл в `src/Shared/AccessRules/` (или любой другой директории проекта).

```php
<?php
// src/Shared/AccessRules/ConsultantRules.php
declare(strict_types=1);

namespace App\Shared\AccessRules;

use SpsFW\Core\Auth\AccessRule\Instances\BaseAccessRules;

class ConsultantRules extends BaseAccessRules
{
    // ROLE — метка группы, сохраняется в таблице access_rules.role
    public const string ROLE = 'CONSULTANT';

    // RULES — словарь id → человекочитаемое описание
    public const array RULES = [
        100 => 'Доступ к обратившимся',
        101 => 'Просмотр статистики',
        102 => 'Ведение дневника',
    ];

    // Именованные константы для использования в #[AccessRulesAny]
    public const int CLIENTS_ACCESS = 100;
    public const int STATS_VIEW     = 101;
    public const int NOTES_WRITE    = 102;
}
```

```php
<?php
// src/Shared/AccessRules/AdminRules.php
declare(strict_types=1);

namespace App\Shared\AccessRules;

use SpsFW\Core\Auth\AccessRule\Instances\BaseAccessRules;

class AdminRules extends BaseAccessRules
{
    public const string ROLE = 'ADMIN';

    public const array RULES = [
        400 => 'Управление пользователями',
        401 => 'Просмотр аудит-лога',
        402 => 'Полный доступ',
    ];

    public const int USER_MANAGEMENT = 400;
    public const int AUDIT_VIEW      = 401;
    public const int FULL_ACCESS     = 402;
}
```

**Правила:**
- ID правил уникальны глобально (не только в рамках одного класса). Используйте диапазоны: консультанты 100–199, администраторы 400–499 и т.д.
- Каждая константа должна совпадать с ключом в `RULES`.

### Шаг 2. Зарегистрировать в bootstrap.php

```php
// bootstrap.php
use SpsFW\Core\Auth\Util\AccessRuleRegistry;
use App\Shared\AccessRules\ConsultantRules;
use App\Shared\AccessRules\AdminRules;

// Регистрируем ВСЕ группы правил проекта
// Без этого шага AccessRuleRegistry не знает о правилах и атрибуты маршрутов не работают
AccessRuleRegistry::register([ConsultantRules::class, AdminRules::class]);
```

Это нужно сделать **до** первого запроса — сразу после `Config::init()`.

### Шаг 3. Заполнить правила при создании пользователя

При регистрации/создании пользователя нужно записать его правила в таблицу `users__access_rules`.

```php
// Например, в AuthStorage или UserStorage
public function seedAccessRules(string $userId, string $role): void
{
    $rules = match ($role) {
        'admin'      => array_fill_keys(AdminRules::getRuleIds(), [])
                      + array_fill_keys(ConsultantRules::getRuleIds(), []),
        default      => array_fill_keys(ConsultantRules::getRuleIds(), []),
    };

    foreach ($rules as $ruleId => $value) {
        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO users__access_rules (user_uuid, access_rule_id, value)
             VALUES (:user_uuid, :rule_id, :value)'
        );
        $stmt->execute([
            'user_uuid' => $userId,
            'rule_id'   => $ruleId,
            'value'     => json_encode($value),
        ]);
    }
}
```

Правила из `users__access_rules` автоматически загружаются в JWT payload при логине и хранятся в `$user->accessRules`.

### Шаг 4. Использовать в контроллерах

```php
#[Route('/api/clients', ['GET'])]
#[AccessRulesAny([ConsultantRules::CLIENTS_ACCESS])]
public function list(): Response
{
    // Сюда попадут только пользователи с правилом CLIENTS_ACCESS
    return Response::json($this->clientService->getAll());
}
```

---

## AccessChecker::getValue() — правила со значениями (values)

Иногда правило нужно параметризовать. Например, консультант может видеть только определённые разделы данных — список их ID хранится как **value** правила.

```php
// При создании пользователя — задать значение
$rules = [
    ConsultantRules::CLIENTS_ACCESS => ['branch_ids' => ['uuid-1', 'uuid-2']],
    ConsultantRules::STATS_VIEW     => [], // пустое значение = просто наличие правила
];
```

```php
// В контроллере — получить значение
use SpsFW\Core\Auth\Util\AccessChecker;

#[Route('/api/clients', ['GET'])]
#[AccessRulesAny([ConsultantRules::CLIENTS_ACCESS])]
public function list(): Response
{
    // Возвращает массив из value, или [] если value пустое
    $accessData = AccessChecker::getValue(ConsultantRules::CLIENTS_ACCESS);
    $branchIds  = $accessData['branch_ids'] ?? null;

    return Response::json($this->clientService->getAll($branchIds));
}
```

`getValue()` бросит `AuthorizationException`, если пользователь не аутентифицирован. Используйте только за `#[AccessRulesAny]` или `#[AccessRulesAll]`.

---

## Таблицы БД

Система использует две таблицы:

```sql
-- Справочник правил (auto-populated при addAccessRules)
CREATE TABLE access_rules (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,   -- имя константы, напр. CLIENTS_ACCESS
    description VARCHAR(255) NULL,       -- текстовое описание из RULES
    role        VARCHAR(50) NULL,        -- ROLE класса, напр. CONSULTANT
    UNIQUE KEY uk_name (name)
);

-- Права конкретного пользователя
CREATE TABLE users__access_rules (
    id             BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_uuid      CHAR(36) NOT NULL,       -- UUID пользователя (BINARY(16) в MySQL)
    access_rule_id INT NOT NULL,
    value          JSON NULL,               -- произвольные данные правила
    UNIQUE KEY uk_user_rule (user_uuid, access_rule_id)
);
```

Миграция для этих таблиц входит в состав фреймворка и применяется автоматически через `PhinxConfigFactory`.

---

## Текущий пользователь

В любом месте кода можно получить аутентифицированного пользователя:

```php
use SpsFW\Core\Auth\Instances\Auth;

$user = Auth::getOrThrow();   // throws AuthorizationException если нет токена
$user = Auth::getOrNull();    // возвращает null если нет токена

$user->uuid          // UUID пользователя
$user->accessRules   // array<int, mixed> — правила из JWT
```

---

## Когда НЕ нужны access rules

Используйте простой role-check в сервисе, если:
- Разграничение жёсткое и не меняется (admin vs user)
- Нет необходимости в values
- Нет нужды менять права конкретному пользователю без смены роли

```php
// Простая проверка роли — без таблицы access_rules
$user = Auth::getOrThrow();
if ($user->role !== 'admin') {
    throw new ForbiddenException();
}
```
