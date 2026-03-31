<?php

use PhpAmqpLib\Connection\AMQPStreamConnection;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use SpsFW\Core\Auth\AccessRule\AccessRuleServiceI;
use SpsFW\Core\Auth\AccessRule\AccessRuleStorageI;
use SpsFW\Core\Auth\AuthServiceI;
use SpsFW\Core\Auth\AuthToken\AuthTokenStorageI;
use SpsFW\Core\Interfaces\UserServiceI;
use SpsFW\Core\Interfaces\UserStorageI;
use SpsFW\Core\Psr\HttpClientInterface;
use SpsFW\Core\Psr\MonologLogger;
use SpsFW\Core\Queue\Interfaces\QueuePublisherInterface;
use SpsFW\Core\Queue\JobRegistry;
use SpsFW\Core\Queue\QueueClientAndPublisherFactory;
use SpsFW\Core\Queue\RabbitMQConfig;
use SpsFW\Core\Queue\RabbitMQQueuePublisher;
use SpsFW\Core\Workers\WorkerConfig;
use SpsNext\Auth\AccessRules\AccessRuleService;
use SpsNext\Auth\AccessRules\AccessRuleStorage;
use SpsNext\Auth\AuthService;
use SpsNext\Auth\Storages\DeviceAwareTokenStorage;
use SpsNext\Exchange1C\Config\Exchange1CConfig;
use SpsNext\Exchange1C\Factory\Exchange1CClientFactory;
use SpsNext\Psr\Cache\FileCache;
use SpsNext\Psr\FlexibleTelegramNotifier;
use SpsNext\Psr\GuzzleHttpClientAdapter;
use SpsNext\Psr\TelegramNotifier;
use SpsNext\Users\Services\UserService;
use SpsNext\Users\Storages\UserStorage;
use SpsNext\Workers\Errors\GlobalErrorHandler;

// Load cache functions
require_once __DIR__ . '/cache_helpers.php';

// Load cached 1C Exchange configuration
$exchange1cConfig = loadCachedConfig('exchange1c_config', [
    'endpoints' => [],
    'state_file' => __DIR__ . '/../.tmp/state/1c_down.state'
]);

return [
    AuthServiceI::class => AuthService::class,
    AccessRuleServiceI::class => AccessRuleService::class,
    AccessRuleStorageI::class => AccessRuleStorage::class,
    UserServiceI::class => UserService::class,
    UserStorageI::class => UserStorage::class,
    AuthTokenStorageI::class => DeviceAwareTokenStorage::class,
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
    LoggerInterface::class => [
        'class' => MonologLogger::class,
        'args' => [
            './sps-next.log'
        ]
    ],
    CacheInterface::class => [
        'class' => FileCache::class,
        'args' => [
            __DIR__ . '/../.cache', // путь к кешу
            3600, // TTL по умолчанию
        ],
    ],
    Exchange1CConfig::class => [
        'class' => Exchange1CConfig::class,
        'args' => [$exchange1cConfig],
    ],

    Exchange1CClientFactory::class => [
        'class' => Exchange1CClientFactory::class,
        'args' => [
            HttpClientInterface::class,
            TelegramNotifier::class,
            Exchange1CConfig::class,
        ],
    ],

    RabbitMQConfig::class => [
        'class' => RabbitMQConfig::class,
        'args' => [
            $_ENV['SPS_RABBIT_MQ_HOST'] ?? 'localhost',
            (int)($_ENV['SPS_RABBIT_MQ_PORT'] ?? 5672),
            $_ENV['SPS_RABBIT_MQ_USER'] ?? 'guest',
            $_ENV['SPS_RABBIT_MQ_PASS'] ?? 'guest',
            $_ENV['SPS_RABBIT_MQ_VHOST'] ?? '/',
            3.0, // connection timeout
            3.0, // read/write timeout
            0,   // heartbeat
        ],
    ],
    \SpsFW\Core\Queue\JobRegistry::class => [
        'class' => \SpsFW\Core\Queue\JobRegistry::class,
    ],

    TelegramNotifier::class => [
        'class' => TelegramNotifier::class,
        'args' => [
            $exchange1cConfig['state_file'],
            [
                'stickers' => [
                    'down' => $_ENV['TELEGRAM_STICKER_DOWN'] ?? 'CAACAgIAAxkBAAE1AmNoJvuKuyqcFKdaSsAjcG3QSbNrZAAC93kAAsc0KUnQGSom1xg2gzYE',
                    'up' => $_ENV['TELEGRAM_STICKER_UP'] ?? 'CAACAgIAAxkBAAEPM5doKpDB-s3Fs7XLf5rMin0pcIznNAACtksAAmwQaUgg5d-hUmYtNTYE',
                ],
                'chat_id' => $_ENV['TELEGRAM_CHAT_ID'] ?? null,
                'topic_id' => $_ENV['TELEGRAM_TOPIC_ID'] ?? null,
            ],
        ],
    ],

    FlexibleTelegramNotifier::class => [
        'class' => FlexibleTelegramNotifier::class,
        'args' => [
            $_ENV['TELEGRAM_BOT_TOKEN'] ?? null,
            $_ENV['TELEGRAM_CHAT_ID'] ?? null,
            $_ENV['TELEGRAM_TOPIC_ID'] ?? null,
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
                'errors-worker' => [
                    'type' => 'queueConsumer',
                    'config' => [
                        'exchange' => 'php_next.errors',
                        'queue' => 'php_next.errors.send',
                        'routing_key' => 'errors.send'
                    ]
                ],
                'achievements-worker' => [
                    'type' => 'queueConsumer',
                    'config' => [
                        'exchange' => 'php_next.achievements',
                        'queue' => 'php_next.achievements.process',
                        'routing_key' => 'achievements.process'
                    ]
                ],
                'worksheets_shift_onChange-worker' => [
                    'type' => 'queueConsumer',
                    'config' => [
                        'exchange' => 'php_next.worksheets.shift',
                        'queue' => 'php_next.worksheets.shift.onChange',
                        'routing_key' => 'worksheets.shift.onChange'
                    ]
                ],
                'employee-exchange-worker' => [
                    'type' => 'queueConsumer',
                    'config' => [
                        'exchange' => 'php_next.exchange_1c',
                        'queue' => 'php_next.exchange_1c.employee-metrics',
                        'routing_key' => 'exchange_1c.employee-metrics'
                    ]
                ],
                'news-worker' => [
                    'type' => 'queueConsumer',
                    'config' => [
                        'exchange' => 'php_next.exchange_news',
                        'queue' => 'php_next.exchange_news.news-publisher',
                        'routing_key' => 'exchange_news.news-publisher',
                        'delayed' => true
                    ]
                ],
            ]
        ]
    ],
    QueueClientAndPublisherFactory::class => [
        'class' => QueueClientAndPublisherFactory::class,
        'args' => [RabbitMQConfig::class, WorkerConfig::class],
    ],
    GlobalErrorHandler::class => [
        'class' => GlobalErrorHandler::class,
        'args' => [
            QueueClientAndPublisherFactory::class,
            WorkerConfig::class,
            LoggerInterface::class
        ]
    ]
];
