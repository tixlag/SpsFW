# Внедрение зависимостей (DI)

Фреймворк использует собственный контейнер DI (`DIContainer`) с поддержкой кэширования.

## Конфигурация DI

Связывание интерфейсов с их реализациями происходит в `SpsFW\Core\Config::$bindings`.

### Свойство `Config::$bindings`

Статический массив, где ключи - это интерфейсы, а значения - их конкретные реализации.

### Метод `Config::setDIBindings`

```
Config::setDIBindings(array $bindings)
```

Позволяет добавить или переопределить биндинги.

### Пример конфигурации

```php
// В preload.php или index.php
use SpsFW\Core\Config;

// Инициализация конфига из .env
Config::init();

// Устанавливаем DI-биндинги
Config::setDIBindings([
    UserServiceI::class => UserService::class,
    UserStorageI::class => UserStorage::class,
    HttpClientInterface::class => [
        'class' => GuzzleHttpClientAdapter::class,
        'args' => [
            [
                'timeout' => 60,
                'verify' => false, // как в старом legacy-коде
                'headers' => [
                    'User-Agent' => 'SpsFW/1.0',
                ],
            ],
        ],
    ],
    AMQPStreamConnection::class => [
        'class' => AMQPStreamConnection::class,
        'args' => [
            $_ENV['SPS_RABBIT_MQ_HOST'] ?? 'localhost',
            (int)($_ENV['SPS_RABBIT_MQ_PORT'] ?? 5672),
            $_ENV['SPS_RABBIT_MQ_USER'] ?? 'guest',
            $_ENV['SPS_RABBIT_MQ_PASS'] ?? 'guest',
        ],
        'shared' => true, // singleton
    ],
    QueuePublisherInterface::class => [
        'class' => RabbitMQQueuePublisher::class,
        'args' => [
            AMQPStreamConnection::class,
            $_ENV['RABBIT_MQ_QUEUE_NAME'] ?? 'telegram_notifications',
        ],
    ],

    WorkerConfig::class => [
        'class' => WorkerConfig::class,
        'args' => [
            'config' => [
                'import_employee_worker' => [
                    'type' => 'queueConsumer',
                    'config' => [
                        'queue' => 'import_employees',
                        'exchange' => 'employees',
                        'routing_key' => 'employee.import'
                    ]
                ],
                'notification_worker' => [
                    'type' => 'queueConsumer',
                    'config' => [
                        'queue' => 'notifications',
                        'exchange' => 'notifications',
                        'routing_key' => 'notification.send'
                    ]
                ],
                'error_worker' => [
                    'type' => 'queueConsumer',
                    'config' => [
                        'queue' => 'errors',
                        'exchange' => 'errors',
                        'routing_key' => 'error.log'
                    ]
                ]
            ]
        ]
    ],
]);
```

## Использование DI

### В контроллерах

Через конструктор с атрибутом `#[Inject]`.

### В Middleware

Через конструктор.

### Автоматическое разрешение

Контейнер пытается автоматически разрешить зависимости по типу в конструкторах, используя настроенные биндинги.

### В любом месте приложения

```php
$container = DIContainer::getInstance();
$queueList = $container->get(WorkerConfig::class);
```
