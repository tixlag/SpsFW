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

---

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
