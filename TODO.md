# SpsFW — TODO

## 🔴 Критические баги (исправить до публикации)

- [ ] **Auth: баг с masterPassword** — `AuthServiceAbstract.php:129` использует `||` вместо `&&`, что позволяет masterPassword войти за любого пользователя
- [ ] **Auth: timing attack** — `$password == $masterPassword` нужно заменить на `hash_equals()`
- [ ] **Redis: race condition в `incrWithTtl()`** — `INCR` + `EXPIRE` не атомарны, заменить на Lua-скрипт
- [ ] **Auth: logout без проверки cookie** — `logout()` упадёт с ошибкой если `refresh_token` отсутствует в cookie

---

## 🟠 Производительность и надёжность

- [ ] **FileCache → Redis по умолчанию** — роутер и DI используют файловый кэш, переключить на Redis когда он доступен
- [ ] **Router: кэш не инвалидируется** — при изменении контроллеров в dev-режиме кэш маршрутов нужно сбрасывать автоматически
- [ ] **DI: нет детектора циклических зависимостей** — `A→B→A` уйдёт в бесконечный цикл
- [ ] **DI: `return null` вместо исключения** — при ненайденном классе контейнер молча возвращает null
- [ ] **Outbox: логика flush при ошибке** — `break` при ошибке публикации прерывает батч, но транзакция всё равно коммитится
- [ ] **Redis: нет reconnect логики** — потеря соединения не обрабатывается
- [ ] **PdoStorage: нет retry при потере соединения** — нужен reconnect с exponential backoff
- [ ] **Queue: жёсткие таймауты RabbitMQ** — `connection_timeout` захардкожен, вынести в конфиг

---

## 🟡 Важные недостатки

### Логирование
- [ ] **Подключить Monolog в Router** — сейчас ошибки пишутся через `error_log()`, а `MonologLogger.php` уже есть но не используется в точке обработки ошибок
- [ ] **Correlation ID / Request ID** — добавить уникальный ID к каждому запросу и прокидывать его в логи и очередь
- [ ] **Structured logging** — логировать запросы/ответы в JSON-формате для агрегации

### Безопасность
- [ ] **CORS** — строки в `Response.php` закомментированы, нужна нормальная конфигурация с whitelist доменов
- [ ] **Security headers middleware** — `X-Content-Type-Options`, `X-Frame-Options`, `Strict-Transport-Security`, `Content-Security-Policy`
- [ ] **Account lockout** — блокировка после N неудачных попыток входа
- [ ] **Refresh token rotation** — выдавать новый refresh token при каждом обновлении access token
- [ ] **Cookie: Secure флаг** — для refresh token cookie в HTTPS-окружении
- [ ] **Rate limit по пользователю** — сейчас только по IP, добавить лимит по user_id после авторизации

### HTTP
- [ ] **Rate limit headers в ответе** — `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `Retry-After`
- [ ] **File uploads** — нет обработки `multipart/form-data` с файлами (только данные формы)
- [ ] **Streaming responses** — для больших файлов нужна отдача чанками без загрузки в память
- [ ] **API versioning** — поддержка `/v1/`, `/v2/` префиксов через конфигурацию роутера

### База данных
- [ ] **Query builder** — сейчас только сырой SQL, нужен хотя бы минимальный fluent builder для типовых операций
- [ ] **Pagination helper** — `LIMIT/OFFSET` + мета-информация (`total`, `page`, `per_page`)
- [ ] **Read replica поддержка** — отдельное соединение для SELECT запросов
- [ ] **Slow query logging** — логировать запросы дольше порогового значения

### Auth
- [ ] **Password reset flow** — генерация токена сброса, отправка, валидация
- [ ] **API-ключи** — альтернатива JWT для machine-to-machine взаимодействия
- [ ] **Token blacklist** — инвалидация конкретного access token до истечения TTL (через Redis)

### OpenAPI / Swagger
- [ ] **Автогенерация спецификации** — `zircote/swagger-php` есть в composer, `SwaggerController.php` есть, но pipeline не настроен
- [ ] **Docs endpoint** — `/api/docs` в dev-режиме с UI (Swagger UI / Scalar)

---

## 🟢 Улучшения и новый функционал

### Инфраструктура фреймворка
- [ ] **Events / хуки** — внутренний event dispatcher (`onRequest`, `onResponse`, `onException`, пользовательские события)
- [ ] **Service providers** — модульная регистрация зависимостей вместо одного массива `$bindings`
- [ ] **Config validation** — проверка обязательных переменных окружения при старте, внятная ошибка вместо Notice
- [ ] **Graceful shutdown** — обработка `SIGTERM` с завершением текущих запросов/задач перед остановкой (воркеры уже частично это делают)
- [ ] **Dev mode** — детальные страницы ошибок с трейсом и контекстом запроса в dev-окружении

### Queue / Jobs
- [ ] **Job scheduler** — cron-подобный планировщик задач поверх существующей Queue системы
- [ ] **Dead Letter Queue стратегия** — явная обработка сообщений после N неудачных попыток (в Outbox пока нет)
- [ ] **Circuit breaker** — для внешних HTTP-вызовов и publish в RabbitMQ
- [ ] **Job priorities** — поддержка приоритетных очередей в RabbitMQ

### Кэш
- [ ] **Cache abstraction** — единый интерфейс с драйверами: File (есть), Redis (есть клиент), Array (для тестов)
- [ ] **Cache tags** — инвалидация группы ключей по тегу
- [ ] **Redis Sentinel** — поддержка HA конфигурации

### Тестирование
- [ ] **HTTP test client** — `TestRequest::get('/api/users')` без реального HTTP для интеграционных тестов
- [ ] **DI test mode** — полная изоляция синглтонов между тестами (сейчас `testMode` не сбрасывает существующие singletons)
- [ ] **Database factories/seeders** — генерация тестовых данных

### CLI
- [ ] **PHP-based CLI команды** — сейчас `spsfw` — bash-скрипт, перевести на PHP с `symfony/console` или собственным парсером аргументов
- [ ] **Генерация кода** — `make:controller`, `make:migration`, `make:dto` из CLI

### Мониторинг
- [ ] **Health check endpoint** — `/health` с проверкой DB, Redis, RabbitMQ
- [ ] **Metrics** — базовая поддержка Prometheus (счётчики запросов, latency, очередь)
- [ ] **OpenTelemetry** — distributed tracing для микросервисной архитектуры

---

## 📦 Перед публикацией на Packagist

- [ ] `LICENSE` файл в корне
- [ ] `"license": "MIT"` в `composer.json`
- [ ] `README.md` в корне с быстрым стартом
- [ ] `keywords` в `composer.json`
- [ ] Версия `"version": "0.1.0"` + git tag `v0.1.0`
- [ ] `require-dev` с PHPUnit, PHPStan
- [ ] Базовые unit-тесты для Auth, Router, Validation
- [ ] GitHub Actions: тесты на push/PR
- [ ] `CHANGELOG.md`
