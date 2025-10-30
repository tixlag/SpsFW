# Инструкция по использованию системы очередей RabbitMQ

## Краткий воркфлоу для разработчика

**5 шагов для создания новой очереди:**

1. **Написать Job и Handler классы** с атрибутами `#[QueueJob]` и `#[JobHandler]`
2. **Зарегистрировать в JobRegistry** (автоматически после перезапуска контейнера _главное указать атрибуты_)
3. **Добавить конфиг воркера в DI** в `WorkerConfig`
4. **Запустить воркера:** `php bin/worker.php my_worker`
5. **Отправлять задачи** используя `WorkerConfig` для получения параметров очереди

```php
// Где угодно в коде - отправляем задачу
$queueConfig = $this->workerConfig->getQueueConfig('my_worker');
$job = new MyJob($data);
$publisher = $this->queueFactory->create(
    $queueConfig['queue'],
    $queueConfig['exchange'],
    $queueConfig['routing_key']
);
$publisher->publish($job);
```

---

## Обзор системы

Система очередей построена на RabbitMQ и состоит из следующих основных компонентов:

* **Jobs** — задачи, которые нужно выполнить
* **Handlers** — обработчики, которые выполняют задачи
* **Publishers** — отправляют задачи в очередь
* **Workers** — читают задачи из очереди и выполняют их
* **Registry** — реестр задач и их обработчиков (не нужен в клиентском коде)

## 1. Создание новой задачи (Job)

### Шаг 1: Создайте класс задачи

```php
<?php

namespace YourApp\Jobs;

use SpsFW\Core\Queue\Attributes\QueueJob;
use SpsFW\Core\Queue\Interfaces\JobInterface;

#[QueueJob('send_notification')]  // Уникальное имя задачи
class SendNotificationJob implements JobInterface
{
    public function __construct(
        public readonly string $email,
        public readonly string $subject,
        public readonly string $message,
        public readonly string $type = 'email'
    ) {}

    public function getName(): string
    {
        return 'send_notification';
    }

    /**
     * Сериализация задачи для отправки в очередь
     */
    public function serialize(): string
    {
        return json_encode([
            'email' => $this->email,
            'subject' => $this->subject,
            'message' => $this->message,
            'type' => $this->type,
            'timestamp' => time(),
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Восстановление задачи из очереди
     */
    public static function deserialize(string $payload): static
    {
        $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        return new self(
            $data['email'],
            $data['subject'],
            $data['message'],
            $data['type'] ?? 'email'
        );
    }
}
```

### Шаг 2: Создайте обработчик (Handler)

```php
<?php

namespace YourApp\Jobs;

use SpsFW\Core\Queue\Attributes\JobHandler;
use SpsFW\Core\Queue\Interfaces\JobHandlerInterface;
use SpsFW\Core\Queue\Interfaces\JobInterface;
use SpsFW\Core\Queue\JobResult;
use Psr\Log\LoggerInterface;

#[JobHandler('send_notification')]  // Связываем с задачей
class SendNotificationHandler implements JobHandlerInterface
{
    public function __construct(
        private LoggerInterface $logger,
        private EmailService $emailService  // Ваш сервис отправки
    ) {}

    public function handle(JobInterface $job): JobResult
    {
        /** @var SendNotificationJob $job */
        
        try {
            // Выполняем задачу
            $success = $this->emailService->send(
                $job->email,
                $job->subject,
                $job->message
            );

            if ($success) {
                $this->logger->info('Notification sent successfully', [
                    'email' => $job->email,
                    'type' => $job->type
                ]);
                return JobResult::Success;
            } else {
                $this->logger->warning('Failed to send notification', [
                    'email' => $job->email
                ]);
                return JobResult::Retry;  // Попробуем еще раз
            }

        } catch (\Exception $e) {
            $this->logger->error('Notification handler error', [
                'email' => $job->email,
                'error' => $e->getMessage()
            ]);
            
            // Если это временная ошибка — retry, иначе failed
            if ($this->isTemporaryError($e)) {
                return JobResult::Retry;
            }
            
            return JobResult::Failed;
        }
    }

    private function isTemporaryError(\Exception $e): bool
    {
        // Определяем, стоит ли повторять задачу
        return str_contains($e->getMessage(), 'timeout') || 
               str_contains($e->getMessage(), 'connection');
    }
}
```

## 2. Регистрация задач и обработчиков

#### После перезапуска контейнера, задачи и обработчики зарегистрируются автоматически

---

## 3. Настройка Workers

### Конфигурация в DI контейнере

```php
// В вашем bootstrap/config файле
Config::setDIBindings([
    // ... другие биндинги

    WorkerConfig::class => [
        'class' => WorkerConfig::class,
        'args' => [
            // Воркер для уведомлений
            'notification_worker' => [
                'type' => 'queueConsumer',
                'config' => [
                    'queue' => 'notifications',
                    'exchange' => 'notifications',
                    'routing_key' => 'notification.send'
                ]
            ],
            
            // Воркер для импорта сотрудников
            'import_employee_worker' => [
                'type' => 'queueConsumer',
                'config' => [
                    'queue' => 'import_employees',
                    'exchange' => 'employees',
                    'routing_key' => 'employee.import'
                ]
            ],
            
            // Воркер для отчетов
            'report_worker' => [
                'type' => 'queueConsumer',
                'config' => [
                    'queue' => 'reports',
                    'exchange' => 'reports',
                    'routing_key' => 'report.generate'
                ]
            ]
        ]
    ]
]);
```

## 4. Отправка задач в очередь

### Простая отправка

```php
<?php

namespace YourApp\Controllers;

use SpsFW\Core\Attributes\Controller;
use SpsFW\Core\Attributes\Inject;
use SpsFW\Core\Attributes\Route;
use SpsFW\Core\Http\Response;
use SpsFW\Core\Queue\QueueClientAndPublisherFactory;
use SpsFW\Core\Route\RestController;
use SpsFW\Core\Workers\WorkerConfig;
use YourApp\Jobs\SendNotificationJob;

#[Controller]
class NotificationController extends RestController
{
    public function __construct(
        #[Inject] private WorkerConfig $workerConfig,
        #[Inject] private QueueClientAndPublisherFactory $queueFactory
    ) {
        parent::__construct();
    }

    #[Route(path: "/api/send-notification", httpMethods: ['POST'])]
    public function sendNotification(): Response
    {
        $data = $this->request->getJsonData();
        
        $queueConfig = $this->workerConfig->getQueueConfig('notification_worker');

        // Создаем задачу
        $job = new SendNotificationJob(
            email: $data['email'],
            subject: $data['subject'],
            message: $data['message'],
            type: $data['type'] ?? 'email'
        );
        

        // Создаем publisher для очереди уведомлений
        $publisher = $this->queueFactory->create(
            queueName: $queueConfig['queue'],
            exchange: $queueConfig['exchange'],
            routingKey: $queueConfig['routing_key']
        );

        // Отправляем в очередь
        $publisher->publish($job);

        return Response::json([
            'success' => true,
            'message' => 'Notification queued successfully',
            'job_name' => $job->getName()
        ]);
    }
}
```

## 5. Запуск Workers

### Запуск одного воркера

```bash
# Запуск воркера уведомлений
php bin/worker.php notification_worker

# Запуск воркера импорта
php bin/worker.php import_employee_worker

# Запуск воркера отчетов
php bin/worker.php report_worker
```

### Запуск воркера с nohup (в фоне)

```bash
# Запуск в фоне с логированием
nohup php bin/worker.php notification_worker > logs/notification_worker.log 2>&1 &

# Проверка запущенных воркеров
ps aux | grep "worker.php"
```

### Создание systemd сервиса (для production)

```ini
# /etc/systemd/system/notification-worker.service
[Unit]
Description=Notification Queue Worker
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/path/to/your/project
ExecStart=/usr/bin/php bin/worker.php notification_worker
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

```bash
# Управление сервисом
sudo systemctl enable notification-worker
sudo systemctl start notification-worker
sudo systemctl status notification-worker
```

## 6. Мониторинг и управление

### Web Dashboard

Откройте в браузере: `http://your-domain/api/workers`

Dashboard позволяет:

* Просматривать статус всех воркеров
* Запускать/останавливать воркеров
* Отправлять тестовые задачи
* Мониторить статистику обработки

### API для управления

```bash
# Получить статус всех воркеров
curl http://your-domain/api/queue/dashboard

# Запустить воркера
curl -X POST http://your-domain/api/queue/worker/notification_worker/start \
  -H "Authorization: Bearer <token>" \

# Остановить воркера
curl -X POST http://your-domain/api/queue/worker/notification_worker/stop \
  -H "Authorization: Bearer <token>" \

# Перезапустить воркера
curl -X POST http://your-domain/api/queue/worker/notification_worker/restart \
  -H "Authorization: Bearer <token>" \

# Отправить задачу через API
curl -X POST http://your-domain/api/queue/send \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{
    "job_name": "send_notification",
    "queue": "notifications",
    "exchange": "notifications", 
    "routing_key": "notification.send",
    "job_data": {
      "email": "user@example.com",
      "subject": "Test",
      "message": "Hello World",
      "type": "email"
    }
  }'
```

## 7. Полный пример: Создание системы отчетов

### Job для генерации отчета

```php
<?php

namespace YourApp\Jobs;

use SpsFW\Core\Queue\Attributes\QueueJob;
use SpsFW\Core\Queue\Interfaces\JobInterface;

#[QueueJob('generate_report')]
class GenerateReportJob implements JobInterface
{
    public function __construct(
        public readonly string $reportType,
        public readonly array $parameters,
        public readonly string $userId,
        public readonly string $format = 'pdf'
    ) {}

    public function getName(): string
    {
        return 'generate_report';
    }

    public function serialize(): string
    {
        return json_encode([
            'reportType' => $this->reportType,
            'parameters' => $this->parameters,
            'userId' => $this->userId,
            'format' => $this->format,
            'timestamp' => time(),
        ]);
    }

    public static function deserialize(string $payload): static
    {
        $data = json_decode($payload, true);
        return new self(
            $data['reportType'],
            $data['parameters'],
            $data['userId'],
            $data['format']
        );
    }
}
```

### Handler для генерации отчета

```php
<?php

namespace YourApp\Jobs;

use SpsFW\Core\Queue\Attributes\JobHandler;
use SpsFW\Core\Queue\Interfaces\JobHandlerInterface;
use SpsFW\Core\Queue\JobResult;

#[JobHandler('generate_report')]
class GenerateReportHandler implements JobHandlerInterface
{
    public function __construct(
        private ReportService $reportService,
        private NotificationService $notificationService
    ) {}

    public function handle(\SpsFW\Core\Queue\Interfaces\JobInterface $job): JobResult
    {
        /** @var GenerateReportJob $job */
        
        try {
            // Генерируем отчет
            $filePath = $this->reportService->generate(
                $job->reportType,
                $job->parameters,
                $job->format
            );

            // Уведомляем пользователя о готовности
            $this->notificationService->notifyReportReady(
                $job->userId,
                $filePath
            );

            return JobResult::Success;

        } catch (\Exception $e) {
            // Логируем ошибку
            error_log("Report generation failed: " . $e->getMessage());
            
            // Уведомляем об ошибке
            $this->notificationService->notifyReportError(
                $job->userId,
                $e->getMessage()
            );

            return JobResult::Failed;
        }
    }
}
```

### Controller для запуска генерации

```php
#[Route(path: "/api/generate-report", httpMethods: ['POST'])]
public function generateReport(): Response
{
    $data = $this->request->getJsonData();

    $job = new GenerateReportJob(
        reportType: $data['type'],
        parameters: $data['parameters'] ?? [],
        userId: $data['user_id'],
        format: $data['format'] ?? 'pdf'
    );
    
    // Можно использовать строки, но лучше брать сконфигурированные в DI
    $publisher = $this->queueFactory->create(
        queueName: 'reports',
        exchange: 'reports',
        routingKey: 'report.generate'
    );

    $publisher->publish($job);

    return Response::json([
        'success' => true,
        'message' => 'Report generation started',
        'estimated_time' => '2-5 minutes'
    ]);
}
```

## 8. Лучшие практики

### Обработка ошибок

```php
public function handle(JobInterface $job): JobResult
{
    try {
        // Основная логика
        $this->doWork($job);
        return JobResult::Success;
        
    } catch (TemporaryException $e) {
        // Временная ошибка - повторим
        $this->logger->warning('Temporary error, will retry', [
            'error' => $e->getMessage(),
            'job' => $job->getName()
        ]);
        return JobResult::Retry;
        
    } catch (PermanentException $e) {
        // Постоянная ошибка - не повторяем
        $this->logger->error('Permanent error, job failed', [
            'error' => $e->getMessage(),
            'job' => $job->getName()
        ]);
        return JobResult::Failed;
        
    } catch (\Throwable $e) {
        // Неожиданная ошибка - лучше повторить
        $this->logger->error('Unexpected error', [
            'error' => $e->getMessage(),
            'job' => $job->getName(),
            'trace' => $e->getTraceAsString()
        ]);
        return JobResult::Retry;
    }
}
```

### Мониторинг производительности

```php
public function handle(JobInterface $job): JobResult
{
    $startTime = microtime(true);
    
    try {
        $result = $this->processJob($job);
        
        $duration = microtime(true) - $startTime;
        $this->logger->info('Job completed', [
            'job' => $job->getName(),
            'duration' => round($duration, 3),
            'memory_usage' => memory_get_peak_usage(true)
        ]);
        
        return $result;
        
    } catch (\Exception $e) {
        $duration = microtime(true) - $startTime;
        $this->logger->error('Job failed', [
            'job' => $job->getName(),
            'duration' => round($duration, 3),
            'error' => $e->getMessage()
        ]);
        
        return JobResult::Failed;
    }
}
```

### Graceful Shutdown

Workers поддерживают graceful shutdown через SIGTERM:

```bash
# Отправить сигнал для корректного завершения
kill -TERM <worker_pid>

# Принудительное завершение (не рекомендуется)
kill -KILL <worker_pid>
```

### Масштабирование

Для высоких нагрузок запускайте несколько экземпляров воркеров:

```bash
# Запуск 3 экземпляров воркера уведомлений
for i in {1..3}; do
    nohup php bin/worker.php notification_worker > logs/notification_worker_$i.log 2>&1 &
done
```

Это позволит обрабатывать задачи параллельно и повысит throughput системы.

---

# Дополнение: Отложенная отправка (rabbitmq_delayed_message_exchange) и поддержка множества delayed-очередей

Необходим плагин **`rabbitmq_delayed_message_exchange`**.

## Ключевые идеи (вкратце)

* Используем exchange типа `x-delayed-message` и header `x-delay` (миллисекунды) для отложенной доставки.
* `RabbitMQQueuePublisher` получил удобные опции `delayMs` и `executeAt` (через `publish()` / `publishAt()`), не меняя `JobInterface`.
* Фабрика `QueueClientAndPublisherFactory` умеет создавать delayed-exchange и множество очередей/routing keys, а также `createWithRetry()` поддерживает delayed exchange.
* В payload автоматически кладём мета-поле `meta.executeAt` (если было передано) — это страховка на стороне consumer.
* Всегда используем UTC для расчёта времени/задержки.

---

## Где и какие изменения появились (коротко)

1. **RabbitMQClient**

    * `exchange_declare` / `queue_declare` теперь поддерживают `exchange_arguments` и `queue_arguments` (преобразуются в `AMQPTable`).
    * `publish()` теперь имеет сигнатуру с опциональным параметром `exchange` — можно публиковать в любой exchange.

2. **RabbitMQQueuePublisher**

    * Новый `publish(JobInterface $job, array $options = [])`:

        * `delayMs` — задержка в миллисекундах,
        * `executeAt` — `DateTimeInterface`, рассчитывается `delayMs`,
        * `exchange`, `routingKey`, `properties` — переопределение параметров.
    * Удобный `publishAt(JobInterface $job, DateTimeInterface $when, array $options = [])`.
    * Не требует изменения `JobInterface` — `serialize()`/`deserialize()` остаются прежними.

3. **QueueClientAndPublisherFactory**

    * `create()` принимает `exchangeType` и `exchangeArguments` — можно создать exchange `x-delayed-message` (`['x-delayed-type' => 'direct']`).
    * `createWithRetry()` поддерживает тот же `exchangeType` — можно иметь retry для delayed-очередей тоже.

---

## Инструкция: как создавать delayed-очереди и публиковать задачи

### 1) Создание publishers через фабрику (пример — один delayed-exchange + три очереди)

```php
$factory = DIContainer::getInstance()->get(QueueClientAndPublisherFactory::class);

$delayedExchange = 'delayed_notifications';
$exchangeType = 'x-delayed-message';
$exchangeArgs = ['x-delayed-type' => 'direct'];

// Email publisher
$emailPublisher = $factory->create(
    queueName: 'notifications_email',
    exchange: $delayedExchange,
    routingKey: 'notification.email',
    exchangeType: $exchangeType,
    exchangeArguments: $exchangeArgs
);

// Push publisher
$pushPublisher = $factory->create(
    queueName: 'notifications_push',
    exchange: $delayedExchange,
    routingKey: 'notification.push',
    exchangeType: $exchangeType,
    exchangeArguments: $exchangeArgs
);
```

> Рекомендуется: **один delayed-exchange + много routing keys** (экономично и удобно). При необходимости можно создать отдельные delayed-exchange для отдельных доменов.

### 2) Публикация задач

#### Немедленная отправка (как раньше)

```php
$publisher->publish($job);
```

#### Отложенная отправка по времени (publishAt)

```php
$when = new \DateTimeImmutable('2025-11-01 12:30:00', new \DateTimeZone('UTC'));
$emailPublisher->publishAt($job, $when);
// Внутри автоматически рассчитает delayMs и поставит x-delay header
```

#### Отложенная отправка по задержке (delayMs)

```php
$emailPublisher->publish($job, ['delayMs' => 120000]); // 2 минуты (ms)
```

#### Переопределение exchange/routingKey при публикации

```php
$publisher->publish($job, [
    'delayMs' => 60000,
    'exchange' => 'delayed_notifications',
    'routingKey' => 'notification.email'
]);
```

---

## Примеры конфигурации DI (фрагменты)

Если вы генерируете publishers в DI, можно добавить биндинги примерно так:
_пока нет возможности создавать объекты по ключу, но будет_
```php
// В bootstrap/config — пример создания через фабрику
$factory = $container->get(\SpsFW\Core\Queue\QueueClientAndPublisherFactory::class);
$delayedExchange = 'delayed_notifications';
$exchangeArgs = ['x-delayed-type' => 'direct'];

$container->set('publisher.email', fn() => $factory->create(
    'notifications_email', $delayedExchange, 'notification.email', 'x-delayed-message', $exchangeArgs
));
```

---

## Consumer: двойная страховка — проверяем meta.executeAt

Даже если вы уверены в delayed-exchange, consumer **должен** проверять `meta.executeAt` — на случай нестабильной доставки или ручного publish без delay.
_(реализовано)_

> Внутри consumer можно либо восстановить `Job` через `deserialize()`, либо обработать payload напрямую — в зависимости от вашей архитектуры.

---

## Retry / DLX + delayed-exchange

* `createWithRetry()` поддерживает создание main queue с DLX и retry-очереди. Этот механизм работает и если main exchange — `x-delayed-message`.
* Для retry-очереди используется `x-message-ttl` и `x-dead-letter-exchange`, как раньше; фабрика автоматизирует создание `main` + `retry` + `dlx` exchange.

---

## Важные замечания и best-practices

1. **UTC** — всегда использовать `DateTimeImmutable` в UTC при передаче `executeAt`. В коде выше это делается по умолчанию.
2. **x-delay в миллисекундах** — плагин ожидает миллисекунды. `publishAt()` конвертирует секунды в миллисекунды.
3. **meta.executeAt** — кладём в полезную нагрузку как страховку и для логов/мониторинга.
4. **Не меняем JobInterface** — все изменения происходят через опции публикации (`publish()`/`publishAt()`), поэтому существующие Job классы не требуют правки.
5. **Тестируйте в среде с плагином** — локально плагин может отсутствовать; для локального fallback используйте TTL+DLX или тестовый режим.
6. **Мониторинг** — логируйте `publishedAt`, `executeAt`, `delayMs` и `jobId` (если есть) для удобства расследования задержек и дублирования.


# TODO
**Dedup / idempotency** — при высоких нагрузках/повторах подумать о `jobId` и идемпотентной обработке в handler.

