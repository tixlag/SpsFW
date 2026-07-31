# 16. Outbox Pattern (надёжная публикация в RabbitMQ)

Подробный внутренний разбор механизма, включая `LISTEN/NOTIFY`, Redis-замену для MySQL/MariaDB, lease-модель и оценки сложности, см. в `developers/queue-outbox.md`.

## Transactional and scheduled outbox

`OutboxPublisher` remains a compatibility fallback: it first tries RabbitMQ and
stores the message only when publication fails. Critical business events should
use a `TransactionalOutboxPublisher` built by `createForTransaction()`: it
always stores a fully prepared message, and the factory proves that the outbox
write can share the business transaction.

```php
$transactionManager = new TransactionManager($businessStorage->getPdo());
$publisher = $factory->createForTransaction(
    queueName: 'task-reminders',
    transactionManager: $transactionManager,
    exchange: 'crm.reminders',
    routingKey: 'reminder.deliver',
    storage: $outboxStorage,
    wakeup: $wakeup,
);

$transactionManager->transactional(
    function () use ($businessStorage, $businessEntity, $publisher, $job, $when): void {
        $businessStorage->save($businessEntity);
        $publisher->publishAt(
            $job,
            $when,
            ['deduplicationKey' => 'task-reminder:' . $job->reminderId],
        );
    },
);
```

`createForTransaction()` verifies that the manager and `OutboxStorage` use the
same PDO object. This identity check matters because transactions are local to a
connection: two PDO instances pointing to the same database still cannot share
one transaction. The worker-config equivalent is
`createByWorkerNameForTransaction()`.

The older `createTransactional()` / `createByWorkerNameTransactional()` methods
remain available with their original nullable-manager signatures for backwards
compatibility. They do not perform the strict connection proof; prefer the new
entrypoints whenever code claims atomicity with a business write.

`OutboxRelay` claims due rows with `FOR UPDATE SKIP LOCKED`, commits the lease,
publishes with AMQP mandatory routing and publisher confirms, then deletes the
claimed row. A crash after broker confirmation can produce a duplicate, so job
handlers must remain idempotent.

The relay transport should be wrapped in
`ReconnectablePreparedMessageTransport`. A failed AMQP connection is discarded;
the next retry creates a fresh client and continues draining the same leased
outbox table after RabbitMQ recovers.

Wakeup strategies:

- `PostgresOutboxWakeup`: PostgreSQL `LISTEN/NOTIFY`;
- `RedisOutboxWakeup`: Redis wakeup для MySQL/MariaDB и любых окружений без PostgreSQL notifications;
- `SleepOutboxWakeup`: bounded fallback без дополнительной инфраструктуры.

The outbox schema supports PostgreSQL 9.5+, MySQL 8+ and MariaDB 10.6+.

For a framework checkout mounted outside `vendor/` (for example `/opt/spsfw` in
Docker), set `SPSFW_PROJECT_ROOT=/app`. DI, route and job-registry caches will
then be written to the application `.cache` directory instead of the read-only
framework source.

## Зачем

При публикации задачи в RabbitMQ брокер может быть временно недоступен. Простой вызов `$publisher->publish()` в таком случае упадёт с исключением и задача будет потеряна.

**Outbox Pattern** решает это: при ошибке сообщение сохраняется в БД (`queue_outbox`). После восстановления соединения оно автоматически публикуется и удаляется из таблицы.

---

## Архитектура

```
┌────────────────────────────────────────────────────┐
│  OutboxPublisher (декоратор)                        │
│                                                     │
│  publish()  ──►  RabbitMQQueuePublisher             │
│                       │ OK → return                 │
│                       │ ERR → saveToOutbox(DB)      │
│                                                     │
│  OutboxRelay ──►  claim lease → publish confirm     │
│                  → delete row / release with retry  │
└────────────────────────────────────────────────────┘
```

---

## Настройка миграции

Outbox использует таблицу `queue_outbox`. Запустите миграцию (Phinx найдёт её автоматически через `MigrationRequiredClass`):

```bash
vendor/bin/phinx migrate
```

Таблица совместима с PostgreSQL 9.5+, MySQL 8.0+ и MariaDB 10.6+ (использует `SELECT FOR UPDATE SKIP LOCKED`).

---

## Использование

### Через фабрику (рекомендуется)

```php
use SpsFW\Core\Queue\QueueClientAndPublisherFactory;
use SpsFW\Core\Queue\Outbox\OutboxStorage;
use SpsFW\Core\Storage\PdoStorage;

// OutboxStorage берёт соединение из DI/конфига так же, как другие PdoStorage
$outboxStorage = $container->get(OutboxStorage::class);

$factory = new QueueClientAndPublisherFactory($rabbitConfig);

// createWithOutbox — возвращает OutboxPublisher вместо RabbitMQQueuePublisher
$publisher = $factory->createWithOutbox(
    queueName:      'orders',
    exchange:       'orders.exchange',
    routingKey:     'order.created',
    storage:        $outboxStorage,
    autoFlushBatch: 0,    // автодренирование отключено; доставку выполняет отдельный relay
);

// Через имя воркера:
$publisher = $factory->createByWorkerNameWithOutbox('orders_worker', $outboxStorage);
```

### Публикация

API полностью совместимо с `RabbitMQQueuePublisher`:

```php
// Если RabbitMQ доступен — сообщение уходит сразу.
// Если нет — сохраняется в queue_outbox для отдельного relay.
$publisher->publish(new CreateOrderJob($orderId));

// Отложенная доставка (publishAt тоже работает через outbox)
$publisher->publishAt(new CreateOrderJob($orderId), new \DateTimeImmutable('+5 minutes'));
```

### Ручной слив из cron/воркера

```php
// Слить до 100 сообщений; вернёт количество успешно опубликованных
$flushed = $publisher->flush(100);
```

Пример cron-команды:

```php
// src/Cron/FlushOutboxCommand.php
$flushed = $publisher->flush(200);
echo "Flushed: $flushed\n";
```

---

## Конкурентность

`flush()` использует lease-модель `OutboxRelay`: строки захватываются на ограниченное время,
публикуются с RabbitMQ publisher confirms и удаляются только после подтверждения. Ошибка одной
строки не откатывает уже обработанные строки и записывается в `attempts`, `last_error` и
`next_attempt_at`.

---

## Параметры конструктора

| Параметр         | Тип                        | По умолчанию | Описание                                      |
|------------------|----------------------------|--------------|-----------------------------------------------|
| `$publisher`     | `RabbitMQQueuePublisher`   | —            | Обёртываемый publisher                        |
| `$storage`       | `OutboxStorage`            | —            | Хранилище сообщений                           |
| `$autoFlushBatch`| `int`                      | `0`          | Не рекомендуется; best-effort flush после publish. `0` — отключить |

---

## Схема таблицы

| Колонка       | Тип (pgsql / mysql)              | Описание                      |
|---------------|----------------------------------|-------------------------------|
| `id`          | UUID / CHAR(36)                  | Первичный ключ                |
| `payload`     | JSONB / LONGTEXT                 | Тело сообщения (JSON)         |
| `properties`  | JSONB / TEXT                     | AMQP-свойства (JSON)          |
| `routing_key` | VARCHAR(255)                     | Ключ маршрутизации            |
| `exchange`    | VARCHAR(255)                     | Имя exchange                  |
| `attempts`    | INT                              | Число попыток (для аналитики) |
| `created_at`  | TIMESTAMPTZ / DATETIME(3)        | Время добавления              |
| `message_id`  | VARCHAR(255)                     | Стабильный ID сообщения       |
| `available_at`| TIMESTAMPTZ / DATETIME(6)        | Не публиковать раньше времени |
| `next_attempt_at` | TIMESTAMPTZ / DATETIME(6)    | Следующая попытка relay       |
| `deduplication_key` | VARCHAR(255), UNIQUE        | Идемпотентная запись          |
| `claim_token` | VARCHAR(36), nullable             | Lease конкретного relay       |
| `claimed_until` | TIMESTAMPTZ / DATETIME(6)      | Срок lease                    |
| `last_error`  | TEXT, nullable                    | Последняя ошибка публикации   |
