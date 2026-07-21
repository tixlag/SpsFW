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

### 1.3 Метод генерации (regenerable, read-only из N)
Запуск **из корня SpsFW** (использует собственный autoload SpsFW; читает cache репо N по абсолютному пути;
результат пишется во временный файл и затем копируется в SpsFW — чтобы не плодить cross-repo путей в команде):
```bash
cd /home/tixlag/PhpstormProjects/SpsFW
NEXT=/home/tixlag/PhpstormProjects/lk.sps38.pro/next
php -r '
  require "vendor/autoload.php";                         // autoload SpsFW (cache содержит enum-инстансы ParamsIn)
  $routes = require $argv[1] . "/.cache/compiled_routes.php";  // без autoload — Class-not-found
  foreach ($routes as $key => $r) {
      [$method,$path] = explode(":", $key, 2);
      $dto = !empty($r["dtos"]) ? "Y" : "-";
      $acc = !empty($r["access_rules"]) ? "Y" : "-";
      $mw  = !empty($r["middlewares"]) ? "Y" : "-";
      $ini = !empty($r["php_ini_settings"]) ? "Y" : "-";
      echo implode("\t", [$method,$path,$r["controller"]."::".$r["method"],$dto,$acc,$mw,$ini])."\n";
  }
' "$NEXT" | sort > /tmp/route_inventory.txt
cp /tmp/route_inventory.txt docs/metadata_compiler_audit/route_inventory.txt
```
Снимок сделан read-only из N (production-код N не менялся). Полный OpenAPI-reconciliation
(spec + routes + generated client) генерируется отдельным скриптом — см. §4.2.

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

**⚠️ Quirk: фильтр `*Test.php` фактически не работает.** В `compile()` (DICacheBuilder.php:40) стоит
`if (str_ends_with($class, 'Test.php')) continue;`, но `$class` здесь — **FQCN** (из
`ClassScanner::getClassesFromDir()`, далее передаётся в `new ReflectionClass($class)`), а не имя файла.
FQCN не содержит `.php`, поэтому `str_ends_with($class, 'Test.php')` **почти всегда ложно** — тестовые
классы НЕ отфильтровываются и попадают в `compiled_di.php`. Это **current quirk**, а не рабочий фильтр:
новый Coordinator обязан явно исключать test-классы по корректному признаку (напр. FQCN namespace
`*\Test\*` / суффикс `Test`, либо фильтрация на этапе ClassScanner по пути файла).
`compile()` имеет побочный эффект `setCompiledMap()` (строка 49) — именно его compile-only API (Шаг 5)
обязан избегать на prod-контейнере.

### 3.1 Снимок объёма (источник: N `.cache/compiled_di.php` + `.cache/job_registry.php`)
| Артефакт | Записей |
|---|---|
| `compiled_di.php` | **857** классов |
| `job_registry.php` | **9** job'ов |
| `compiled_routes.php` | 377 маршрутов (см. §1) |

---

## 4. operationId reconciliation (источники: R routes + S spec + P generated client)

**Полная таблица:** `docs/metadata_compiler_audit/operation_id_reconciliation.tsv` (379 строк +
заголовок-counts). Генератор: `docs/metadata_compiler_audit/gen_operation_id_reconciliation.php`
(читает `route_inventory.txt` + N spec + P endpoints, read-only; пути к N/P — параметры со значениями
по умолчанию для этого хоста).

Колонки TSV (12): `METHOD | path | controller::method | in_spec | legacy_op_id |
id_source(explicit|method-fallback|route-only|spec-only) | generated_function | query_key |
migration_candidate_id | canonical_id | lockfile(in|deferred|out) | classification`. Сопоставление R↔S↔P идёт по
**нормализованному** ключу `METHOD:normpath`, где path-параметры схлопнуты в `{}` — это необходимо, т.к.
swagger-php эмиттит snake_case path-params (`{code_1c}`), а orval переписывает url camelCase TS-варами
(`${code1c}`); структура (позиции параметров) сохраняется. **query_key извлекается из реального P**
(геттер `get{Fn}QueryKey`, первый строковый литерал возвращаемого массива); операции без геттера (мутации)
получают `-` — значение **не фабрикуется** как `qk=fn`.

### 4.1 N: спецификация, которую читает Orval
- Файл: `N/.cache/swagger/openapi.yml` (672 КБ), `openapi: 3.1.0`, генерируется `DocsUtil::updateDocs()`.
- **300 path-ключей / 347 operations** (⚠️ ранее в этом аудите и в плане ошибочно фигурировало «172 paths»
  — это артефакт `grep '^  /'`: swagger-php **кавычит** параметризованные пути как `'/api/.../{x}'`, и grep
  по 2-space-indent+`/` ловил только 172 пути без параметров; Symfony YAML-парсер даёт корректные 300).
- **Явных `operationId:` — 39** (все в R∩S; `generated_function == operationId` для всех 39). Ещё
  **306 operations в spec без operationId** (`SetOperationIdFromMethodNameProcessor` у swagger-php НЕ активен
  — иначе все 347 имели бы id) и **2 spec-only** (orphan/stale, §4.5). ⇒ для 306 documented operations
  canonical id сегодня не задан вовсе, а клиент получает path-derived имена.

### 4.2 Итоги reconciliation (счётчики)
| Метрика | Значение |
|---|---|
| R∩S (routes в spec) | **345** |
| R\S (routes **отсутствуют** в spec — недокументированы) | **32** |
| S\R (spec operations без route — orphan/stale) | **2** |
| explicit (operationId в spec, R∩S) | **39** |
| method-fallback (в R∩S, без operationId) | **306** |
| spec-only (в spec, без route) | **2** |
| S∩P (spec ↔ generated client) | **347** (1:1) |
| S\P / P\S | **0 / 0** (клиент точно соответствует spec) |
| explicit ops: generated_function == operationId | **39/39** |
| P: операций с query-key (только GET-queries) | **172 / 347** |
| из них generated_query_key == generated_function | **172 / 172** |
| P: мутаций без query-key (→ `-`) | **175** |
| нормализованных коллизий ключей R / S / P | **0 / 0 / 0** |
| canonical_id коллизий (готовых ids: explicit + route-only add) | **0** |
| migration_candidate_id коллизий (bare method-name, deferred M9) | **19** |
| route-only → add (в lockfile) | **22** |
| route-only → pending (вне lockfile, до решения владельца) | **4** |
| route-only → exclude (вне lockfile) | **6** |
| lockfile: in / deferred / out | **61 / 306 / 12** |

**Объяснение разрыва 377 routes / 300 spec paths / 39 operationId:**
- 377 routes → 345 из них документированы в spec (имеют OA), **32 routes без OA в spec не попадают**
  (напр. `DELETE /api/achievements/{uuid}`, `GET /api/auth/reset`, `GET /api/test` — test-endpoint в prod);
  ⇒ 32 routes невидимы для фронтенда.
- 300 spec paths → 347 operations (несколько методов на path).
- 347 operations → лишь **39 с явным operationId**, **306 без operationId вообще**, **2 spec-only**.

### 4.3 Ключевые finding'и: query-key и canonical-коллизии
- **query-key НЕ равен function-name «во всех случаях»** (ранее ошибочное утверждение удалено). Реально из
  P: только **172 из 347** операций имеют query-key-геттер (это все GET-queries); **175 мутаций геттера не
  имеют → `-`**. Для тех 172, что имеют key, выполнено `generated_query_key == generated_function` (172/172);
  для мутаций query-key нет вовсе. ⇒ утверждение «query_key == function_name всегда» **неверно и удалено**
  до доказательства обратным парсингом P — оно ложно уже потому, что 175 операций вообще не имеют ключа.
- **Для 39 explicit** operations: `generated_function == operationId == canonical_id` (orval использует
  operationId и для имени функции, и для query-key). **Выровнено, дрейфа нет.**
- **Для 306 method-fallback** operations: operationId в spec **нет** ⇒ orval генерирует **path-derived**
  имя функции (напр. `deleteApiAchievementsDeleteLevelUuid`); query-key (для GET) == этому сгенерированному
  имени. При этом `canonical_id` = PHP method name (напр. `deleteAchievementLevel`) **≠** `generated_function`.
- **⚠️ 19 migration_candidate_id коллизий** (bare method-name среди 306 method-fallback; план §19):
  `create` (4×), `delete` (2×), `me`, `getAll`, `getByName`, `index` (2×), `yaml`, `deleteFile`,
  `addFilesInRecord`, `removeDriver`, `add`, `addDriver`, `close`. ⇒ **bare method-name как canonical id
  НЕ жизнеспособен** — он не уникален, поэтому вынесен в отдельную колонку `migration_candidate_id` и НЕ
  помечается готовым lockfile-id. Controller-квалификация (`<ControllerShort><Method>`) обязательна.
  Готовых canonical_id-коллизий (39 explicit + 22 route-only add) — **0**.

### 4.4 Правило canonical ID (§19) — обновлено по итогам reconciliation (rev. 3)
**Pre-M9 policy (зафиксирована, см. также план §19):**
1. **39 explicit IDs сохраняются** как есть (`lockfile=in`).
2. **306 legacy operations сохраняют operationId=null** — чтобы НЕ менять P (клиент). В TSV это
   `lockfile=deferred`, `canonical_id='-'`, bare method-name уходит в `migration_candidate_id` (19 коллизий
   ⇒ не готов как lockfile-id). Controller-qualified id для них — **только в согласованном M9**.
3. **Новые route-only add (22)** получают canonical id `<ControllerShort><Method>` с uniqueness-check
   (0 коллизий) → `lockfile=in`.
4. **4 route-only** (public-vs-internal неизвестен) → `pending`, `lockfile=out`; **6 exclude** + **2 stale** → вне lockfile.
   Итого: `in=61` (39 explicit + 22 add), `deferred=306`, `out=12` (4 pending + 6 exclude + 2 stale).

База join (controller::method ↔ path, 377) — `route_inventory.txt` (§1); полный join — TSV (§4). Сопоставление
по нормализованному `METHOD:normpath`. **Lockfile пока НЕ создаётся** (требовалось: сначала таблица — теперь
она есть, rev. 3); создание отложено на M9 с учётом пунктов 1–4 выше.

### 4.5 Spec-only operations (S\R, 2 шт) → stale (не переносятся в lockfile)
- `POST /api/auth/add-access-rules` (operationId `addAccessRules`) — **method drift**: spec/client = POST,
  но route = **PATCH** (`AuthController::addAccessRules`). Канонический sibling (`PATCH .../add-access-rules`)
  классифицирован route-only add (§4.6). POST-запись — stale, opId в lockfile **не переносится**.
- `GET /api/employees/documents/important/{code_1c}` — в spec + клиенте, но **route отсутствует** (orphan).
  opId в lockfile **не переносится**.
Оба — кандидаты на удаление из spec/клиента (решение в M7); в lockfile не попадают.

### 4.6 Route-only operations (R\S, 32 шт) → ручная классификация (22 add / 4 pending / 6 exclude)
Классификация: `docs/metadata_compiler_audit/operation_id_classification.php` (ключ = нормализованный
route-key, `resolution` ∈ {add, pending, exclude, stale}).
- **add (22)** — реальные JSON `#[Route]` endpoint'ы, отсутствующие в spec; войдут в новый OpenAPI с
  конвенцией `<ControllerShort><Method>` (0 коллизий) и в lockfile. Напр.
  `DELETE /api/achievements/{uuid}` → `AchievementDelete`, `GET /api/auth/reset` → `AuthReset`,
  `GET /api/tickets/all` → `TicketsGetAll`.
- **pending (4)** — реальные endpoint'ы, но public-vs-internal неизвестен; `lockfile=out` до решения владельца:
  `GET /api/import/tickets`, `POST /api/exchange-1c/employees`, `POST /api/import/access-rules/bulk`,
  `POST /api/users/duplicate`.
- **exclude (6)** — не публичные JSON API (test/util/HTML/binary), в spec и lockfile **не входят**:
  `GET /api/test`, `GET /test`, `POST /core/update`, `GET /qr`, `GET /api/qr-fast`, `GET /api/image-resize`.

### 4.7 ⚠️ Drift: отдельный `P/openapi.yaml`
- `P/openapi.yaml` (270 КБ, `openapi: 3.0.0`, **143** `operationId`) — **другой файл**, не равный
  `N/.cache/swagger/openapi.yml` (3.1.0, 300 paths / 347 ops / 39 явных opId), который реально читает Orval.
- Reconciliation исходит из **cache-спеки** (§4.1) как источника истины для клиента; `P/openapi.yaml`
  классифицируется отдельно (устаревший коммит / другой продукт → кандидат на удаление/архив в M9).

### 4.8 DtoSchemaBuilder rule-graph parity (M2, dev-only probe)

**Генератор:** `docs/metadata_compiler_audit/gen_dto_rulegraph_parity.php` — dev-only (НЕ тест чистого
checkout'а F; запускается вручную против живого consumer N). Bootstrap: полный deps-сет N (`next/vendor`)
+ **local SpsFW working-tree src** (prepend поверх vendored копии) + `next/src` для `SpsNext\\`. Источник
DTO — `N/.cache/compiled_routes.php` (182 DTO-bindings, 156 unique FQCN).

**Утверждение:** `DtoSchemaBuilder::ruleGraph(build($dto))->rules === Router::extractValidationRules($dto)`
(strict `===`) для каждого уникального DTO из route-cache.

**Результат прогона (snapshot 2026-07, local SpsFW @ Step 2):**

| Метрика | Значение |
|---|---|
| DTO-bindings в `compiled_routes` (с дублями) | **182** |
| Unique DTO FQCN | **156** |
| Loadable | **156** |
| Unloadable | **0** |
| **Расхождений (divergences)** | **0** |

Вывод: `DtoSchemaBuilder::ruleGraph()` воспроизводит `Router::extractValidationRules()` **побайтно на всём
множестве реальных production-DTO** consumer'а (156 классов, все `SpsNext\\*\\Dto\\*`, вкл. nested/nullable/
array+ref/items/promoted-params/defaults). Паритет доказан не только на синтетических фикстурах
(`tests/Compile/Introspection/DtoSchemaBuilderTest.php`), но и на полном инвентаре N — M5 (switch route
cache producer `dtos`←graph) опирается на этот факт. Тесты F от N **не зависят** — числа выше зафиксированы
артефактом аудита, runtime-зависимости нет.

### 4.9 OperationMetadata projection + Step-3 diagnostics (M3)

**Реализовано (F, Step 3 + fix-pass):** `RouteMetadataCompiler::compileOperations()` /
`compileOperationClasses()` / `compileAllOperations()` строят `OperationMetadata[]` из того же
reflection-прохода, что и route-IR: operationId (через `OperationIdResolver` с tri-state lockfile, §4.10),
pathParams (PHP-тип, не legacy OA `string`), queryParams (из `#[QueryParams]`-DTO), requestBody (из
body-маркера + contentType), responses (вывод 200 из return-type либо `#[Response]`), security
(`bearerAuth` / anonymous + `x-required-rules`), tags. Пять новых атрибутов
`src/Core/Attributes/OpenApi/{Operation,Response,Items,Field}.php` (+ `Response::collection`).

**Fix-pass (после ревью operation projection):**
- **operationId lockfile (§4.10):** в `RouteMetadataCompiler` инъектируется tri-state map
  (`array $operationIdMap = []`); пустой map (бывший баг) назначал бы convention всем 306 deferred-операциям.
- **DtoSchemaBuilder split:** schema-проекция (`build()`) строится по **всем** public-сериализуемым свойствам
  (serialName = `#[Field(name)]` ?? PHP-name, **НЕ** OA `property`-arg) и обогащается из `#[Field]/#[Items]`;
  rule-graph (`ruleGraphFor()`/`oaTaggedProperties()`) читает **только** OA (parity). Два множества свойств
  расходятся до M8. Route-IR путь использует `ruleGraphFor()` — schema-диагностики не текут в route-cache.
- **required source-mode:** `PropertyMetadata::isRequired(RequiredSource)` — `Oa` (legacy `required:[true]`,
  parity, **по умолчанию**) vs `PhpType` (non-nullable без default, post-OA). Источники **не смешиваются**.
- **response projection:** missing return-type ⇒ diagnostic (не пустой 200); `mixed` ⇒ diagnostic;
  `void/null/never` ⇒ пустой body; scalar/enum/DateTime/Uuid ⇒ inline-фрагмент `{type, format}` (раньше
  терялись); array ⇒ diagnostic (item-тип); `#[Response(collection: true)]` ⇒ item-форма в `arrayItem`;
  несуществующий schema-class ⇒ diagnostic; `JsonSerializable` без явного контракта ⇒ diagnostic.
- **eligibility (§6):** `DtoEligibility` — `\Dto\` namespace-сегмент OR `*Dto`-суффикс (case-insensitive)
  OR enum / `DateTimeInterface` OR app-whitelist (инъектируемый). Раньше был захардкожен только суффикс.
- **collectDtoBindings reorder:** проверка `ValidateAttr` **до** извлечения типа — обычный untyped/union
  параметр больше не создаёт ложной compile-ошибки.

**Тесты:** `tests/Compile/Route/OperationProjectionTest.php` (проекция + required-mode divergence + 3
response-диагностики + tri-state lockfile), `tests/Compile/OpenApi/OpenApiAttributesTest.php` (контракты
атрибутов вкл. `collection`), `tests/Compile/Introspection/DtoSchemaBuilderTest.php` (Field/Items проекция
+ required source-mode + parity). 25/25 зелёных.

**Doc-диагностики (halt в managed mode, plan §7), теперь по категориям (см. §4.10 — реальные счётчики N):**
1. **path-param mismatch** — `{name}` без name-matching параметра (`field=<param-name>`);
2. **non-eligible return-type без `#[Response]`** — entity/framework/`Http\Response`/`UserAbstract` (`field=return`);
3. **array return без item-типа** — `array`/`iterable` без `#[Response(collection: true)]` (`field=return`);
4. **missing return type** / **mixed return** — opaque, не выводится (`field=return`);
5. **unsupported return** — genuine union/intersection (`field=return`);
6. **JsonSerializable return** — custom JSON-форма, не выводится без `#[Response]` (`field=return`);
7. **`#[Items]` misuse** — не ровно одно из `class|type` (`field=<prop>`).

**Eligibility gate (§6), подтверждён инвентарём N:** `\Dto\` namespace-сегмент OR `*Dto`-суффикс
(case-insensitive) OR enum / `DateTimeInterface` OR app-whitelist. В N — **325** `*Dto`-классов (конвенция
строгая). Non-eligible (opaque `Response`, base-class `UserAbstract`, domain-entity) требуют явного
`#[Response(schema: …)]` — это и есть фронт работ M7 (см. §4.10: 292 `Response` + 5 `UserAbstract`).

**Ключевое расхождение проекций (фиксируется тестом, не баг):** `SecurityMetadata.requiredRules` хранит
`any`/`all` **независимо** (AccessRulesAll-only → `all=[…]` здесь), тогда как runtime-IR
`collectAccessRules` коллапсирует All-only в `[]` и игнорирует class-level — поэтому `SecurityMetadata`
не заменяет `RouteRuntimeMetadata.access_rules` (§3/§6C).

**Quirk для emitter/тестов:** `ReflectionUnionType` нормализует порядок членов (`int|string` → `string|int`);
диагностические сообщения и parity-сравнения не зависят от порядка.

### 4.10 Route-IR + operation-projection parity на реальном N (M3, dev-only probe)

**Генератор:** `docs/metadata_compiler_audit/gen_n_route_and_operation_parity.php` — dev-only (НЕ тест
чистого checkout'а F; вручную против живого N). Тот же bootstrap, что §4.8 (`next/vendor` + local SpsFW
working-tree src prepend + `next/src`), `SPSFW_PROJECT_ROOT=next`, discovery-dirs = `PathManager::getControllersDirs()`
(= `[framework src/Core, next/src]` — ровно те, что сканировал N Router). Compile-слой грузится из local
working-tree (в vendored копии `Compile/` отсутствует).

**A. Route-IR parity** — `RouteCacheEmitter::emit(RouteMetadataCompiler::compile($dirs))` **строго `===`**
`N/.cache/compiled_routes.php` (побайтно, вкл. middlewares/access-asymmetry/dtos-ruleGraph/params/pattern/
php_ini, last-wins дублей). `DtoSchemaBuilder::ruleGraphFor()` (новый, OA-only) рулит `dtos[].rules`.

| Метрика | Значение |
|---|---|
| Golden keys (`compiled_routes.php`) | **377** |
| Emitted keys (local compiler+emitter) | **377** |
| Missing (golden only) | **0** |
| Extra (emitted only) | **0** |
| **Per-key divergences (`!==`)** | **0** |
| Route-IR diagnostics (дубли METHOD:path / untyped DTO-param / missing DTO) | **14** (структурные finding'и N, не parity-fail) |

Вывод: **route-IR и rule-graph воспроизводятся побайтно на полном инвентаре N (377 routes + 156 DTO из §4.8).**
M5 (switch `dtos`←graph) и emitter опираются на этот факт.

**B. Operation projection** — `compileAllOperations($dirs)` с tri-state lockfile из reconciliation TSV
(`in`=canonical_id / `deferred`=null / `out`=absent→convention).

| Метрика | Значение |
|---|---|
| Lockfile signatures (`in`+`deferred`) | 367 (61 `in` + 306 `deferred`; 12 `out` ⇒ convention) |
| Operations compiled | **384** |
| operationId non-null (preserved + convention) | **78** (61 preserved + 17 convention) |
| **operationId null (deferred, id-less)** | **306** (точно совпадает с `deferred` TSV) |
| Diagnostics total | **374** |

**Диагностики по категориям (cause buckets) — это фронт миграции M7, не баги компилятора:**

| Категория | Кол-во | Что значит |
|---|---|---|
| `non-eligible-entity` | **297** (292 `Response` + 5 `UserAbstract`) | opaque/framework/base return без `#[Response]` — нужен `#[Response(schema: …)]` |
| `itemless-array` | 39 | `array` return без item-типа — нужен `#[Response(collection: true, schema: …)]` |
| `path-param-mismatch` | 28 | `{name}` без name-matching параметра (field = `code_1c` ×18, `uuid` ×3, `ticket_uuid` ×3, `role_id` ×2, `user_uuid` ×2) |
| `missing-return-type` | 5 | метод без return-type — нужен тип или `#[Response]` |
| `unsupported-return-type` | 3 | genuine union/intersection return |
| `jsonserializable-response` | 2 | DTO с custom `jsonSerialize()` без `#[Response]` |

**Вывод B:** operation-projection корректна — 306 deferred-операций остаются id-less (точное совпадение с
lockfile), preserved/convention-распределение честное. Все 374 диагностики — реальный фронт M7: N-контроллеры
ещё не декларируют `#[Response]`/return-типы (292 opaque `Response`-возврата — основная масса). Ни одна
`*Dto` не классифицирована ошибочно как non-eligible (подтверждено сэмплом: 292 `Response` + 5 `UserAbstract`,
0 misclassified DTO). `composer test` зелёный (25/25); чистый checkout F от N не зависит.

### 4.11 Secondary OpenAPI emitter + normalized parity на реальном N (Step 4 / M3, dev-only probe)

> **Rev. 3 (Step 5 doc-fix).** Два методологических уточнения измерения baseline (двигают числа, но не
> структурное состояние N): (а) **parity сравнивается после round-trip `dump → parse`**, а не напрямую с
> in-memory массивом — т.е. ровно с тем, что было бы опубликовано (значения, выживающие только в памяти,
> теперь видны); (б) **publication gate агрегирует opDiag + emitDiag + validatorDiag** (раньше probe проверял
> только последние два). Повторные прогоны детерминированы. Прежние числа (rev. 2: 4986 divergences) **не
> сохранены искусственно** — см. ниже.
>
> **Rev. 2 (Step 4 correctness fix-pass).** Пересчитано после focused-фикспасса: убран повторный `emit()` из
> публикации (array-first `dump()`/`writeFile(array)`), введён `SchemaNameResolver` (FQCN⇒name registry
> внутренняя — `x-fqcn` больше НЕ публикуется), nullability канонизируется в 3.1-union (а не удаляется),
> `security: []` сохраняется, добавлен структурный `OpenApiValidator`, 403 — по эффективному runtime-access.
> Прежние числа (30 fatal / 4737 divergences) **не сохранены искусственно** — см. ниже.

**Генератор:** `docs/metadata_compiler_audit/gen_n_openapi_parity.php` — dev-only (НЕ тест чистого checkout'а
F; вручную против живого N). Тот же bootstrap, что §4.8/§4.10. Эмиттит **вторичный** артефакт
`next/.cache/swagger/openapi.generated.yml`; основной `openapi.yml` (swagger-php) НЕ трогается (plan §15, M6).

**Решения Step 4 (фиксированы, plan Шаг 4):** прямая зависимость `symfony/yaml ^7.0` (materialized в
`composer.lock`); emitter **array-first** — `emit()` строит детерминированный PHP-массив и копит диагностики
**один раз** (дедуплицированы по signature); `dump(array)` / `writeFile(array)` сериализуют УЖЕ построенный
массив без перекомпиляции (повторного накопления диагностик нет). Parity — после round-trip `Yaml::parseFile` +
normalization: рекурсивный ksort, strip `x-fqcn`, **CANONICALIZE `nullable`⇒3.1-union на обеих сторонах**
(скаляр ⇒ `type:[…,"null"]`, `$ref` ⇒ `anyOf:[{$ref},{type:"null"}]` — маркер НЕ удаляется, иначе скрылась бы
реальная контрактная разница), drop пустых контейнеров **кроме** `security: []` (анонимность = контракт) и
любого пустого контейнера внутри security-поддерева (`bearerAuth: []` scopes); сравнение массив-к-массиву,
НИКОГДА по сырой текст/whitespace.

**C. Secondary emission (PARITY-режим):** `OpenApiEmitter::emit(387 ops)` → array; затем структурная
валидация массива `OpenApiValidator::validate($doc)` (refs resolve / каждая op имеет responses / валидные
HTTP-methods / security-schemes существуют — YAML round-trip сам по себе недостаточен). Эмиттер и валидатор
пишут диагностики, но НЕ бросают; публикация gated `throwOnErrors()`-семантикой вызывающего.

| Метрика | Значение |
|---|---|
| Raw operations (из §4.10) | 387 |
| Effective paths emitted | **333** (METHOD:path-операции, collapse last-wins) |
| Component schemas emitted | **151** |
| Emission — fatal (ERROR) | **15**: 14 duplicate METHOD:path (last-wins, зеркалит Router) + **1 schema-name collision** |
| Emission — warning | 1 |
| Structural validation findings (`OpenApiValidator`) | **0** (все `$ref` резолвятся, каждая op имеет responses) |
| Operation-projection migration gaps (WARNING) | **393** |
| Operation-projection structural errors | **0** |
| **Secondary-файл опубликован?** | **НЕТ** — blocked 15 структурными error(s) (in-memory preview только для отчёта) |

**Mode behavior (требование заказчика):** **parity**-режим допускает ТОЛЬКО WARNING — 391 migration-gaps НЕ
блокируют построение массива/parity-report. Но структурные ERROR блокируют **публикацию** secondary-файла
(`throwOnErrors()` перед записью); при 15 error(s) файл НЕ пишется, разрешён лишь in-memory preview для отчёта.
В **strict/managed** `throwOnErrorsAndWarnings()` HALT-ит на 391 warning(s) + 15 error(s). Реализовано
разделением каналов severity в `CompileDiagnostics` (ERROR=структурные → `throwOnErrors()` во ВСЕХ режимах;
WARNING=migration-gap → только strict/managed).

**Почему fatal-счётчик уменьшился вдвое (30 → 15):** прежние 28 duplicate + 2 collision были **задвоены** —
probe звал `$emitter->emit($ops)` и затем `$emitter->toFile($ops,…)`, а `toFile()` ре-эмиттил (до появления
дедупликации каждая диагностика записывалась дважды). Фикспасс: (а) `CompileDiagnostics` дедуплицирует по
signature; (б) probe эмиттит ОДИН раз и сериализует через `writeFile(array)`. Реальное структурное состояние N
не изменилось — **14** shadowed METHOD:path + **1** `CreateNewsDto` collision; считали их неверно.

**Structural findings N (fatal, не parity-fail):** (1) **14 duplicate METHOD:path** — N реально имеет
shadowed-роуты (Router молча перетирал); emitter делает их видимыми, last-wins. (2) **schema-name collision
`CreateNewsDto`**: два FQCN (`SpsNext\News\Dto\CreateNewsDto` и `SpsNext\LK\News\DTOs\CreateNewsDto`)
коллапсируют в одно short name → неоднозначный `$ref` (`SchemaNameResolver` детектит в одном месте; первый
регистрант владеет слотом). Фикс — class-level `#[Field(schema: …)]` rename или namespace cleanup (M7).
**Bug найден и исправлен на N (Phase 1):** swagger-php `Generator::UNDEFINED` sentinel протекал через
OA items-fallback → `ReflectionException`; закрыт `DtoSchemaBuilder::isOaDefault()` + regression-тест.

**D. Normalized parity vs legacy swagger-php `openapi.yml`** (generated прогнан через round-trip
`Yaml::parse($emitter->dump($doc))` — сравнивается ровно то, что было бы опубликовано; in-memory preview —
только для отчёта, в сравнение не идёт):

| Метрика | Generated | Legacy |
|---|---|---|
| Paths | 333 | 300 |
| Schemas | 151 | 338 |
| Operations | 380 | 347 |
| Paths only-in-generated / only-in-legacy | 35 / 2 | |
| Schemas only-in-generated / only-in-legacy | 6 / **193** | |
| **Total normalized divergences** | **5002** | |

По категориям: **2568** missing-in-generated, **956** value-mismatch, **1478** extra-in-generated.

**Baseline M3 = 5002 divergences / 15 structural fatals / secondary-публикация blocked** (цель
zero-divergence — M7/M8/M9; 15 ERROR устраняются перед Step 6b). Дрейф относительно rev. 2 (4986) — **чисто
методологический**, без изменения структурного состояния N: (а) round-trip `dump → parse` теперь честно ловит
значения, выживающие только в памяти; (б) N — живое рабочее дерево, в котором за это время прибавились
операции (385 → 387 ops, 331 → 333 paths, 149 → 151 schemas). Повторные прогоны детерминированы.
Доминирующие root-causes (сэмпл + анализ):

| Root-cause | Доля / пример | Когда закрывается |
|---|---|---|
| **Schema coverage gap** (149 vs 338; 193 only-in-legacy) | доминирует. (a) операции с провалившейся response-projection (391 gap) не дотягиваются до своих DTO-refs → схемы не собраны; (b) swagger-php сканирует standalone `#[OA\Schema]`/nested DTO вне досягаемости эмитнутых роутов. Emitter собирает только transitively-reachable схемы | M7 (`#[Response]`) + M8 (DTO cleanup) |
| **Nullability contract** (новое, видно после canonicalization) | `type:["array","null"]` (gen) vs `type:"array"` (legacy) — emitter выводит nullable из PHP-типа (`?Type`), swagger-php из явного OA `nullable:`; разница больше не маскируется | M7/M8 |
| **Serialization-name** (PHP name vs OA `property` arg) | `userCode1C` (gen) vs `user_code_1c` (legacy) — serialization contract plan §6: JSON-ключ = PHP property name, НЕ OA `property:` (если нет `JsonSerializable`-ремапа). swagger-php ошибается; projection — по контракту | M8 (per-DTO verify JsonSerializable) |
| **$ref target** divergence | `rules` → `AccessRulesDto` (gen) vs `AccessRuleDto` (legacy) — projection выводит item-тип из `#[Items]`/reflection, swagger-php из явного OA items | M7 |
| **Missing description/title** | swagger-php эмиттит `OA\Schema` title/description (рус. подписи) — projection их пока не имеет (нет OA\Schema-источника) | M7 (`#[Field]`/описания) |
| **operationId delta** | 306 deferred id-less ops → missing-in-generated operationId | M9 (canonical-id assignment) |
| **Duplicate-key / collision** | 14 + 1 (см. structural findings выше) | M7 (namespace / class-level `#[Field(schema:)]`) |

**E. Prereq 4 — non-promoted constructor fields на реальном N:** **0** DTO с non-promoted ctor-param,
тегированным `#[OA\Property]` (FQCN⇒name теперь читается из внутреннего `componentRegistry()` эмиттера, а не из
`x-fqcn`). N-ные DTO используют promoted ctor-params (или не тегируют non-promoted) → специальная ветка
`Router::extractValidationRules` (Router.php:421–481) — **фактически мёртвый код на N**. Schema-projection
корректно их исключает (они не `json_serialize`'ются); расхождений этого класса нет.

**Вывод §4.11:** secondary emitter работает на полном инвентаре N (387 ops → 333 paths / 151 schemas);
array-first контракт (`emit()` один раз → `dump()`/`writeFile()` + структурная `OpenApiValidator`) даёт
идемпотентные/дедуплицированные диагностики и gate публикации (`throwOnErrors()`-семантика: 15 структурных
error блокируют запись secondary-файла, in-memory preview разрешён). `x-fqcn` не публикуется (registry
внутренняя), nullability — 3.1-union, `security: []` сохранена, 403 — по эффективному runtime-access.
**baseline M3 = 5002 normalized divergences** (после round-trip `dump → parse`), **15 структурных fatals**,
secondary-публикация **blocked** — актуальный фронт миграции M7–M9, разложенный по root-causes. Режимная
семантика (parity tolerate warnings / strict-managed halt / structural-error blocks publish) подтверждена на
реальных числах. `composer test` зелёный (27/27); чистый checkout F от N не зависит (probe — dev-only
артефакт, числа зафиксированы в §4.11).

### 4.12 Coordinator dry-run на реальном N (Step 5 / M4, dev-only probe)

**Probe:** `docs/metadata_compiler_audit/gen_n_coordinator_dryrun.php`. Драйвит production-`Coordinator`
end-to-end на реальном дереве контроллеров N в **read-only / dry-run** (managed + parity), с DI-привязками,
засеянными из `next/config/di_config.php` (как `preload.php:67-68`, минус DB-сторону `Config::init()` —
`getDIBinding()` читает статический map напрямую). Классовый classmap N оптимизирован и указывает на vendored
копию, поэтому probe переопределяет classmap для каждого локального `src`-класса (`addClassMap`), иначе stale
vendored `DICacheBuilder` выигрывал бы у рабочей ветки.

**Контракт probe — подтверждён (PASS):** probe передаёт Coordinator'у **реальную tri-state operationId-карту**
(367 записей: 61 preserved id + 306 deferred-null), распарсенную из `operation_id_reconciliation.tsv` (rev.3), и
**явную `routeOverrideMap`** для 6 Core↔Next auth-перекрытий (winner = Next). Результат: `published=false`,
`reason='dry-run'`, текущие N `.cache`-артефакты (`compiled_routes.php`, `compiled_di.php`, `job_registry.php`,
`swagger/openapi.yml`, secondary `openapi.generated.yml`) **не изменены** (md5 до === после). Публикация
заблокирована оставшимися структурными ERROR'ами; ни один ERROR не понижен до warning ради прохождения.

**Реклассификация (исправление ревью #10): «37 сырых записей» — это НЕ 37 дефектов.** Coordinator агрегирует
диагностики route-compiler + OpenAPI-emitter + operationId-resolver, и каждая сталкивающаяся операция даёт свою
запись — поэтому raw-счётчик завышает число реальных проблем. Более того, ранний «unmapped» замер (пустая
operationId-карта, без перекрытий) раздувал счётчик артефактами convention-operationId. С реальной tri-state картой
+ объявленными перекрытиями raw-поверхность схлопывается до **3 ERROR-записей**, и они делятся на 4 чётких категории:

**1. Явные intentional overrides (6) — НЕ дефекты.** 6 Core↔Next auth-маршрутов (`login`, `register`, `logout`,
`refresh-tokens`, `add-access-rules`, `set-access-rules`): Next намеренно замещает framework-шаблоны Core. С
`routeOverrideMap` Coordinator распознаёт их как перекрытия (winner = Next, Core shadowed), НЕ как дубли —
ERROR-записей по ним нет, а shadowed-операции не попадают ни в OpenAPI, ни в operationId-проверку. Winner выбран
по карте, независимо от порядка discovery. (Без карты те же 6 выглядели бы «duplicate route key» — отсюда
артефактный счётчик в unmapped-замере.)

**2. Реальный route-дефект (1) — починить в N.** `GET:/api/employees/documents/code-1c/{code_1c}` — внутри ОДНОГО
контроллера два метода (`EmployeeDocumentsController::getDocumentsByUserCode1C` ↔ `::getImportantDocumentsByUserCode1C`)
регистрируют один path. Это подлинный дефект N (не перекрытие): остаётся ERROR (2 записи), должен быть устранён
в Step 6b (развести path'ы или слить в один метод).

**3. Compiler-induced findings (4 operationId-коллизии) — НЕ N-дефекты, исчезают с реальной картой.** В unmapped-замере
Core и Next `AuthController` давали одинаковый convention-operationId (`AuthLogin`, `AuthLogout`, `AuthRegister`,
`AuthRefreshTokens`). С реальной tri-state картой Next-методы получают preserved-ids (`loginUser`, `logoutUser`, …),
а shadowed Core-операции вообще не участвуют в проверке уникальности → **operationId-коллизий: 0** (directive #4,
доказано: preserved-id uniqueness + shadowing). Это были артефакты пустой карты, а не баги N — поэтому
preemptively называть их «N-bugs» было ошибкой.

**4. Schema-name collision (1) — починить в N.** `components.schemas.CreateNewsDto` ← `SpsNext\LK\News\DTOs\CreateNewsDto`
и `SpsNext\News\Dto\CreateNewsDto` (два DTO-класса маппятся в одно component-имя по short name). Реальный дефект N,
остаётся ERROR.

**Сравнение замеров:** unmapped baseline — 15 ERROR-записей (7 distinct dup-route-keys × 2 + 1 CreateNewsDto);
с реальной картой + перекрытиями — **3 ERROR-записи** (EmployeeDocuments ×2 + CreateNewsDto ×1), 6 применённых
перекрытий, 0 operationId-коллизий. Блокировка публикации — та же и подтверждена сильнее (на корректных входах).

**Вывод §4.12:** Coordinator на реальном N ведёт себя точно по Step-5-контракту — единый flow, агрегированные
диагностики, ERROR блокирует публикацию во ВСЕХ режимах, dry-run не трогает prod-кеш, а реальные tri-state входы
(карта + перекрытия) корректно разделяют intentional-перекрытия/артефакты от подлинных дефектов. **Worklist
Step 6b — только реальные дефекты N:** (а) развести intra-controller path-коллизию `EmployeeDocumentsController`;
(б) разрешить `CreateNewsDto` schema-name collision (переименовать один из DTO / добавить явное schema-имя).
6 Core↔Next auth-перекрытий — это **конфигурация** (`routeOverrideMap`), а не дефект. Шаг 6 не начат.

### 4.13 OpenAPI-source parity + escape hatch (Step 8 / M6, dev-only probe) — engine capability complete; N activation pending M7/M8

**Capability (F, Step 8 / M6):** the PRIMARY `.cache/swagger/openapi.yml` producer is now switchable behind a new
4th independent `ApplicationContext` axis — `OpenApiSource` (enum, mirrors `RuleSource`):
- `Legacy` (default) — byte-compat swagger-php via `DocsUtil::produceLegacyOpenApiYaml()`; PURE rollback for every
  client; the full swagger-php scan runs ONLY in this branch (skipped under `Metadata`).
- `Metadata` (opt-in) — the graph document (built ONCE by `OpenApiEmitter::emit()`) merged with the narrow OA
  escape hatch, then re-validated, then dumped; the SECONDARY `openapi.generated.yml` stays the pure graph under
  BOTH modes.

`OpenApiSource` reaches the fingerprint via `recordedConfig()` → `config` (single source; NOT passed twice); the
escape hatch has ONE canonical representation in `Fingerprinter` (`class:<FQCN>` / `file:<project-relative-path>`
keys + content md5; no absolute deploy paths; a file outside projectRoot is the `out-of-root` sentinel — already
FATALed by the merger upstream); the manifest carries ONLY the `escape_hatch_hash` md5. `COMPILER_VERSION` bumped
`spsfw-compile-3` → `spsfw-compile-4` (the emitted PRIMARY set changed shape, so the fingerprint invalidates).

**Rollback:** flip `SPSFW_OPENAPI_SOURCE` back to `legacy` (or unset). The axis is recorded in fingerprint +
manifest, so the flip deterministically invalidates the cache; default `Legacy` is a pure no-op vs today.

**Escape-hatch contract (exact):** `OpenApiEscapeHatch` VO (scan targets + requested schema keys; validates +
dedupes + sorts). Config shape (`next/config/openapi_escape_hatch.php`, N-owned, empty default):
`['scan' => [...class-strings/relative-paths...], 'schemas' => [...requested component keys...]]`. TWO forbidden-
content guards: (a) an annotation-level allowlist guard on the swagger-php `Analysis` (only
`Schema/Property/Items/Discriminator/AdditionalProperties/ExternalDocumentation/Xml` + root `OpenApi`; ANY
operation/path/parameter/request-body/response/security annotation → FATAL) — catches `#[OA\Get]` even though the
schema-preserving pipeline drops `BuildPaths`; (b) an exact-shape guard (only `openapi/info/components.schemas`;
`paths` empty; any other root section or `components` key → FATAL). Provenance: resolved files deduped + sorted
(same file via FQCN and path scans once), scanned separately, duplicate fragment key across targets → FATAL,
requested key missing → FATAL, graph↔fragment collision → FATAL (graph wins). External `$ref` policy: any `$ref`
not starting with `#/` → FATAL (the validator would otherwise accept external refs).

**F test results (composer test, 46 files, all green):** characterization-FIRST
(`EscapeHatchPipelineCharacterizationTest` — polymorphic schema survives + forbidden `#[OA\Get]` detected without
`BuildPaths`); `OpenApiEscapeHatchMergerTest` (11 cases — empty no-op, whitelist extraction, missing/collision/
forbidden/dup-key FATALs, same-file dedup, refs resolve, external-ref FATAL, determinism, no x-fqcn/nullable);
`OpenApiSourceTest`; `OpenApiPrimarySourceTest` (Coordinator-level — Legacy byte-identical to `DocsUtil`, Metadata
== `dump(merge(emit()))`, secondary pure graph both modes, ERROR→not published, manifest `openapi_source` +
`escape_hatch_hash`, fingerprint differs on flip); extended `FingerprinterTest` (openapi_source via config, escape-
hatch content-change invalidation, manifest md5-only no-path-leak) + `OpenApiEmitterTest` (emit-once array-first
contract: merge/validate on a built doc add no diagnostics). Emit-once is pinned by the array-first contract +
single-`emit()`-call Coordinator code review, NOT by a diagnostic count (CompileDiagnostics dedups).
`composer validate`: only the PRE-EXISTING `firebase/php-jwt` lock exit-2 (origin/master); NO new composer problems.

**N Coordinator probe (`docs/metadata_compiler_audit/gen_n_openapi_source_parity.php`, both runs PASS):** drives the
FULL Coordinator TWICE over N's real discovery tree — A=`OpenApiSource::Legacy`, B=`OpenApiSource::Metadata` —
identical inputs (ruleSource=Metadata UNCHANGED, empty escape hatch, POLICY_PARITY, real operationIdMap/overrides/
configFiles). Results:
- `errors: A=0 B=0` (MANDATORY — both clean); `warnings: A=394 B=394` (IDENTICAL between modes → confirms the
  switch is observably isolated to the PRIMARY openapi.yml; these are pre-existing migration-gap warnings, parity-
  tolerated, reported separately);
- `compiled_routes.php` / `compiled_di.php` / `job_registry.php` byte-identical A vs B (openapi-source-independent);
- SECONDARY `openapi.generated.yml` byte-identical A vs B (pure graph never depends on the primary producer);
- A PRIMARY byte-identical to `DocsUtil::produceLegacyOpenApiYaml(scanPaths)` (sanity — Legacy reproduces swagger-php);
- B PRIMARY == B SECONDARY (empty hatch ⇒ Metadata primary IS the pure graph) and PASSES `OpenApiValidator`;
- fingerprints differ (A `f89488bbebf2` vs B `5fabd01eadab`); manifests record `openapi_source=legacy|metadata`;
  real `next/.cache` byte-unchanged before/after; no P/public_next files referenced.

**New normalized parity baseline (M7–M9 backlog — NOT auto-failure, structurally valid):** the legacy swagger-php
projection and the graph emitter are DIFFERENT projections of the same app. Counts (PRIMARY openapi.yml):
- A (legacy swagger-php): 301 paths / 348 operations / 341 schemas;
- B (graph): 333 paths / 380 operations / 151 schemas.
Divergence drivers (known): (i) swagger-php scans ALL `#[OA\Schema]` in the framework tree (incl. unreferenced) →
more schemas; the graph emits only schemas referenced by operations; (ii) the graph emits operations from `Route`
attributes that lack `#[OA\Get]` (swagger-php skips those) → more paths/operations; (iii) response/error-shape,
security, and standard-error policy differences. These close as Step 9 (clean controllers of OA) + Step 10 (clean
DTOs) + rule-graph/standard-error parity land — at which point the N production flip to `metadata` becomes safe.

**Why N stays `Legacy` primary default in M6:** flipping to `Metadata` changes the PRIMARY spec Orval reads
(301 vs 333 paths etc.); the divergence backlog is unresolved, so a flip would change the generated client without
a coordinated regen. M6 ships the ENGINE CAPABILITY + the narrow escape hatch + a proven, isolated, reversible
switch; the N production activation is deferred to M7/M8 (after Step 9 + the parity backlog closes).

**Live FPM smoke deferred:** the probe is a CLI throwaway-cache run, NOT a live PHP-FPM preload. Live FPM smoke
(`SPSFW_OPENAPI_SOURCE=metadata` through the real preload → Orval regen → tsc) is a pre-merge/pre-deploy gate,
deferred until the divergence backlog closes.

**Integration caveat:** N's `feature/metadata-compiler-step6b` is BEHIND `origin/main` — it needs an `origin/main`
merge + this probe re-run before final integration (the worktree `.wt/lk-step6b` was used for the N-side changes).

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
| OpenAPI spec (Orval input) | `N/.cache/swagger/openapi.yml` | 3.1.0, **300 paths / 347 ops**, 39 явных opId | §4.1 (ранее ошибочно «172 paths» — grep-артефакт) |
| Устаревший spec (drift) | `P/openapi.yaml` | 3.0.0, 143 opId | §4.7 — отдельный файл |
| Сгенерированный клиент | `P/src/lk-openapi/react-query` | 347 endpoint-функций (1:1 со spec) | §4.2/4.3, react-query, `useOperationIdAsQueryKey:true` |
| Клиентский preload | `N/preload.php` | — | Приложение B плана (последовательность 11 шагов) |
| Контроллеры (F discovery) | `*Controller.php` в `getControllersDirs()` | 70 (N) | §2.1 плана |
| Characterization-тесты (F) | `tests/Compile/*Test.php` | 5 | §2 этого аудита |

Все N/P-данные получены **read-only**; production-код F и N в Шаге 0 **не менялся**.
