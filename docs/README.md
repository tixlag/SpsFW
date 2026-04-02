# Документация фреймворка SpsFW

SpsFW — PHP-фреймворк для построения REST API. Маршрутизация через атрибуты, DI-контейнер, валидация DTO, Auth/JWT, Redis, очереди RabbitMQ.

## Содержание

1. [Начало работы](1_getting_started.md) — установка, структура проекта, bootstrap, index.php
2. [Маршрутизация](2_routing.md) — `#[Route]`, параметры пути, HTTP-методы
3. [Контроллеры](3_controllers.md) — `RestController`, DI, атрибуты доступа
4. [Валидация данных](4_validation.md) — DTO, `#[JsonBody]`, `#[QueryParams]`, nullable, enum, uuid
5. [Middleware](5_middleware.md) — интерфейс, глобальные и маршрутные middleware
6. [DI-контейнер](6_dependency_injection.md) — биндинги, `#[Inject]`, `shared`
7. [Конфигурация](7_configuration.md) — Config, секции, Redis, несколько БД
8. [Миграции](8_database_migrations.md) — Phinx, доменные миграции, MigrationRequiredClass
9. [Управление доступом](9_access_control.md) — `AccessChecker`, `#[AccessRulesAny]`, `#[AccessRulesAll]`
10. [Исключения](10_exceptions.md) — иерархия, HTTP-коды, обработка в Router
11. [Очереди и воркеры](11_queue_workes.md) — RabbitMQ, `#[QueueJob]`, `#[JobHandler]`
12. [Большие сообщения](12_large_messages.md) — ChunkedMessageHandler
13. [Надёжность очередей](13_queue_reliability_update.md) — heartbeat, мониторинг
14. [CLI-утилита spsfw](14_spsfw_cli.md) — `make:chapter`, `make:api`, генерация кода
15. [Preload и OPcache](15_preload_opcache.md) — прогрев кеша, `php.ini`, CI/CD деплой
16. [Outbox Pattern](16_outbox_pattern.md) — надёжная публикация в RabbitMQ через БД-буфер
17. [Rate Limiting](17_rate_limiting.md) — `#[RateLimit]`, `RateLimitMiddleware`, 429 Too Many Requests
