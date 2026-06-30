# Очереди и transactional outbox: внутренний гайд

Этот документ описывает, как в SpsFW устроены публикация сообщений, relay, wakeup-стратегии и внутренние ограничения transactional outbox.

Если нужна короткая прикладная инструкция, сначала смотри:

- `../16_outbox_pattern.md`
- `../13_queue_reliability_update.md`

Эта страница отвечает на вопрос «почему это работает именно так» и «какие границы у решения».

## Что изменилось в SpsFW

Фичи transactional outbox и устойчивой очереди добавили в runtime несколько новых слоёв:

- `RabbitMQQueuePublisher::prepare()` — сообщение собирается в нормализованный `PreparedQueueMessage` до публикации;
- `OutboxPublisher` — compatibility fallback: пробует отправить в RabbitMQ, а при сбое сохраняет подготовленное сообщение в outbox и позже сливает накопленные записи;
- `TransactionalOutboxPublisher` — сохраняет подготовленное сообщение в `queue_outbox` вместо немедленной отправки в брокер;
- `TransactionManager` — даёт `afterCommit()`-хуки, чтобы wakeup происходил после успешного commit;
- `OutboxStorage` — хранит `available_at`, `next_attempt_at`, `deduplication_key`, `claim_token`, `claimed_until`, `last_error`;
- `OutboxRelay` — забирает due rows, публикует их и удаляет после успешной доставки;
- `OutboxRelayRunner` — держит цикл релэя и выбирает стратегию ожидания;
- `PostgresOutboxWakeup` — использует PostgreSQL `LISTEN/NOTIFY`;
- `RedisOutboxWakeup` — замена для окружений без `LISTEN/NOTIFY`, прежде всего MySQL/MariaDB;
- `SleepOutboxWakeup` — fallback без дополнительной инфраструктуры;
- `ReconnectablePreparedMessageTransport` — пересоздаёт AMQP transport после сетевых сбоев;
- `QueueClientAndPublisherFactory` — единая точка создания publisher’ов с outbox и без него.

## Когда использовать какой режим

| Режим | Когда использовать | Что он гарантирует |
|---|---|---|
| Direct publish | Сообщение можно потерять при сбое RabbitMQ | Минимальная задержка, простая схема |
| Outbox publish | Сообщение можно восстановить из БД | Доставку можно отложить до восстановления брокера |
| Transactional outbox | Бизнес-запись и публикация должны быть атомарны | Сообщение не потеряется между сохранением данных и публикацией |

Правило практическое: если событие можно пересоздать из бизнес-данных, direct publish допустим. Если нельзя — используй outbox. Если публикация должна жить в одной транзакции с данными, используй transactional outbox.

## Как это проходит по шагам

1. Код создаёт job и вызывает publisher.
2. Publisher собирает `PreparedQueueMessage` с `message_id`, `available_at`, routing metadata и headers.
3. В outbox-режиме сообщение пишется в `queue_outbox`.
4. Если `TransactionManager` передан и бизнес-операция обёрнута в `transactional()`, wakeup откладывается до commit через `TransactionManager::afterCommit()`. Если менеджера нет, wakeup вызывается сразу — корректность не теряется, меняется только latency.
5. Relay берёт due rows через `SELECT ... FOR UPDATE SKIP LOCKED`.
6. Успешно опубликованные строки удаляются.
7. При ошибке строка остаётся в outbox, получает `attempts + 1`, `last_error` и новый `next_attempt_at`.
8. После окончания lease строка снова становится доступной для захвата.

Важно: даже после confirm от RabbitMQ возможен дубль, если процесс упал между confirm и удалением строки. Поэтому handler’ы должны быть идемпотентными.

## Wakeup-стратегии

### PostgreSQL

`PostgresOutboxWakeup` использует `LISTEN/NOTIFY`.

Это самый прямой вариант: база сама посылает сигнал о новом доступном сообщении, а relay выходит из ожидания без лишнего polling.

### MySQL / MariaDB

У MySQL и MariaDB нет эквивалента `LISTEN/NOTIFY` с теми же семантиками. В SpsFW вместо него используется `RedisOutboxWakeup`.

Там wakeup устроен как лёгкий сигнал:

- `notify()` делает `LPUSH` в короткоживущий Redis-ключ;
- `wait()` блокируется на `BRPOP`;
- ключ живёт ограниченное время через TTL, чтобы не копить мусор.

Это не очередь бизнес-сообщений. Это только сигнал для пробуждения relay. Источник истины — таблица `queue_outbox`.

### Минимальная конфигурация

Если Redis недоступен или не нужен, можно использовать `SleepOutboxWakeup`.

Это самый простой вариант, но он повышает задержку реакции на новые сообщения. Для низкого трафика и простых сред это допустимо, для активной системы — нежелательно.

## Алгоритмическая оценка

Сложность здесь в основном определяется не CPU, а сетью и индексами. Грубая оценка такая:

| Операция | Оценка | Что реально доминирует |
|---|---|---|
| `savePrepared()` | `O(1)` на одно сообщение | один DB insert + JSON encode |
| `claimDue(limit)` | `O(k)` для батча из `k` строк | индексированный выбор due rows, row-locking и update lease |
| `markPublished()` | `O(1)` | delete по primary key + claim token |
| `releaseFailed()` | `O(1)` | update строки и перенос `next_attempt_at` |
| `notify()` / `wait()` | `O(1)` | системный вызов или блокирующее ожидание |
| `OutboxRelayRunner::run()` | `O(1)` на итерацию цикла | в пустом цикле решает только timeout и wakeup |

По памяти:

- `OutboxRelay` держит в памяти только текущий batch, то есть `O(k)`;
- `TransactionManager` хранит список `afterCommit`-callback’ов, то есть `O(m)` по числу зарегистрированных действий;
- `RedisOutboxWakeup` и `SleepOutboxWakeup` не накапливают состояние между итерациями.

Практический вывод: узкое место — это не PHP-циклы, а частота DB round-trip и сеть до RabbitMQ. Поэтому batch processing, lease и wakeup-стратегия важнее микроскопической оптимизации кода.

## Ключевые поля queue_outbox

| Поле | Роль |
|---|---|
| `payload` | Нормализованное тело сообщения |
| `properties` | AMQP properties и headers |
| `routing_key` / `exchange` | Куда и как публиковать |
| `message_id` | Стабильный идентификатор сообщения |
| `available_at` | Когда сообщение можно публиковать впервые |
| `next_attempt_at` | Когда сообщение можно пробовать снова |
| `deduplication_key` | Уникальность на уровне БД |
| `claim_token` | Lease токен текущего релэя |
| `claimed_until` | Когда lease истекает |
| `attempts` | Счётчик повторных попыток |
| `last_error` | Последняя ошибка публикации |

`deduplication_key` полезен, когда сообщение можно однозначно вывести из бизнес-идентификатора. Типичный шаблон: `entity:{id}:event:{name}`.

## Как использовать в коде

### Неблокирующая публикация

```php
$publisher = $factory->createByWorkerNameWithOutbox('notifications_worker', $outboxStorage);
$publisher->publish($job, [
    'deduplicationKey' => 'notification:' . $notificationId,
]);
```

### Публикация внутри бизнес-транзакции

```php
$transactionManager->transactional(function () use (
    $orderStorage,
    $publisher,
    $order,
): void {
    $orderStorage->save($order);

    $publisher->publish($job, [
        'deduplicationKey' => 'order:' . $order->id . ':created',
    ]);
});
```

### Relay loop

```php
$runner = new OutboxRelayRunner($relay, $outboxStorage, $wakeup, batchSize: 100);
$runner->run(static fn (): bool => false);
```

Relay обычно живёт в отдельном long-running процессе или в фоновом воркере. Таблица `queue_outbox` остаётся источником истины, wakeup только уменьшает latency.

## Рекомендации по эксплуатации

- Используй outbox только там, где потеря сообщения действительно дорога.
- Не воспринимай outbox как гарантию exactly-once.
- Держи handler’ы идемпотентными.
- Для PostgreSQL используй native `LISTEN/NOTIFY`, если он доступен.
- Для MySQL/MariaDB планируй Redis как wakeup-слой.
- Если Redis не подходит, принимай latency trade-off от `SleepOutboxWakeup`.
- Следи за `attempts` и `last_error`, а не только за количеством сообщений в очереди.
- Если бизнес-транзакция и outbox живут на разных DB-конфигах, атомарность теряется.

## Что читать дальше

- `../16_outbox_pattern.md` — прикладное описание outbox-паттерна.
- `../13_queue_reliability_update.md` — устойчивость очередей, DLQ и наблюдаемость.
- `../11_queue_workes.md` — базовый workflow очередей, если нужен legacy-обзор.
