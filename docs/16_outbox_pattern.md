# 16. Outbox Pattern (надёжная публикация в RabbitMQ)

Подробный внутренний разбор механизма, включая `LISTEN/NOTIFY`, Redis-замену для MySQL/MariaDB, lease-модель и оценки сложности, см. в `developers/queue-outbox.md`.

## Transactional and scheduled outbox

`OutboxPublisher` remains a compatibility fallback: it first tries RabbitMQ and
stores the message only when publication fails. Critical business events should
use `TransactionalOutboxPublisher`, which always stores a fully prepared
message in the same database transaction as the business change.

```php
$transactionManager->transactional(function () use ($publisher, $job, $when): void {
    $publisher->publishAt(
        $job,
        $when,
        ['deduplicationKey' => 'task-reminder:' . $job->reminderId],
    );
});
```

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
│                       │ OK → autoFlush(outbox→MQ)   │
│                       │ ERR → saveToOutbox(DB)      │
│                                                     │
│  flush()    ──►  SELECT FOR UPDATE SKIP LOCKED      │
│                  → publish each → delete → commit   │
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
    autoFlushBatch: 10,   // сколько outbox-сообщений дренировать при каждом успешном publish()
);

// Через имя воркера:
$publisher = $factory->createByWorkerNameWithOutbox('orders_worker', $outboxStorage);
```

### Публикация

API полностью совместимо с `RabbitMQQueuePublisher`:

```php
// Если RabbitMQ доступен — сообщение уходит сразу, попутно сливается до 10 outbox-записей
// Если нет — сохраняется в queue_outbox
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

`flush()` использует `SELECT FOR UPDATE SKIP LOCKED` внутри транзакции. Это значит, что несколько воркеров или cron-процессов могут вызывать `flush()` одновременно — они получат разные строки и не опубликуют одно сообщение дважды.

---

## Параметры конструктора

| Параметр         | Тип                        | По умолчанию | Описание                                      |
|------------------|----------------------------|--------------|-----------------------------------------------|
| `$publisher`     | `RabbitMQQueuePublisher`   | —            | Обёртываемый publisher                        |
| `$storage`       | `OutboxStorage`            | —            | Хранилище сообщений                           |
| `$autoFlushBatch`| `int`                      | `10`         | Авто-слив при каждом успешном `publish()`. `0` — отключить |

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
