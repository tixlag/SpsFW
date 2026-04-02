# 16. Outbox Pattern (надёжная публикация в RabbitMQ)

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
