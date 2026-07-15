# Metadata Compiler — M0 Audit (inventory / IR-shape / operationId / deploy)

> **Назначение:** фактические артефакты Шага 0 (M0), на которые ссылается `docs/METADATA_COMPILER_PLAN.md`
> (§16 M0, §19 operationId inventory, Приложения B/C). M0 **считается открытым** до приёмки этих артефактов.
>
> **Принцип clean-checkout:** SpsFW из clean checkout **не зависит** от соседних репо — characterization-тесты
> (`tests/Compile/*Test.php`) читают только собственный `src/` фреймворка. Данные ниже — это **снимки**
> состояния приложения-консьюмера `lk.sps38.pro/next` (N) и клиента `public_next` (P), зафиксированные на
> конкретных коммитах со ссылкой на пути/коммиты и командой регенерации. Они служат справочным inventory,
> а не тестовой зависимостью.

| Репо | Путь на диске | Коммит-снимок | Ветка |
|---|---|---|---|
| **F** SpsFW | `/home/tixlag/PhpstormProjects/SpsFW` | (текущая ветка `feature/metadata-compiler-step0`) | `feature/metadata-compiler-step0` |
| **N** next | `/home/tixlag/PhpstormProjects/lk.sps38.pro/next` | `07a98801a` | `main` |
| **P** public_next | `/home/tixlag/PhpstormProjects/lk.sps38.pro/public_next` | `07a98801a` | `main` |

---

## 1. Route inventory (источник: N `.cache/compiled_routes.php`)

**Полный файл:** `docs/metadata_compiler_audit/route_inventory.txt` (380 строк: 3 строки заголовка + 377 маршрутов).
Формат колонок (TAB-separated): `METHOD  path  controller::method  hasDto  hasAccessRules  hasMiddlewares  hasPhpIni`
(маркер `Y` / `-`).

### 1.1 Сводка
| Метрика | Значение |
|---|---|
| Всего маршрутов (cache-level) | **377** |
| Контроллеров | **70** |
| GET / POST / PUT / PATCH / DELETE | 186 / 130 / 18 / 6 / 37 |
| С DTO (`dtos` в IR) | **182** |
| С `access_rules` | **81** |
| С `middlewares` (class+method merge) | **11** |
| С `php_ini_settings` | **3** |
| Path-параметров (`{x}`) всего | 155 |
| **Дубли `METHOD:path` на cache-уровне** | **0** (377 ключей уникальны) |

### 1.2 ⚠️ Caveat про дубли (критично для §7 / Шага 3)
Cache-level дубли отсутствуют **только потому, что `Router::registerControllerRoutes()` молча перетирает
проигравший** при коллизии ключа `$routes[$key]` (`METHOD:path`, Router.php:290) — cache не сохраняет
проигравшую запись. ⇒ **нулевое число дубликатов в cache НЕ означает отсутствия коллизий при сканировании**.
Новый `RouteMetadataCompiler` обязан **детектить коллизии `METHOD:path` на этапе controller-scan** (включая
унаследованные методы) и `halt` с диагностикой (`controller::method`, оба претендента). Это единственный
способ поймать дубли — post-hoc по cache их уже не видно.

### 1.3 Метод генерации (regenerable, не зависит от SpsFW-runtime)
```bash
# Из корня репо N (lk.sps38.pro/next):
php -r '
  require "vendor/autoload.php";                       // cache содержит enum-инстансы ParamsIn
  $routes = require ".cache/compiled_routes.php";      // без autoload — Class-not-found
  foreach ($routes as $key => $r) {
      [$method,$path] = explode(":", $key, 2);
      $dto = !empty($r["dtos"]) ? "Y" : "-";
      $acc = !empty($r["access_rules"]) ? "Y" : "-";
      $mw  = !empty($r["middlewares"]) ? "Y" : "-";
      $ini = !empty($r["php_ini_settings"]) ? "Y" : "-";
      echo implode("\t", [$method,$path,$r["controller"]."::".$r["method"],$dto,$acc,$mw,$ini])."\n";
  }
' | sort > SpsFW/docs/metadata_compiler_audit/route_inventory.txt
```
Снимок сделан read-only из N (production-код N не менялся).

---

## 2. Route-cache IR characterization (источник: F `src/` + characterization-тесты)

Полная проекция IR, которую производит `Router::registerControllerRoutes()` (Router.php:224), зафиксирована
тестом **`tests/Compile/FullRouteIrCharacterizationTest.php`** (новый в fix-pass). Поля IR
(Router.php:291–302):

`controller, httpMethod, method, rawPath, pattern, params[{name}=>null, порядок path-имён],
middlewares[{class,params}] (class+method merge), access_rules, dtos[{in(ParamsIn), dto(class), rules(RuleGraph)}],
php_ini_settings`.

Закреплённые инварианты ( будущий `RouteMetadataCompiler`+`RouteCacheEmitter` обязан воспроизвести побайтно):
- Ключ IR = `METHOD:path`; `pattern` = якорный regex (`#^/fix/([^/]+)$#`).
- **Асимметрия middleware/access:** `#[Middleware]` на **классе** собирается и merge'ится (class ++ method);
  `#[AccessRulesAny]` на **классе** — **игнорируется** (`collectAccessRules` method-only, Router.php:575).
- DTO-запись строится только для типизированного параметра с marker-атрибутом (`#[JsonBody]` и т.п.); `int $id` пропускается.
- `access_rules` для метода без method-level access = `[]` (даже если есть class-level).

Расширенные producer/consumer-контракты (rule graph + validation matrix):
| Тест | Что закрепляет |
|---|---|
| `ValidationMatrixCharacterizationTest` | runtime-поведение `Validator::validateDtoWithCachedRules` (§14 matrix): required=`[true]`,quirks `0`/`'0'`/`false`/`[]`, type/enum/format, **+ consumer-side**: default application, nested DTO, collection DTO, enum-ref single+collection |
| `RuleGraphExtractionCharacterizationTest` | producer `Router::extractValidationRules`: key=OA`property`/`real_name`, required verbatim (не выводится), ref+nested_rules, **+ promoted ctor-param, non-promoted ctor-param с `#[OA\Property]`, default-precedence (property>ctor>OA; null⇒не эмитится)** |
| `RoutePatternCharacterizationTest` | `compileRoutePattern` → якорный regex |
| `AccessRulesCharacterizationTest` | `collectAccessRules` quirks: NoAuthAccess→`['NO_AUTH_ACCESS']`, All-only→`[]`, any/all, **+ class-level access ignored** |
| `FullRouteIrCharacterizationTest` | полная IR-проекция `registerControllerRoutes` (middleware merge, class-level access ignored, dtos, php_ini) |

---

## 3. compiled_di / job_registry — shape (authoritative из F `src/`)

**Shape подтверждён из исходников SpsFW** (не зависит от соседних репо):

`DICacheBuilder::analyze()` (`src/Core/Router/DICacheBuilder.php:47–52`) возвращает для каждого класса:
```php
[
  'class'              => string,                 // FQCN
  'args'               => array,                  // config-bound args
  'constructor_params' => [['name'=>..,'class'=>..,'position'=>..], ...],
  'has_constructor'    => bool,
]
```
Файлы кеша (`DICacheBuilder::writeToFile`, строки 125/133):
- `compiled_di.php` = `[ FQCN => <analyze-shape выше> ]`
- `job_registry.php` = `[ jobName => ['jobClass'=>string,'handlerClass'=>string] ]` (из `#[QueueJob]`/`#[JobHandler]`)

**Файлы `*Test.php` пропускаются** (DICacheBuilder.php:40). `compile()` имеет побочный эффект
`setCompiledMap()` (строка 49) — именно его compile-only API (Шаг 5) обязан избегать на prod-контейнере.

### 3.1 Снимок объёма (источник: N `.cache/compiled_di.php` + `.cache/job_registry.php`)
| Артефакт | Записей |
|---|---|
| `compiled_di.php` | **857** классов |
| `job_registry.php` | **9** job'ов |
| `compiled_routes.php` | 377 маршрутов (см. §1) |

---

## 4. operationId reconciliation (источники: N spec + P generated client)

### 4.1 N: спецификация, которую читает Orval
- Файл: `N/.cache/swagger/openapi.yml` (672 КБ), `openapi: 3.1.0`, `paths:` блок, генерируется
  `DocsUtil::updateDocs()` (swagger-php).
- **Явных `operationId:` — 40** (остальные ~337 операций получают **method-name-fallback** через
  swagger-php `SetOperationIdFromMethodNameProcessor`).

**40 явных operationId (verbatim, отсортированы) — база canonical ID:**
```
addAccessRules                 getRoadSheetsByVehicleCode         registerUser
addAdminEmployeeAccessRole     getRegistrationTokenByChatUUID     resetPassword
createDayReportsPdfLists       getScannedRoadSheetPdf             resetPasswordByEmail
createPasswordResetLink        getUserAccessRules                 revokeDevice
createResetCode                getUserDevices                     revokeOtherDevices
createWorksheetReportPdf       getWorksheetReportScans            runAdminEmployeeExchangeByHireDate
deleteAdminEmployeeAccessRole  loginByCode1c                      setAccessRules
downloadTicketFile             loginUser                          setUserAccessRules
generateRoadSheetsPdf          logoutUser                         submitResetLink
getAccessRulesClasses          parseIdBirthday                    syncRulesFrom1c
getAdminEmployeeAccessRoles    previewAdminEmployeeExchangeByHireDate  uploadWorksheetReportScans
getDayReportPdfPath            refreshTokens                      verifyResetCode
getFullEmployeeByCode1c        registerByChatUUID                 verifyRestoreCode
```

### 4.2 Правило canonical ID (§19)
1. `canonical ID` = явный operationId из §4.1, если операция его имеет.
2. Иначе — текущий **method-name-fallback** (имя PHP-метода контроллера). **Не** path-derived.
3. База для reconciliation (controller::method ↔ path для всех 377) — `route_inventory.txt` (§1).
4. Lockfile `config/operation_id_map.lock.php` (или материализация `#[Operation(id:)]`) формируется
   **после** reconciliation; новые операции — конвенция `<ControllerShort><Method>` + uniqueness.
   Любая смена canonical ID — только в M9 (согласованный шаг с регенерацией клиента P).

### 4.3 P: сгенерированный клиент
- `orval.config.ts`: `input: ../next/.cache/swagger/openapi.yml` (3.1.0 из §4.1), `client: react-query`,
  `useOperationIdAsQueryKey: true` ⇒ **query-key = operationId** (поэтому стабильность operationId критична).
- Сгенерировано в `P/src/lk-openapi/react-query`: **1448** экспортов (react-query хуки + TS-типы).
- **Имя функции path-derived** (напр. `getApiAuthMe`, `deleteApiAchievementsDeleteLevelUuid`) — это
  orval-конвенция имён функций; **query-key при этом = operationId**. ⇒ имя функции и operationId
  расходятся по схеме наименования — это известный дефект pipeline, **не основание** делать canonical
  operationId path-derived (§19 явно: canonical = opId/method-name).

### 4.4 ⚠️ Drift: отдельный `P/openapi.yaml`
- `P/openapi.yaml` (270 КБ, `openapi: 3.0.0`, **143** `operationId`) — **другой файл**, не равный
  `N/.cache/swagger/openapi.yml` (3.1.0, 40 явных), который реально читает Orval.
- Это либо устаревший коммит спецификации, либо спецификация другого продукта. **Reconciliation обязан
  исходить из cache-спеки (§4.1) как источника истины для клиента**; `P/openapi.yaml` классифицируется
  отдельно (вероятно — удалить/архивировать в M9).

---

## 5. Deploy / Docker / cache-volume audit

Подробно — **Приложение C** `docs/METADATA_COMPILER_PLAN.md` (authoritative). Краткое резюме:

| Параметр | Значение |
|---|---|
| ENTRYPOINT / CMD | `/usr/local/bin/entrypoint.sh` / `php-fpm` (dev+prod target) |
| compose-сервис | `php_next` (`docker/prod/docker-compose.yml`), `restart: always` |
| healthcheck | `php-fpm-healthcheck` (process-level), `start_period: 10s`, `retries: 100` |
| deploy strategy | **stop-then-start** (`docker compose restart --no-deps php_next` после rsync; `.cache/` исключён из rsync); **1 реплика, replicas/update_config отсутствуют** |
| cache volume | bind mount `../../next/.cache:/var/www/next.sps38.pro/.cache` — **общий для любого контейнера на хосте** |
| preload→ready | preload = синхронный шаг master-стартапа FPM (`opcache.preload`); воркеры поднимаются после; исключение в preload = FPM не стартует = **not ready** |

**Ключевой вывод (§11.3):** при текущем stop/start-деплое **конкурентных читателей `.cache` нет** ⇒
per-file atomic `rename` + lifecycle достаточны. НО `.cache` — общий bind mount ⇒ **при переходе на
rolling/replicas** гонка неизбежна ⇒ **обязательно**: versioned cache dir + `current` pointer (или
container-local cache) **до** включения rolling. Зафиксировано как требование к деплою.

---

## 6. N/P inventory — справочно (commit/path refs)

| Что | Где | Кол-во | Примечание |
|---|---|---|---|
| Маршруты (cache) | `N/.cache/compiled_routes.php` | 377 | §1, фикс-снимок `07a98801a` |
| DI-карта (cache) | `N/.cache/compiled_di.php` | 857 | §3.1 |
| Job-registry (cache) | `N/.cache/job_registry.php` | 9 | §3.1 |
| OpenAPI spec (Orval input) | `N/.cache/swagger/openapi.yml` | 3.1.0, 172 paths, 40 явных opId | §4.1 |
| Устаревший spec (drift) | `P/openapi.yaml` | 3.0.0, 143 opId | §4.4 — отдельный файл |
| Сгенерированный клиент | `P/src/lk-openapi/react-query` | 1448 экспортов | §4.3, react-query, `useOperationIdAsQueryKey:true` |
| Клиентский preload | `N/preload.php` | — | Приложение B плана (последовательность 11 шагов) |
| Контроллеры (F discovery) | `*Controller.php` в `getControllersDirs()` | 70 (N) | §2.1 плана |
| Characterization-тесты (F) | `tests/Compile/*Test.php` | 5 | §2 этого аудита |

Все N/P-данные получены **read-only**; production-код F и N в Шаге 0 **не менялся**.
