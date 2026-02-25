# Large Messages Support (Сообщения > 16MB)

## Проблема

RabbitMQ имеет лимит на размер сообщения (по умолчанию 128MB, может быть настроен иначе). Сообщения, превышающие этот лимит, будут отклоняться с ошибкой.

## Решение: Chunked Message Protocol

Фреймворк автоматически разбивает большие сообщения на чанки и собирает их обратно на стороне consumer'а.

### Как это работает

1. **Publisher** отправляет сообщение > threshold (по умолчанию 8MB)
2. **LargeMessageHandler** сжимает данные (GZIP) и разбивает на чанки
3. Каждый чанк отправляется как отдельное сообщение с метаданными
4. **Worker** получает чанки, собирает их по `messageId`
5. После получения всех чанков — декомпрессия и обработка

### Структура чанка

```json
{
  "jobName": "MyJob",
  "payload": "<base64_encoded_chunk>",
  "meta": {
    "isChunked": true,
    "messageId": "uuid-v4",
    "chunkIndex": 0,
    "totalChunks": 5,
    "originalSize": 45000000,
    "compressed": true,
    "checksum": "md5_hash"
  }
}
```

## Использование

### 1. Базовая конфигурация (по умолчанию)

```php
use SpsFW\Core\Queue\QueueClientAndPublisherFactory;

// Large message handler создаётся автоматически с настройками по умолчанию:
// - chunkSize: 8MB
// - compression: включено
// - checksum: md5

$factory = new QueueClientAndPublisherFactory(
    $rabbitConfig,
    $workerConfig
);

$publisher = $factory->createByWorkerName('my-worker');
// Большие сообщения автоматически разбиваются на чанки
```

### 2. Кастомная конфигурация

```php
use SpsFW\Core\Queue\QueueClientAndPublisherFactory;

// Создаём handler с кастомными параметрами
$largeMessageHandler = QueueClientAndPublisherFactory::createLargeMessageHandler(
    chunkSize: 10 * 1024 * 1024,  // 10MB
    enableCompression: true,       // сжатие GZIP
    checksumAlgo: 'sha256'         // более надёжный checksum
);

// Передаём в фабрику
$factory = new QueueClientAndPublisherFactory(
    $rabbitConfig,
    $workerConfig,
    $largeMessageHandler
);
```

### 3. При создании клиента напрямую

```php
$handler = QueueClientAndPublisherFactory::createLargeMessageHandler(
    chunkSize: 5 * 1024 * 1024,  // 5MB
    enableCompression: false      // без сжатия
);

$client = new RabbitMQClient(
    exchange: 'my-exchange',
    queue: 'my-queue',
    routingKey: 'my-key',
    config: $config,
    largeMessageHandler: $handler
);
```

## Параметры ChunkedMessageHandler

| Параметр | Тип | По умолчанию | Описание |
|----------|-----|--------------|----------|
| `chunkSize` | int | 8MB (8 * 1024 * 1024) | Максимальный размер одного чанка |
| `enableCompression` | bool | true | Включить сжатие GZIP |
| `checksumAlgo` | string | 'md5' | Алгоритм checksum ('md5', 'sha256') |

## Метаданные чанков

При отправке чанков автоматически добавляются AMQP headers:

```php
[
    'headers' => [
        'x-chunk-index' => 0,
        'x-total-chunks' => 5,
        'x-is-chunked' => true,
    ]
]
```

## Важное требование к Job

**Все job-классы должны корректно реализовывать `deserialize()`**, потому что при сборке чанков фреймворк всегда вызывает `deserialize()` для восстановления job.

### Правильный пример

```php
use SpsFW\Core\Queue\Attributes\QueueJob;
use SpsFW\Core\Queue\Interfaces\JobInterface;
use SpsFW\Exchange1C\Webhooks\Dto\EmployeesExchange1CDto;

#[QueueJob('employee-exchange-worker', handlerClass: EmployeeExchangeHandler::class)]
class EmployeeExchangeJob implements JobInterface
{
    public function __construct(
        readonly EmployeesExchange1CDto $employees,
        readonly string $trigger = 'unknown'
    ) {}

    public function serialize(): string
    {
        return json_encode(get_object_vars($this));
    }

    public static function deserialize(string $payload): static
    {
        $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

        // Важно: EmployeesExchange1CDto должен быть восстановлен через fromArray()
        return new self(
            EmployeesExchange1CDto::fromArray($data['employees']),
            $data['trigger'] ?? 'unknown'
        );
    }

    public function getName(): string
    {
        return 'employee-exchange-worker';
    }
}
```

### Почему это важно?

1. При отправке job сериализуется в JSON
2. При сборке чанков JSON декодируется в массив
3. При создании job вызывается `deserialize()`, который должен корректно восстановить все вложенные объекты (DTO)

## Обработка ошибок

1. **Checksum mismatch** — сообщение отклоняется (nack), буфер очищается
2. **Missing chunks** — последний чанк помечается как received, но payload не собирается пока не придут все
3. **Chunk/job timeout** — в воркере есть soft-timeout выполнения `handle()`, но timeout сборки чанков в памяти как отдельного механизма нет. Для долгоживущих буферов используйте внешний мониторинг или собственную реализацию `LargeMessageHandlerInterface`.

## Ограничения

1. **Порядок доставки** — чанки могут прийти в любом порядке. Сборка происходит по `chunkIndex`
2. **Memory usage** — при сборке больших сообщений весь payload загружается в память
3. **Один воркер** — все чанки сообщения должны обрабатываться одним и тем же воркером (prefetch=1)
4. **Idempotency** — при сбое воркера после частичной сборки, буфер очищается и сообщение нужно переотправить

## Расширение

Вы можете создать свою реализацию `LargeMessageHandlerInterface` для:

- Хранения чанков в Redis/БД вместо памяти
- Использования других алгоритмов сжатия (zstd, lz4)
- Реализации собственной логики chunking'а

```php
use SpsFW\Core\Queue\LargeMessage\LargeMessageHandlerInterface;

class RedisChunkedHandler implements LargeMessageHandlerInterface
{
    private \Redis $redis;

    public function needsChunking(mixed $payload): bool { /* ... */ }
    public function splitIntoChunks(array $payload): array { /* ... */ }
    public function addChunk(array $chunk): bool { /* ... */ }
    public function getAssembledPayload(string $messageId): ?array { /* ... */ }
    public function clearAssembly(string $messageId): void { /* ... */ }
    public function getConfig(): array { /* ... */ }
}
```
