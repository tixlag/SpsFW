# Единый compile-time компилятор метаданных SpsFW (routes + DI + OpenAPI + клиент)

> **Статус:** архитектурный документ и пошаговый план (rev. 2 после ревью + финальная корректировка «preload-owned flow»). **Production-код не меняется.** Шаг 0 (характеризация) выполнен — см. Приложение B (audit preload-flow) и `tests/Compile/*Test.php`.
> Канонический экземпляр документа — этот файл (`docs/METADATA_COMPILER_PLAN.md`).
> Все ссылки проверены firsthand по SpsFW (`/home/tixlag/PhpstormProjects/SpsFW`) и приложению `lk.sps38.pro/next` (`SpsNext\`, `tixlag/php-framework:@dev`).

## Context

`swagger-php` `#[OA\...]` сегодня: многословен, дублирует данные из `#[Route]`/сигнатуры/DTO/собственных атрибутов и **неявно является источником истины для runtime-валидации** (`Router::extractValidationRules()` строит rule graph из `#[OA\Property]` → route cache `dtos` → `Validator::validate(...,$dtoParam['rules'])`, Router.php:1054). Цель — единый compile-компилятор, где источник истины — сигнатуры методов и DTO; декларативный шум минимизируется; routing/validation/request/DTO-schemas строятся полностью без OA, OA остаётся узким opt-in escape hatch.

**Решения заказчика (фиксированы):** общий endpoint/DTO graph для route cache и OpenAPI; `DtoSchemaBuilder` как продюцент schema + rule graph; DI orchestrate-only с `job_registry`; сохранение legacy operationId; response inference; узкий OA escape hatch; **production-владелец compilation flow — клиентский `next/preload.php`** (SpsFW = compile-engine без bootstrap). 12 + 1 (финальная) корректировок ревью включены ниже (см. Приложение A — таблица трассировки).

---

## 1. Краткий итог и рекомендация

**Вариант B — общий metadata compiler**, адаптированный:
- **SpsFW = compile-engine** (`Coordinator`/`MetadataCompiler`, builders, diagnostics, staging/publication API); **не владеет application bootstrap** и не знает, как клиент грузит env/dynamic-config/`Config::init()`/`Config::setDIBindings()`. Production-владелец compilation flow — клиентский `next/preload.php`. `new Router()` **не** используется как неявный сборщик route cache — Coordinator строит route metadata напрямую. Два режима: `legacy` (lazy, по умолчанию) и `managed` (preload-built, fail-fast в prod) — см. §11.
- **Один discovery — НЕТ** (честное ограничение, см. §17): route-discovery и DI-discovery — **разные механизмы** и остаются разными. Унификация только в общем **слое Introspection** (`DtoSchemaBuilder`/`TypeMapper`/`AttributeReader`/`OperationIdResolver`), который кормит и route cache, и OpenAPI.
- **Точка унификации — `DtoSchemaBuilder`**: один проход по DTO даёт и `RuleGraph` (→ route cache `dtos` → `Validator`), и `SchemaMetadata` (→ OpenAPI). Закрывает сегодняшнее двойное чтение `#[OA\Property]`.
- **Безопасная публикация** (§11): staging на той же ФС + flock + build+validate + atomic rename per-file + manifest/fingerprint последним; readiness контейнера — только после успешного preload (atomicity опирается на lifecycle, а не на set-atomic swap).
- `Validator` и production-`DIContainer` (**singleton**) **нетронуты**; в `DICacheBuilder` добавляется **узкий compile-only API** (строит и возвращает DI/job artifacts без записи в prod cache и без `setCompiledMap()` на prod-контейнере; без throwaway-контейнера); старый `compile()` — compatibility wrapper.

## 2. Карта текущего pipeline (исправлено: два discovery, 6 entrypoint-ов, error-статусы)

### 2.1 Route cache — discovery через ИМЯ ФАЙЛА (НЕ ClassScanner!)
- `PathManager::getControllersDirs()` → `[getLibraryRoot(), getSrcPath()]`.
- `Router::scanControllers()` (Router.php:175–218): **свой** `RecursiveDirectoryIterator`, фильтр **`preg_match('/Controller\.php$/', filename)`** (строка 191), затем `ClassScanner::getPathToNamespace($file)` (строчная версия, НЕ token-парсер) → FQCN → `require_once` → `new ReflectionClass` → `registerControllerRoutes`.
- ⚠️ `ClassScanner::getClassesFromDir()` (token-парсер, **возвращает ВСЕ классы**, без фильтра) для routes **НЕ используется** (вызов закомментирован, Router.php:112).
- `registerControllerRoutes()` (224): `#[Route]` на public-методах (`getMethods(IS_PUBLIC)` — **включая унаследованные**); компиляция path; merge middlewares (class+method); access (method-only); DTO-validation; PhpIni.
- **Candidacy правила (точные):** файл `*Controller.php` в `getControllersDirs()` (без `/migrations/`) + `class_exists` + хотя бы один public-метод с `#[Route]`. Дубли `METHOD:path` (ключ `$routes[$key]`, строка 290) **молча перетираются**.
- IR (Router.php:291–302): `controller, httpMethod, method, rawPath, pattern, params(имена path-параметров по порядку), middlewares[{class,params}], access_rules, dtos[{in,dto,rules}], php_ini_settings`.
- Кеш: `.cache/compiled_routes.php` (`var_export`). Загрузка: `Router::loadRoutes()` (151) — `require` или scan+createCache.

### 2.2 DI cache + job registry — discovery через ВСЕ классы
- `DICacheBuilder::compileDI()` (DICacheBuilder.php:203) → `ClassScanner::getClassesFromDir()` (**все классы**) → `compile($classList)`.
- `compile($classList)` (35, **public**): `analyze()` (`new ReflectionClass`, `#[Inject]`, тип) → `compiled_di.php` + `job_registry.php` (из `#[QueueJob]/#[JobHandler]`) через `writeToFile()` в `$this->cachePath`; также `$this->container->setCompiledMap(...)`.
- Runtime: `DIContainer::__construct(cachePath)` → `require compiled_di.php` в `$compiledMap` (28–30); `getInstance(cachePath)` (34); `get()` (95). Fallback при промахе map → runtime Reflection `createInstanceWithDependencies` (Router.php:934, `newInstanceArgs`).

### 2.3 OpenAPI
- `DocsUtil::updateDocs()` (DocsUtil.php:19): scan `[getSrcPath(), getLibraryRoot()]` swagger-php → `.cache/swagger/openapi.yml` (3.1.0). **Warning'и подавлены** (createCustomGenerator, DefaultLogger-наследник глушит `warning`). operationId: явный в OA либо `methodName` (`SetOperationIdFromMethodNameProcessor`).

### 2.4 Шесть entrypoint-ов compilation flow (мигрируются в §11)
| # | Entrypoint | Файл:строка | Что запускает |
|---|---|---|---|
| 1 | `Router::loadRoutes()` cache-miss | Router.php:151 | scanControllers + createRoutesCache (route cache) |
| 2 | `Router::createControllerInstance()` lazy | Router.php:792 | если нет `compiled_di.php` → `DICacheBuilder::compileDI()` (DI, **в runtime запроса**) |
| 3 | `Bootstrap::getRouter()` | Bootstrap.php:15 | `DICacheBuilder::compileDI()` (DI, bootstrap) |
| 4 | `CoreUtilController` HTTP | CoreUtilController.php (`/api/core/update`, `/swagger/update`) | `DocsUtil::updateDocs()` (OpenAPI, **HTTP**) |
| 5 | preload (framework template `example/preload.php` ⇄ клиентский `next/preload.php`, см. Приложение B) | — | compileDI + `new Router()` + updateDocs (deploy) |
| 6 | `DocsUtil::updateDocs()` | DocsUtil.php:19 | OpenAPI |

**Режимы compilation flow (см. §11.4):**
- `legacy` (по умолчанию, BC) — entrypoint-ы №1/2/3 сохраняют текущее lazy-поведение; №4 HTTP-rebuild и №5 preload работают как сегодня.
- `managed` (production target) — клиент (`next/preload.php`, расширенный entrypoint №5) **гарантирует** сборку до старта; №1/2/3 **не пересобирают** комплект (cache-miss → fail-fast, без runtime discovery/reflection); №4 HTTP-rebuild **только в dev**. Единственный production-продюсер — Coordinator через preload.

### 2.5 Error/status runtime-контракт (для StandardErrorPolicy, без выдумывания)
| Статус | Источник | Код |
|---|---|---|
| **400** | `ValidationException` | `parent::__construct($msg, 400)` (хардкод) |
| **401** | `AuthorizationException` (default) | code=401 |
| **403** | `AuthorizationException` (forbidden) | code=403 |
| **429** | `TooManyRequestsException` | code=429 |
| **500** | `BaseException`/`\Throwable` | default 500 |
Тело ошибки: `Response::createErrorBody()` → `{error:{status,uri,user,exception,message,file,line,previous,trace}}` (debug-поля `null`/`[]` вне `DEBUG_MODE`). **422 в runtime нет** — не добавлять без отдельного изменения.

## 3. Точки дублирования и скрытые проблемы
- **Главное:** `#[OA\Property]` — источник compile-time rule graph валидации. `Router::extractValidationRules()` (345) → graph → route cache `dtos` → `Validator::validateDtoWithCachedRules()` (runtime, **без OA**). Legacy `Validator::validateDto()` (149) — OA-fallback (см. lifecycle §15/§16).
- **Формат graph шире whitelist** `Validator::$attributesOpenApi` (`required,type,minimum,maximum,minLength,maxLength,enum,format,nullable`): также `real_name, default, ref, nested_rules`, collection item. **Подтверждено characterization-тестом:** `required` хранится как **`[true]`** (массив); проверяется `$rules['required'] !== [true]` (Validator.php:77) и `$ruleValue[0] === true` (305). ВАЖНО: `grep "required" src/Core/Router/Router.php` пуст — продюсер **не выводит required автоматически**; `required` попадает в graph только если передан явным OA-аргументом (`required: [true]`/`[false]`, см. собственный `src/Core/Auth/Dto/AccessRulesDto.php`) и копируется verbatim.
- **Access-quirks (характеризовать, не править — подтверждено тестом):** `#[AccessRulesAll]` **без** `#[AccessRulesAny]` → `collectAccessRules` возвращает `[]` (игнор, Router.php:584); **class-level access не собирается** (только method-level) — в отличие от middlewares (class+method merge). `SecurityMetadata(requiredRules[])` это теряет ⇒ нужен отдельный `RouteRuntimeMetadata.access_rules` (точная форма) + OpenAPI-проекция `x-required-rules{any,all}`.
- Дублирование: path/method (`#[Route]`↔`#[OA\Get]`), body (`#[JsonBody]`↔`#[OA\RequestBody]`), query, path-params (тип `int` искажается в OA `string`), response (return-type↔`#[OA\Response]`, при opaque `Response`), enum, массивы, ошибки, security.
- `DocsUtil` глушит warning'и swagger-php → структурные ошибки проходят тихо.

## 4. Разбор реальных endpoint-ов (`lk.sps38.pro/next`)
| # | Категория | Endpoint | Из кода | Дублировано в OA | Требует явного |
|---|---|---|---|---|---|
| 1 | GET без парам. | `AuthController::me()` → `FullUser\|array` | path, method, return `FullUser` | `#[OA\Get]`, security, response-ref, 401 | security, 401; **union** — override |
| 2 | GET + path | `ExamsController::getExam(int $examId)` | path, `{examId}`, `int` | `#[OA\Parameter schema:string]` (искажено) | — |
| 3 | GET + Query | `ExamsController::getExams(#[QueryParams] GetExamsSummaryDto)` | query из DTO | `#[OA\Parameter]` | example |
| 4 | POST + body | `AuthController::login(#[JsonBody] LoginUserDto): UserAbstract` | body, return | `#[OA\RequestBody]`, header Auth | header Auth, operationId |
| 5 | Сложный DTO | `FullUser` (nested/nullable/arrays) | refs, nullable | все `#[OA\Property ref]` | item-тип `PhoneDto[]` |
| 6 | Nullable | `GetExamsSummaryDto::?string $examList=null` | nullable+default | `#[OA\Property nullable,default]` | — |
| 7 | Массив nested | `FullUser::$phones: array` | — (PHP молчит) | `#[OA\Property items: Items(ref)]` | **`#[Items(PhoneDto::class)]`** |
| 8 | Enum | `LightweightLkModeDto::string $mode` | — | `#[OA\Property enum:[...]]` | PHP backed-enum → авто, иначе `#[Field(enum:[])]` |
| 9 | Errors | `createResetCode` (200+400/403/404) | 403 из `#[AccessRulesAny]` | 4× `#[OA\Response]` | нестандартные 4xx — централизованно |
| 10 | Auth+caps | `createResetCode` + `#[AccessRulesAny]` + `#[RateLimit]` | security+caps | `#[OA\Security]` (caps нет) | `x-required-rules` |

Перегруженные OA: `QueueManagerController.php`, `next/src/Auth/AuthController.php`, `ExamsController.php`, `UserController.php`. Удаляемый шум 60–90 %.

## 5. Анализ клиента (`lk.sps38.pro/public_next`)
`orval.config.ts`: input `../next/.cache/swagger/openapi.yml`; client react-query; tags-split; mutator `axios.config.ts::mutatorInstance`; `useOperationIdAsQueryKey:true`. Проблемы: `as any` (mutation options), `@ts-ignore`/`@ts-expect-error` (refresh, `window.__PHPDATA__`), error-типы `void`/`unknown` (400/401/403/404/500 теряются), несконсистентный конверт (direct/`{items,total,offset,limit}`/void), **function-name path-derived** (`getApiAuthMe`) при spec operationId `loginUser`/`me` ⇒ spec/client **рассинхрон** — дефект pipeline (не основание для path-derived operationId). Регенерация клиента — отдельный скоординированный шаг (§21).

## 6. Целевая модель метаданных (исправлено: runtime vs OpenAPI-проекция, DTO eligibility, serialization)

Namespace `SpsFW\Core\Compile\` (compile-time only). Разделены **три артефакта**:

**A. `RouteRuntimeMetadata`** — точная репродукция route cache IR (источник истины для runtime). Поля: `controller, httpMethod, method, rawPath, pattern, params(порядок path-имён), middlewares[{class,params}] (class+method merge), access_rules(ТОЧНАЯ форма: ['NO_AUTH_ACCESS'] | [] | ['any'=>['rules'=>..],'all'=>['rules'=>..]], с quirk'ами All-only→[] и class-level→ignored), dtos[{in(ParamsIn), dto(class), rules(RuleGraph)}], php_ini_settings`. `RouteCacheEmitter` сериализует это в `compiled_routes.php` побайтно-совместимо с текущим.

**B. `OperationMetadata`** — OpenAPI-проекция: `httpMethod, path, operationId, pathParams[], queryParams[], requestBody?, responses[], security?(OpenAPI), tags[], summary?, description?, deprecated, exclude`. **Производный** от того же reflection, но для документации.

**C. `SecurityMetadata`** (OpenAPI-only): `scheme: bearerAuth` (если не `#[NoAuthAccess]`), `requiredRules: {any:[], all:[]}` → эмиттится как `x-required-rules` vendor extension (scopes OAuth не используем — capabilities не лезут в OAuth-семантику). **Не заменяет** `RouteRuntimeMetadata.access_rules`.

**Introspection-слой:** `AttributeReader`, `TypeMapper` (PHP-тип→openapi{type,format}; UUID/date/date-time/email через `#[Field(format)]` или класс-тип; backed enum→enum), `DtoSchemaBuilder::build(class): SchemaMetadata` (+ memo) и `::ruleGraph(SchemaMetadata): RuleGraph` (строго совместим с `Validator`), `OperationIdResolver`.

**DTO eligibility gate (критично, §6 пользователя):** тип считается источником schema **только если** FQCN соответствует критерию (e.g. неймспейс/директория `Dto`, или `implements` API-маркер, или явно `#[Response]/#[Field]`-тегирован, или в whitelist). **Произвольный class return-type** (`UserAbstract`, доменные entity, `Response`) **НЕ** отражается как DTO — требуется явный `#[Response]` или exclusion. Базовые классы (`User`, `UserAbstract`) — characterize их json-форму; если это entity, а не API DTO — не генерить schema автоматически.

**Serialization contract:** response-тело = `json_encode` возврата (`Response::json`, Response.php:302). Ключи JSON = **PHP property name** (или `JsonSerializable::jsonSerialize()`), НЕ `#[OA\Property(property:)]` (которое может врать). ⇒ `#[Field(name:)]` обязан совпадать с реальным json-ключом; компилятор выводит serial-name из json-поведения, `#[Field(name)]` — только документация/override существующего ключа. `private(set)` (PHP 8.4) = public read ⇒ сериализуется; `private`/`protected` — нет (если не JsonSerializable). Request vs response — одна `SchemaMetadata`, проекция по направлению; `readOnly/writeOnly` через `#[Field]` если нужно.

## 7. Правила автоматического вывода (детерминированные)
- **HTTP method/path** ← `#[Route]`.
- **operationId:** новые операции — `<ControllerShort><Method>` + compile-time уникальность; **существующие не переименовываются** (см. §19 inventory-first).
- **controller candidacy** — репродукция §2.1 (файл `*Controller.php` + `#[Route]` + унаследованные методы). **Отдельная диагностика:** если новый компилятор находит `#[Route]` в классе, не подходящем под candidacy (напр. не-`*Controller.php`) — halt с указанием, что ранее этот route не публиковался.
- **duplicate route keys** — детект `METHOD:path` коллизий (вкл. унаследованные) → halt (сегодня молча перетирается).
- **path-params** ← `{name}` ∩ сигнатура; тип из PHP (`int→integer`, не `string`). Несогласованность path↔signature → halt.
- **query/body** ← маркеры `#[QueryParams]/#[JsonBody]/#[PostBody]/#[FormDataBody]` (или `#[Validate]`) → DTO → `DtoSchemaBuilder`. contentType: Json/Post→`application/json`, FormData→`multipart/form-data`.
- **response (success):** return = DTO-eligible/enum/массив-DTO → infer 200. return = opaque `Response`/`array`(без item)/union/нестандартный status/headers → **обязателен** repeatable `#[Response]`; иначе halt. Runtime-контракт контроллеров не меняется.
- **required:** в **фазе parity** эмитить `required => [true]` ровно из тех же источников, что `extractValidationRules` (т.е. **только** из явного OA-аргумента `required` — продюсер сегодня не выводит required из PHP-типа; см. §3/§14), для побайтного совпадения. **Отдельной фазой** (после удаления OA) — вывод из PHP-типа (non-nullable без default). **Не смешивать.** `#[Required]` из плана **удалён** (в фреймворке есть dormant `Required` rule — не плодить путаницу).
- **enum** ← PHP backed enum (авто) | `#[Field(enum:[])]`.
- **массивы** ← `#[Items(class)]` (или docblock `@var X[]` как fallback с диагностикой); иначе halt «нет item-типа».
- **constraints (format/enum/min/max/length)** ← `#[Field]`.
- **security:** `#[NoAuthAccess]` ⇒ нет security; иначе `bearerAuth`; `#[AccessRulesAny/All]` ⇒ `x-required-rules{any,all}` (+опц. в description).
- **стандартные ошибки централизованно** (`StandardErrorPolicy`, **по runtime §2.5, без выдумывания**): 400 (если DTO+constraints), 401 (если не NoAuthAccess), 403 (если AccessRules*), 429 (если RateLimit), 500 (глобально, отключаемо). Тело = схема `createErrorBody`. **422 не добавлять.**

Границы автывода (явно): массивы без item-типа, generic-коллекции, union/intersection, polymorphism, dynamic `additionalProperties` → `#[Items]/#[Field]` или OA escape hatch, иначе halt.

## 8. Минимальный набор новых атрибутов
| Атрибут | Где | Почему не выводится | runtime? | корректность/док |
|---|---|---|---|---|
| `#[Response(schema:?class, status:200, description:?, contentType:'application/json', headers:[])]` repeatable | method | opaque Response, мульти-status, headers, array без item, override | нет | оба |
| `#[Operation(id:?, tags:[], summary:?, description:?, deprecated:false, exclude:false)]` | method | operationId override/коллизия, теги, описание, exclude | нет (compile) | док (+ id→клиент) |
| `#[Items(class:?class-string, type:?string)]` | property/param | PHP `array` без item-типа | да (rule graph массивов) | корректность |
| `#[Field(format:?, enum:[], min:?, max:?, minLength:?, maxLength:?, example:?, name:?, schema:?, readOnly:?, writeOnly:?)]` | property | format/enum/constraints/serial-name/schema-name/example не видны из PHP-типа | да (→ rule graph) | оба |

`#[Field]` **без** `oneOf/anyOf` — полиморфизм уходит в OA escape hatch (§13), чтобы не строить второй OpenAPI DSL. `#[Required]` удалён. Совместимость с `#[OA\...]` — параллельно; приоритеты конфликтов в §15.

## 9. Сравнение вариантов (кратко)
A (расширить OpenAPI-gen) — не убирает дублирование DTO→rules/DTO→schema, OA остаётся источником валидации. C (раздельные компиляторы на общей lib) — две orchestration-точки, больше шансов расхождения. **B** — единый граф → route cache и OpenAPI консистентны; `DtoSchemaBuilder` закрывает дублирование; DI orchestrate-only без ложного «единого reflection-pass». ⇒ **B**.

## 10. Выбранный вариант — обоснование
См. §1. Точка унификации `DtoSchemaBuilder` напрашивается из кода (сегодня `extractValidationRules` и swagger-php читают одни `#[OA\Property]` дважды). `Validator`/`DIContainer` нетронуты (контракты сохранены). Артефакты остаются var_export/yaml; preload/opcache-flow не меняется.

## 11. Безопасная публикация артефактов (compile-engine + production owner = клиентский preload)

### 11.1 Разделение ответственности
- **SpsFW = compile-engine:** `Coordinator`/`MetadataCompiler`, builders (`RouteMetadataCompiler`, `RouteCacheEmitter`, `OpenApiEmitter`, `StandardErrorPolicy`), `CompileDiagnostics`, staging/publication API. **Не владеет application bootstrap** и **не знает**, как клиент грузит env/dynamic-config/`Config::init()`/`Config::setDIBindings()`. Coordinator запускается с **явным application context**: `{ projectRoot, cachePath, discoveryPaths[], configInputs[] }`.
- **Production-владелец compilation flow = `next/preload.php`** (не SpsFW). Он инициирует Coordinator **после** готовности app-контекста.
- `new Router()` **не используется** как неявный способ сборки route cache: Coordinator строит route metadata напрямую (route cache = побочный артефакт компиляции, а не Router-discovery).

### 11.2 Целевая последовательность preload.php (production owner)
1. загрузить autoload и env;
2. `cacheDynamicConfigs()`;
3. `Config::init()` + `Config::setDIBindings()`;
4. вызвать framework `Coordinator` с явным application context (§11.1);
5. собрать routes, DI, job registry, OpenAPI **в staging**;
6. провалидировать комплект (`CompileDiagnostics::throwOnErrors()`);
7. опубликовать артефакты (atomic `rename` per file);
8. **последним** опубликовать manifest/fingerprint;
9. после успешной публикации — `opcache_compile_file()` для route/DI cache;
10. при ошибке компиляции — завершить preload ошибкой **и не запускать API** (readiness не наступает).
- **Удалить** из целевого preload предварительные `unlink(compiled_di.php)` и `unlink(compiled_routes.php)`: старый валидный комплект сохраняется до успешной публикации нового.

### 11.3 Гарантии публикации и честность про atomicity
Preload выполняется **до** готовности контейнера принимать трафик, поэтому публикация опирается на **lifecycle контейнера**, а не на атомарность всего набора:
- `flock` на `.cache/.compile.lock`;
- staging на **той же файловой системе**, что и кеш (для atomic `rename`);
- build + validate в staging;
- **atomic `rename` отдельных файлов** (атомарна на одной ФС);
- **manifest публикуется последним** (сигнал «новый комплект валиден»);
- **readiness контейнера — только после успешного preload**.
- ⚠️ **Не утверждать set-atomicity** при конкурентных читателях: корректность гарантируется lifecycle (нет трафика во время preload), а не единым атомарным swap-ом всех файлов. Замечание про несогласованность при гипотетическом параллельном чтении оставить как известное ограничение.
- **Подтверждено audit-ом (Приложение B):** preload подключён как `opcache.preload` во всех средах (dev/stage/prod/local) ⇒ FPM выполняет его один раз при старте master-процесса **до** подъёма воркеров; неперехваченное исключение в preload = fail старта FPM = контейнер не становится ready. Это и есть естественный readiness-gate.

### 11.4 Два режима
- **`legacy`** — текущая lazy-сборка (cache-miss в `Router::loadRoutes`/`createControllerInstance`/`Bootstrap`) для BC существующих клиентов SpsFW; **по умолчанию** до отдельной миграции.
- **`managed`/`prebuilt`** — клиент гарантирует сборку в preload (§11.2); отсутствие или невалидность кеша в production → **fail-fast без runtime discovery/reflection**. `Router`, `Bootstrap`, HTTP-запросы **не пересобирают** комплект в managed production. HTTP-rebuild допустим **только в dev**.
- Выбор режима — по app-config/context (учитывается в manifest `config_inputs`).

### 11.5 DI: compile-only API без throwaway-контейнера
`DIContainer` — **singleton**; «throwaway `DIContainer::getInstance()`» **не используется**. В `DICacheBuilder` добавляется **узкий compile-only API**: строит и **возвращает** DI/job artifacts (map + registry) **без записи в production cache и без `setCompiledMap()` на production-контейнере**. Старый `compile()` остаётся compatibility wrapper (внутри может вызывать новый API + публикацию, для `legacy`). Coordinator записывает полученный map в staging cache path.
- **Manifest/fingerprint** (публикуется последним): `{ compiler_version, src_fingerprint (hash controller/DTO файлов из обоих discovery), config_inputs (di_config, openapi_escape_hatch, operation_id_map.lock, standard_error_policy, mode), built_at, artifact_hashes }`. Invalidation = сравнение fingerprint.

### 11.6 Acceptance criteria (publication + managed mode)
- restart с пустой `.cache` создаёт полный комплект;
- restart с существующей `.cache` детерминированно обновляет его;
- ошибка компиляции не удаляет старые артефакты и **не позволяет контейнеру стать ready**;
- API не принимает трафик до окончания preload (verified audit-ом — см. §11.3 / Приложение B);
- первый HTTP-запрос в managed production не запускает scanning/reflection/compile;
- OpenAPI создаётся в application cache (`.cache/swagger/openapi.yml` в `next`), из которого его читает Orval.

## 12. (объединено в §11)

## 13. OA escape hatch (технически конкретно)
- **Разрешённые fragment-types:** только `components.schemas.<name>` и `components.responses.<status>` для **genuinely underivable** (polymorphism oneOf/anyOp/allOf+discriminator, dynamic `additionalProperties`, экзотические форматы). **Запрещено** переопределять route/security/request-validation.
- **swagger-php scan:** сканируется **whitelist** классов/файлов из `config/openapi_escape_hatch.php` (не весь src) → partial OA-doc.
- **Выбор fragments:** конфиг `config/openapi_escape_hatch.php` перечисляет FQCN/component-ключи для извлечения.
- **Merge keys:** `components.schemas.*`, `components.responses.*`.
- **ref/collision:** graph↔fragment по одному ключу → halt (если fragment претендует на ключ, который граф уже строит); refs graph→fragment и fragment→graph разрешены только для объявленных fragment-ключей.
- **Расположение конфига:** `config/openapi_escape_hatch.php` (app-level, учитывается в manifest `config_inputs`).
- **Поведение M1–M4:** M1–M3 fragments не используются (parity: граф и legacy OA независимо side-by-side); merge включается на M4.
- oneOf/anyOp живут **только** в escape hatch ⇒ `#[Field]` без `oneOf` (§8).

## 14. Validation compatibility matrix (явная; подтверждена characterization-тестом `tests/Compile/ValidationMatrixCharacterizationTest.php`)
Поведение `Validator::validateDtoWithCachedRules` (Validator.php:61) + `validateOpenApi` (296), которое **нельзя менять**:
| Случай | Поведение |
|---|---|
| value missing, `required!=[true]` или `nullable===true` | default/null, continue (ок) |
| value missing, `required==[true]`, not nullable | → `validateOpenApi('required')`: `empty($v) && $v!==[] && $v!==0` → throw 400 |
| `0` (int) | `!== 0` → **не** throw required; type-проверка отдельно |
| `'0'` (string) | `!== 0` (strict) → **throw** required для required-поля |
| `false` | `empty`=true, `!==0` strict, `!==[]` → **throw** required |
| `[]` | `!==[]` исключено → не throw required; но `type:array` примет |
| `null` explicit | как missing |
| `format:uuid/date/date-time/email` | null/non-string skip; иначе проверка |
| `enum:[...]` | `in_array($v, $ruleValue, true)` strict |
| nested DTO (`ref`) | рекурсив `validateDtoWithCachedRules(ref, nested_rules)` |
| collection (`type:array`+`ref`) | каждый элемент → рекурсив; enum-ref → `::from()` |
| promoted ctor-param | обрабатывается через `getProperties()` (дедуп) |
| non-promoted ctor-param с `#[OA\Property]` | отдельная ветка (421–481) |

`required` = `[true]` (массив). **Условие удаления legacy `validateDto()`:** `cachedRules !== null` для **каждой** операции (т.е. `dtos` всегда массив, возможно `[]`), **а не** «непустой graph». Не смешивать parity-миграцию (source=OA) с новым выводом required из PHP-типа.

## 15. План миграции (характеризация-first; entrypoint lifecycle; конфликты)
**Приоритет источников:** (1) явный собственный атрибут `#[Operation/#[Response]/#[Items]/#[Field]` > (2) auto-вывод из кода > (3) OA escape hatch (только whitelist). OA **никогда** не источник для routing/security/request-validation и не перекрывает собственный атрибут. Если OA и выводимое задают одно поле по-разному и это не escape-hatch → **halt**.

**Этапы:**
- **M0 Characterization** (§16, Шаг 0 — **выполнен**): snapshot validation matrix, rule-graph extraction, route pattern, access quirks (`tests/Compile/*Test.php`); preload/container-startup flow (Приложение B). Полный набор route keys / DI-shape / operationId inventory — в репо `next` (требуют app-context).
- **M1 Metadata model + Introspection** (additive, 0 поведения).
- **M2 DtoSchemaBuilder (schema+ruleGraph, parity)** — snapshot `RuleGraph == extractValidationRules` побайтно.
- **M3 Secondary OpenAPI emitter + parity** vs swagger-php.
- **M4 Staged Coordinator (engine) + safe publication (§11) + режимы**: SpsFW предоставляет Coordinator/engine + staging/publication API + compile-only DI API (F, Шаги 5, 6a); production-владелец flow — клиентский `preload.php` (N, Шаг 6b) по последовательности §11.2. В `managed`: №1/3 fail-fast без rebuild, №2 lazy-DI off в prod, №4 HTTP-compile dev-only, №6 thin wrapper; в `legacy` поведение не меняется. Каждый entrypoint — явный lifecycle (delegate/flag/removed).
- **M5 Switch route cache producer** (`dtos`←graph) **за флагом** + snapshot parity; `Validator` нетронут.
- **M6 Primary OpenAPI = graph + escape hatch merge (§13).**
- **M7 Очистка контроллеров от OA** (operations/parameters/responses/security) — per repo.
- **M8 Очистка DTO от OA + lifecycle legacy `validateDto()`** (deprecate → gate `cachedRules!==null` → remove) + удаление OA-пути в `extractValidationRules`.
- **M9 (отдельно, по согласию) Регенерация клиента** `public_next` (§21).

## 16. Тестирование
- **Runner:** `composer test` → `php tests/run.php` (plain PHP, glob `tests/*/*Test.php`, `assert_same/assert_true` из `tests/bootstrap.php`). **`vendor/bin/phpunit` отсутствует**, phpunit нет в `composer.json`. ⇒ новые тесты = `tests/Compile/*Test.php` (**два уровня** — glob `tests/*/*Test.php` не находит трёхуровневые `tests/Compile/Characterization/X.php`; поэтому файла положены напрямую в `tests/Compile/`). Если нужна PHPUnit — отдельное решение о `require-dev`.
- **Characterization-тесты (M0, добавлены):** `ValidationMatrixCharacterizationTest` (§14 matrix), `RuleGraphExtractionCharacterizationTest` (продюсер `extractValidationRules`), `RoutePatternCharacterizationTest` (`compileRoutePattern`), `AccessRulesCharacterizationTest` (`collectAccessRules` quirks). Полный route-keys/DI-shape/OpenAPI-operationId inventory — в репо `next`.
- **Unit:** `TypeMapper`, `DtoSchemaBuilder` (schema+ruleGraph) на реальных DTO, `OperationIdResolver` (конвенция/override/коллизия/lockfile), `AttributeReader`.
- **Snapshot/parity:** OpenAPI golden + diff vs swagger-php (M3); `RuleGraph` новый==старый (M2, M5).
- **Конфликты/диагностики:** path-param mismatch, массив без `#[Items]`, дубль operationId, дубль route key, не-eligible class return-type без `#[Response]`, OA-конфликт вне escape-hatch → halt.
- **Интеграция:** Coordinator на контроллерах `next` → staging-артефакты → atomic swap → bootstrap `Router`+`DIContainer` → dispatch реальных маршрутов с DTO-validate.
- **Кросс-репо (не требуется из clean SpsFW checkout, документируется):** `swagger-cli validate`/orval-генерация/tsc выполняются в `public_next`; указать, что они не предполагаются установленными в SpsFW.
- **Окружение после изменений:** пересобрать route cache; пересобрать DI cache (если bindings/конструкторы); перезапустить API-контейнер; реген `openapi.yml` + клиент.

## 17. Производительность и кеширование (с честным DI-ограничением)
- **Compile-only:** ClassScanner (DI, все классы), Router scanControllers (routes, `*Controller.php`), `DtoSchemaBuilder`, `RouteMetadataCompiler`, `OpenApiEmitter`.
- **В кеш:** `compiled_routes.php` (`dtos`=RuleGraph), `compiled_di.php`, `job_registry.php`, `openapi.yml`, опц. `metadata.php` + `manifest.php`.
- **Старт FPM:** `preload.php` (opcache.preload) → `opcache_compile_file` (без изменений).
- **Reflection в runtime:** не добавляется. `Validator` — cached rules; `DIContainer` — compiled map. Fallback `createInstanceWithDependencies` — только при промахе map (компилятор гарантирует покрытие контроллеров).
- **Reuse:** `DtoSchemaBuilder` memo по FQCN. Будущее: инкрементальный кеш `metadata.php` по fingerprint.
- **Честное ограничение:** общего Reflection-графа между Router и `DICacheBuilder` **нет и не строится** — `DICacheBuilder::analyze()` делает свой `new ReflectionClass`. Discovery тоже разный: routes = `*Controller.php` (filename), DI = все классы (`ClassScanner::getClassesFromDir`). «Один проход» верен только для DTO-анализа внутри графа.
- **Benchmark (before/after):** route-cache build, DI build, openapi-gen, размеры кешей, холодный старт, тёплый запрос.

## 18. Риски и откат
- Скрытая зависимость валидации от OA → M2/M5 за флагом + побайтный snapshot rule graph; откат = флаг + пересборка.
- operationId-дрейф → lockfile (§19), без автопереименования.
- Сложные схемы → `#[Field]` + escape hatch; конфликт ⇒ halt.
- Regression OpenAPI для клиента → parity-diff M3, golden snapshot, кросс-репо tsc.
- Подавленные swagger-php warning'и → явные `CompileDiagnostics`.
- **Откат:** Coordinator детерминирован; revert = флаг продуцента + прежний `DocsUtil`/`scanControllers` + пересборка; staging гарантирует нетронутость старого комплекта (§11).
- job_registry — явно в Coordinator.

## 19. operationId inventory ПЕРЕД первым новым snapshot
**Порядок:** сначала reconciliation, потом snapshot/lockfile.
1. Извлечь все `operationId:` из текущего `lk.sps38.pro/next/.cache/swagger/openapi.yml` (grep).
2. Извлечь имена функций/query-key из `public_next/src/lk-openapi/react-query` (grep `export const|export function|QueryKey`).
3. Построить **reconciliation table**: `controller::method | path | legacy spec operationId | current generated function | current query key | canonical ID`.
4. `canonical ID` = legacy spec operationId если есть; иначе (для ops без явного ID) — текущий method-name-fallback; **не** path-derived.
5. Только после этого — `config/operation_id_map.lock.php` (или материализация как `#[Operation(id:)]`). Новые операции — конвенция `<ControllerShort><Method>` + uniqueness; существующие — из lockfile. Смена любого canonical ID — только в M9 (согласованный шаг с регенерацией клиента).

## 20. Пошаговый план реализации (характеризация-first, per-repo)
> Репозитории: **(F)** SpsFW framework; **(N)** `lk.sps38.pro/next` consumer; **(P)** `public_next` client. Без правок production-кода на этапе планирования. TDD-разбивка — на фазе исполнения каждого шага.

### Шаг 0. Characterization + persist doc + preload flow audit (репо F; зависимостей нет) — ВЫПОЛНЕН
- **Действия:**
  - (а) скопировать этот документ в `docs/METADATA_COMPILER_PLAN.md` ✅;
  - (б) characterization-тесты в `tests/Compile/` ✅ (validation matrix, rule-graph extraction, route pattern, access quirks);
  - (в) зафиксировать фактический preload/container-startup flow клиента `next` ✅ (Приложение B).
- **Файлы:** `docs/METADATA_COMPILER_PLAN.md`; `tests/Compile/{ValidationMatrix,RuleGraphExtraction,RoutePattern,AccessRules}CharacterizationTest.php`.
- **Границы (выполнено):** production-код в `src/` и клиентский `preload.php` **не менялись** — только чтение, snapshot и документирование.
- **Критерии:** snapshot'ы зафиксировали текущее поведение (включая quirks); preload-flow задокументирован и подтверждён readiness-gate'ом через `opcache.preload`; тесты зелёные.

### Шаг 1. Metadata model + Introspection skeleton (F; depends 0)
- **Файлы (new):** `src/Core/Compile/Metadata/{RouteRuntimeMetadata,OperationMetadata,ParameterMetadata,RequestBodyMetadata,ResponseMetadata,SchemaMetadata,PropertyMetadata,SecurityMetadata,ValidationRuleGraph}.php` (readonly VO); `src/Core/Compile/{Coordinator,CompileDiagnostics}.php` (каркас); `src/Core/Compile/Introspection/{AttributeReader,TypeMapper}.php`.
- **Поведение:** VO compile-only; `CompileDiagnostics::error(controller,method,dto,field,cause,fix)` + `throwOnErrors()`.
- **Совместимость:** 0 изменений.
- **Тесты:** `tests/Compile/Metadata/*Test.php`, `tests/Compile/Introspection/TypeMapperTest.php`.
- **Критерии:** VO immutable; TypeMapper покрывает скаляры/enum/UUID/date/DateTimeInterface.

### Шаг 2. DtoSchemaBuilder + OperationIdResolver (F; depends 1)
- **Файлы (new):** `src/Core/Compile/Introspection/{DtoSchemaBuilder,OperationIdResolver}.php`.
- **Методы:** `DtoSchemaBuilder::build(class): SchemaMetadata` (+memo), `::ruleGraph(SchemaMetadata): ValidationRuleGraph` (строго совместим с `Validator`, §14); `OperationIdResolver::resolve(...)` + `assertUnique()` + honour lockfile.
- **Совместимость:** additive; старый `extractValidationRules` не трогается.
- **Тесты:** parity-snapshot `ruleGraph()` == `Router::extractValidationRules()` для эталонных DTO (побайтно); `OperationIdResolver`.
- **Критерии:** rule graph идентичен; resolver отдаёт lockfile/конвенцию; коллизия → halt.

### Шаг 3. RouteMetadataCompiler + RouteCacheEmitter (F; depends 2)
- **Файлы (new):** `src/Core/Compile/Route/{RouteMetadataCompiler,RouteCacheEmitter}.php`; новые атрибуты `src/Core/Attributes/OpenApi/{Operation,Response,Items,Field}.php`.
- **Поведение:** `RouteMetadataCompiler` репродуцирует discovery §2.1 (`*Controller.php` + `getPathToNamespace` + `#[Route]` + унаследованные) → `RouteRuntimeMetadata` (точный IR, вкл. access-quirks) + `OperationMetadata`. `RouteCacheEmitter` → IR-массив. Диагностики: duplicate route key, path-param mismatch, не-eligible return-type без `#[Response]`, массив без `#[Items]`.
- **Совместимость:** за флагом; продакшен route cache пока `Router`.
- **Тесты:** metadata для категорий §4; parity IR == Router IR; диагностики.
- **Критерии:** IR побайтно совпадает; все diagnostics работают.

### Шаг 4. OpenApiEmitter + StandardErrorPolicy + parity (F; depends 3)
- **Файлы (new):** `src/Core/Compile/OpenApi/{OpenApiEmitter,StandardErrorPolicy}.php`.
- **Поведение:** emit `OperationMetadata[]+SchemaMetadata[]` → yaml 3.1.0; стандартные ошибки по §2.5 (400/401/403/429/500, body=`createErrorBody`, **без 422**); security + `x-required-rules`. Эмиттит **вторичный** `.cache/swagger/openapi.generated.yml`.
- **Тесты/команды:** snapshot; parity-diff vs `openapi.yml` (нормализованный); фиксация divergence через `#[Items]/#[Field]/#[Response]`.
- **Критерии:** для мигрированных endpoint-ов generated ≡ old (с точностью до documented fixed-divergences).

### Шаг 5. Coordinator (engine) + staging/publication API + managed mode (F; depends 3,4)
- **Файлы (new/modify):** `src/Core/Compile/{Coordinator,Publication/StagingPublisher,Manifest/Fingerprinter}.php`; `src/Core/Compile/ApplicationContext.php` (`{ projectRoot, cachePath, discoveryPaths[], configInputs[], mode }`); узкий compile-only API в `DICacheBuilder` (строит+возвращает DI/job artifacts без записи в prod cache и без `setCompiledMap()`; старый `compile()` → compatibility wrapper); CLI/helper для dev-сборки.
- **Поведение (§11):** Coordinator принимает **явный ApplicationContext** и сам строит route metadata напрямую (без `new Router()`); flock → staging build (RouteCacheEmitter + OpenApiEmitter + DICacheBuilder compile-only API + job_registry) → diagnostics → atomic rename per-file → manifest last; возвращает результат публикации (success/fail).
- **Не делает:** не грузит env/config/DI bindings, не решает о readiness контейнера — это зона клиента (`next/preload.php`, Шаг 6b).
- **Совместимость:** `legacy`-режим сохраняет текущий lazy-flow; `managed`-режим — за app-config. Production FPM-flow не меняется до Шага 6a.
- **Тесты:** unit Coordinator (staging build по фиксам → ожидаемые артефакты); atomic-rename (ошибка → старый комплект цел); manifest fingerprint; режим legacy/managed.
- **Критерии:** Coordinator собирает routes+DI+job+openapi в staging по ApplicationContext и публикует per-file atomic; compile-only DI API не трогает prod-контейнер; behavior идентичен legacy.

### Шаг 6a. Mode guards в SpsFW entrypoint-ах (F; depends 5) — M4 (engine side)
- **Modify:** `legacy`/`managed` выбор по app-config/context; в `managed` — №1 `Router::loadRoutes` cache-miss и №3 `Bootstrap::getRouter` → fail-fast без runtime rebuild; №2 `createControllerInstance` lazy-DI → в managed/prod off (только dev); №4 `CoreUtilController` HTTP-compile → dev-only (deprecate→remove); №6 `DocsUtil::updateDocs` → thin wrapper над Coordinator. Каждый entrypoint — lifecycle (delegate / flag / removed) с критериями. В `legacy` поведение не меняется.
- **Тесты:** каждый entrypoint в обоих режимах; prod fail-fast; dev-allow.
- **Критерии:** в `managed` первый HTTP-запрос не запускает scanning/reflection/compile; HTTP-compile только dev; `legacy` не регрессирует.

### Шаг 6b. Интеграция Coordinator в реальный `next/preload.php` (N; depends 5, 6a)
- **Modify (клиентский preload, репо N):** реализовать целевую последовательность §11.2: autoload+env → `cacheDynamicConfigs()` → `Config::init()`+`Config::setDIBindings()` → `Coordinator` с явным ApplicationContext (project root, cache path, discovery paths, config inputs) → staging build → validate → publish artifacts → manifest last → `opcache_compile_file()` route/DI. **Удалить** предварительные `unlink(compiled_di.php)`/`unlink(compiled_routes.php)`. При ошибке компиляции — завершить preload с ненулевым кодом и не запускать API.
- **Handoff F→N:** тег релиза F → `composer update tixlag/php-framework` в N.
- **Проверка entrypoint (§11.3, Приложение B):** preload подключён как `opcache.preload` ⇒ fail старта FPM = не ready.
- **Тесты:** restart с пустой/существующей `.cache`; инжект ошибки компиляции → старые артефакты целы, контейнер не ready.
- **Критерии:** acceptance §11.6 выполнены (включая «API не принимает трафик до preload» и «ошибка не даёт readiness»).

### Шаг 7. Switch route cache producer (F; depends 5,6a) — M5
- **Modify:** `Router::registerControllerRoutes()` берёт `dtos` из `DtoSchemaBuilder::ruleGraph()` **за флагом** (env/Config), иначе старый `extractValidationRules()`.
- **Совместимость:** `Validator` нетронут; откат — флаг.
- **Тесты:** snapshot `compiled_routes.php['dtos']` новый==старый для всех DTO; dispatch-интеграция.
- **Критерии:** rule graph идентичен; валидация как раньше.

### Шаг 8. Primary OpenAPI = graph + escape hatch (F; depends 4,6a) — M6
- **Modify:** `DocsUtil`/Coordinator эмиттит основной `openapi.yml` из графа; swagger-php scan только whitelist `config/openapi_escape_hatch.php` (§13) + merge. Конфликт OA↔вывод вне escape-hatch → halt.
- **Тесты:** golden snapshot; escape-hatch merge; halt на конфликте.
- **Критерии:** spec валиден; escape hatch ограничен; расхождения детерминированы.

### Шаг 9. Clean controllers from OA (репо N; depends 8) — M7
- **Действия:** удалить `#[OA\Get/Post/Patch/Parameter/RequestBody/Response/Security]` с мигрированных контроллеров `next`; заменить нужным `#[Operation]/#[Response]/#[Items]/#[Field]`. DTO OA пока остаётся.
- **Handoff F→N:** тег релиза F → `composer update tixlag/php-framework` в N → пересборка cache+openapi. Отдельный commit в N.
- **Тесты:** golden `openapi.yml` стабилен; dispatch.
- **Критерии:** контроллеры без operation-OA; spec идентичен.

### Шаг 10. Clean DTO from OA + legacy validateDto lifecycle (F+N; depends 7,9) — M8
- **Modify:** удалить `#[OA\Property/Schema]` с DTO где вывод достаточен (добить `#[Field]/#[Items]`); lifecycle `Validator::validateDto()`: deprecate (M8a) → gate «cachedRules!==null обязательно» (M8b) → remove `validateDto()` + OA-импорт (M8c); удалить OA-путь в `Router::extractValidationRules`.
- **Тесты:** snapshot rule graph стабилен после удаления OA; негативный тест `cachedRules===null` → явная ошибка.
- **Критерии:** DTO без OA; валидация полностью на графе; legacy удалён.

### Шаг 11. Coordinated client regen (репо P; отдельно, по согласию) — M9
- **Действия:** orval по новому spec в `public_next`; правка imports/hooks/query-keys; `tsc --noEmit`; live-запросы. Только если согласовано изменение operationId (иначе чистый реген без переименований).
- **Критерии:** tsc зелёный; live-запросы через хуки проходят.

## 21. Репозитории и handoff (scope по корректировке #8)
- **(F) SpsFW** — **compile-engine целиком**: `Coordinator`/builders/diagnostics/staging+publication API, compile-only DI API, атрибуты, режим `legacy`/`managed` (engine side), characterization-тесты. **Не** правит клиентский bootstrap/preload. Версионировать тегом.
- **(N) `lk.sps38.pro/next`** — **application integration**: реальный `preload.php` вызывает Coordinator по последовательности §11.2 (app context, container startup, readiness-ворота, opcache); миграция контроллеров/DTO от OA; пересборка cache+openapi. Отдельный commit; rollback boundary = pin версии F.
- **(P) `public_next`** — **только** последующая Orval-регенерация клиента (M9) + tsc. Не участвует в сборке кешей.
- Handoff-чекпойнты: F-tag (engine) → N (preload integration + `composer update tixlag/php-framework:@dev`) → P (orval regen + typecheck). Откат — по repo независимо (pin).
- Важно: **сборка кешей в production выполняет клиентское приложение `next`** через свой preload; SpsFW предоставляет только engine и API публикации.

## 22. Где сохранён документ
Канонический экземпляр — **`docs/METADATA_COMPILER_PLAN.md`** (этот файл). staging-копия план-режима — `~/.claude/plans/cheerful-herding-bear.md`.

---

## Приложение A. Таблица «пункт ревью → где исправлен»
| # | Пункт ревью | Где исправлено |
|---|---|---|
| 1 | Metadata-модель воспроизводит весь route cache; отделить RouteRuntimeMetadata от SecurityMetadata | §6 (A/B/C), §3 (access-quirks), Шаг 3 |
| 2 | Единственный владелец compilation flow; миграция всех entrypoint-ов | §2.4 (6 entrypoints), §15 (M4), Шаг 6a/6b |
| 3 | Безопасная публикация (lock/staging/validate/manifest/HTTP-compile policy) | §11 (целиком), Шаг 5 |
| 4 | Discovery-аудит: ClassScanner vs Router filename-filter; candidacy snapshot; duplicate keys; inherited | §2.1, §7 (candidacy/dup/inherited), §16 (M0), Шаг 0/3 |
| 5 | Validation compatibility matrix; required=[true]; не смешивать parity и новый вывод; #[Required] удалён; removal cachedRules!==null | §14 (matrix), §7 (required phases), §8 (#[Required] убран), Шаг 2/7/10 |
| 6 | DTO eligibility + serialization contract; #[Field(name)] vs json_encode; domain/base classes | §6 (eligibility gate + serialization), §7, Шаг 3 |
| 7 | Characterize auth-семантику (All-only, class-level); StandardErrorPolicy по runtime (400/401/403/429/500, без 422; createErrorBody) | §2.5, §3 (access quirks), §7 (standard errors), §16 (M0), Шаг 0/4 |
| 8 | OA escape hatch конкретно (fragments/scan/merge/collision/config/M1–M4); oneOf из #[Field] убран | §13 (целиком), §8 (oneOf убран), Шаг 8 |
| 9 | Тест-команды: composer test→php tests/run.php; нет phpunit; clean checkout | §16 (runner), все шаги (composer test) |
| 10 | Шаги по репозиториям F/N/P; handoff; composer update; rollback boundary | §20 (per-repo steps), §21 |
| 11 | operationId inventory ПЕРЕД snapshot; reconciliation table → lockfile | §19 (целиком), Шаг 0 |
| 12 | Канонический документ в репо `docs/METADATA_COMPILER_PLAN.md` | §22, Шаг 0 |
| Ф | **Финальная корректировка** (preload-owned flow): SpsFW = engine без bootstrap; production-owner = `next/preload.php` (§11.2); нет предв. `unlink`; `new Router()` не сборщик; честность про atomicity + проверка entrypoint (§11.3, Приложение B); режимы legacy/managed (§11.4); compile-only DI API без throwaway-контейнера (§11.5); scope F/N/P (§21, Шаги 5/6a/6b); acceptance §11.6 | §11 (11.1–11.6), §1, §2.4, §15 (M4), §21, Шаги 5/6a/6b, Приложение B |
| — | Реorder: characterization → model → emitter/parity → staged coordinator → switch/remove | §15 (M0…M9), §20 (Шаги 0…11) |

---

## Приложение B. Audit: фактический preload / container-startup flow клиента `next` (Шаг 0в)

**Источники (read-only, правок не вносилось):** `lk.sps38.pro/next/preload.php`, `lk.sps38.pro/next/index.php`, `SpsFW/example/preload.php` (шаблон фреймворка), `docker/*/etc/php/php_next*.ini` (dev/stage/prod/local).

**1. Шаблон `example/preload.php` фреймворка и клиентский `next/preload.php` — идентичны** (клиент использует шаблон фреймворка as-is). Наблюдаемая последовательность:

| # | Действие | Файл:строка (`next/preload.php`) |
|---|---|---|
| 1 | `require 'vendor/autoload.php'` | 9 |
| 2 | preload essential classes (`Router`, `DIContainer`) | 26–31 |
| 3 | `easyEnv('.env')` + `easyEnv('.env.{ENV}')` | 34–35 |
| 4 | `require config/cache_helpers.php`; `cacheDynamicConfigs()` | 39–40 |
| 5 | `Config::init([... db config ...])` | 44–64 |
| 6 | `require config/di_config.php`; `Config::setDIBindings($diBindings)` | 67–68 |
| 7 | **`unlink` старых `compiled_di.php` и `compiled_routes.php`** | 70–74 |
| 8 | **`new Router()`** (строит route cache через конструктор→scanControllers) | 76 |
| 9 | `DICacheBuilder::compileDI($router->container)` | 77 |
| 10 | `opcache_compile_file(...compiled_routes.php)` + `...compiled_di.php` | 82–83 |
| 11 | `DocsUtil::updateDocs()` (OpenAPI) | 85 |

**2. Readiness-gate подтверждён (§11.3).** `preload.php` подключён как **`opcache.preload`** во всех средах:
```
docker/etc/php/php_next.ini:20:              opcache.preload=/var/www/next.sps38.pro/preload.php
docker/dev/etc/php/php_next.dev.ini:18:      opcache.preload=/var/www/next.sps38.pro/preload.php
docker/stage/etc/php/php_next.dev.ini:18:    opcache.preload=/var/www/next.sps38.pro/preload.php
docker/prod/etc/php/php_next.dev.ini:18:     opcache.preload=/var/www/next.sps38.pro/preload.php
docker/local/etc/php/php_next.dev.ini:18:    opcache.preload=/var/www/next.sps38.pro/preload.php
```
Следствие: PHP-FPM выполняет preload **один раз при старте master-процесса, до подъёма воркеров и до приёма первого запроса**. Неперехваченное исключение/фатал в preload = **fail старта FPM** ⇒ контейнер не становится ready. Это естественный readiness-gate, закрывающий acceptance §11.6 («API не принимает трафик до окончания preload», «ошибка компиляции не даёт ready»). Конкурентных читателей во время preload нет ⇒ оговорка §11.3 про set-atomicity оправдана.

**3. Request-time entry (`next/index.php`).** Каждый запрос: `require bootstrap.php` → `new Router()` (cache HIT → `loadRoutes()` `require`-ит `compiled_routes.php`, без rebuild) → `addGlobalMiddleware(RateLimitMiddleware)` → `dispatch()`. Т.е. при успешном preload request-time не пересобирает кеш; lazy-rebuild (entrypoint №1) в production фактически недостижим, т.к. FPM не стартует при провале preload.

**4. Расхождение с целевым flow (§11.2) — что меняется в Шаге 6b (правки только в `next/preload.php`, не сейчас):**
- **удалить** шаг 7 (`unlink` старых кешей) — старый валидный комплект должен сохраняться до успешной публикации нового (§11.2, корректировка #3);
- **заменить** `new Router()` + `DICacheBuilder::compileDI()` (шаги 8–9) на вызов framework `Coordinator` с явным `ApplicationContext` (project root, cache path, discovery paths, config inputs) — Coordinator сам строит route metadata напрямую, без `new Router()` (§11.1, корректировка #4);
- Coordinator публикует артефакты через staging (atomic rename per-file) + manifest последним; `opcache_compile_file` — после успешной публикации (шаг 10 сохраняется);
- при ошибке компиляции — ненулевой exit (FPM не стартует → not ready).

**5. Не-блокирующие наблюдения:**
- `opcache.preload_user` = `root` в dev/local/prod/stage и `www-data` в части ini — на план не влияет.
- В `next` замечен `phpunit.xml`/`.phpunit.result.cache`/`run_test.php` — у клиента свой test-ranner; SpsFW использует `composer test` → `php tests/run.php` (§16).
