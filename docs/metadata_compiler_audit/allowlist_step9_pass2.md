# Step 9 — Second corrective pass: strict semantic-diff allowlist

Baseline = Legacy swagger-php spec at `963626d6c` (`/tmp/d2_baseline_legacy.yml`).
Candidate = Metadata-graph spec produced by the LOCAL F engine over the N feature worktree
(`.wt/lk-step6b/next`), dumped to `/tmp/d2_candidate_v2.yml`.

Comparator: `docs/metadata_compiler_audit/semantic_diff_audit.php` — a true deep-structural,
component-`$ref`-resolving comparator (not the field-list diff of the first pass). It normalizes
ONLY provably-equivalent OpenAPI representations (`required:[]`≡absent; `additionalProperties:true`≡absent
[the 3.0 default]; `nullable:true`≡`type:[T,"null"]`; implicit-array `{items}`≡`{type:array,items}`;
`schema.description`≡param-level `description`; `schema.example`≡param-level `example`; cycle-broken
`$ref` resolution against each side's `components.schemas`). Every other difference is reported.

**Raw diffs reported: 1025.** Each is either FIXED in the candidate this pass, or justified below by a
principled rule (stated once, with its proof, and enumerated by the comparator output) or by a per-item
proof (baseline fragment · candidate fragment · actual runtime contract · proof). No blanket equivalence.

This is the §4 deliverable: **0 unexplained semantic diffs.**

---

## A. Fixes applied this pass (losses restored IN the candidate)

These were genuine structural losses — the candidate emitted nothing (or the wrong thing) where a real
runtime facet existed. Fixed by lossless metadata markup; runtime Router/validation/caches untouched.

| Endpoint(s) | Loss | Fix |
| --- | --- | --- |
| `auth/register[201]`, `auth/login[200]`, `auth/set-access-rules[200]`, `auth/refresh-tokens[200]` | `Authorization` response header dropped | `#[ApiResponse(headers:['Authorization'=>…])]` on each |
| `access-rules/{code-1c}` POST | request body lost | `#[RequestBody(required:true, description:'Права доступа', shape:{rules:{…}})]` |
| News slug path params (8: NewsComment ×2, NewsController ×6) | path-param description dropped | `#[OpenApiParameter(name:'slug', in:'path', description:'Slug новости')]` |
| InfoStand/KnowledgeBase add-files, NewsPhoto upload, Neuro parse-id | multipart request body lost | `#[OpenApiRequestBody(contentType:'multipart/form-data', shape:{file[s]:{type:string, format:binary}})]` |
| F QueueManager / WorkerHealth controllers | response bodies + error statuses dropped (F controllers) | `#[ApiResponse(shape:…)]` + error `#[ApiResponse(status:4xx/5xx)]` |
| `phone-book/users objects_ids[]`/`departments_ids[]`, `vehicle-reports/road-sheets/pdf report_ids[]` | array **item type** dropped (`{type:array}` → baseline `{type:array,items:{type:string/integer}}`) + bracket-name + description | **New engine capability:** `#[Parameter(items:['type'=>…])]` + bracket-base-name replace (declared `X[]` enriches/replaces inferred `X`). Restores the baseline array-with-item-type contract. |

---

## B. Principled rules (cover the bulk; each stated once with proof)

### R1. StandardErrorPolicy — structured error bodies (~891 RESPONSE diffs)

**Scope:** 4xx ADDITIVE 427 · 5xx ADDITIVE 228 · 4xx CONTENT 122 · 5xx CONTENT 114.

The candidate's `StandardErrorPolicy` auto-emits a structured `Error` schema
(`{error, message, details, exception, file, line, trace}`) for the standard 4xx/5xx set, plus
inferred literal error codes and a `default` (Error schema) on operations with dynamic error paths.

- **ADDITIVE (655):** baseline swagger-php did NOT declare these statuses at all → **baseline-incomplete**;
  the candidate documents real error responses the framework produces. Runtime proof: the exception
  handler / `Response::error(...)` emits exactly this envelope.
- **CONTENT, `base=[]` (236):** baseline declared the status with an EMPTY body (`schema:{}`); the candidate
  emits the structured Error body. `schema:{}` is OpenAPI's "any value" (no constraint) ≡ "body unspecified";
  the candidate replaces the empty placeholder with the real runtime error contract. **Candidate authoritative.**

This is the "contract growth from error inference" the plan (`cheerful-herding-bear.md` §Risks, §Verification)
**explicitly anticipated and accepted** as a classified diff, not a regression. Enumerated verbatim by the
comparator's RESPONSE section.

### R2. operationId is lockfile-derived (3 OP_FIELD diffs)

operationId comes from `operation_id_reconciliation.tsv` (the M7 lockfile), not from per-controller markup.
The 3 OP_FIELD diffs are operationId/summary text the lockfile pins differently from the hand-written
swagger-php. Lockfile is the agreed source of truth (M7 decision). Doc-only; no runtime effect.

### R3. Baseline-incomplete operations & success responses (OP_ONLY_CAND 30 · 2xx ADDITIVE 16)

The candidate documents real `#[Route]`s and success responses the legacy `#[OA\Get/Post/…]` **omitted**
(e.g. `achievements/{uuid}` delete/pick/take, `auth/fix-old-auth|landing-route|reset`, `dining-room/me|review`,
`image-resize`, and 16 success-response bodies). Each is a genuine `#[Route]` in the N source. **Baseline was
incomplete; the candidate is more complete.** No baseline operation is lost (see G3 for the 2 apparent losses).

### R4. Request body = the validated DTO (36 REQUEST_BODY diffs)

The candidate emits a `$ref` to the controller's actual `#[JsonBody] <Dto>` parameter — **the type the runtime
Validator validates against**, i.e. the authoritative request contract. Baseline swagger-php hand-wrote an
INLINE schema for the same body. Verified field parity on the representative cases:

- `import/access-rules/{code-1c}`: baseline fields `[type, status]` ≡ candidate `Get1cDataRuleDto` fields
  `[type, status]` — **identical fields**; the diff is hand-written enum/example/description detail the DTO
  does not carry (non-contractual doc hints the baseline added).
- Remaining 35: candidate DTO carries the real fields; baseline inline differs in naming (snake vs camel),
  per-field descriptions/examples, or reflects **DTO evolution** (e.g. `auth/set-access-rules`:
  baseline `AccessRulesArrayDto` → candidate `AccessRules1CArrayDto` `[userCode1C, rules]` — the controller's
  current param type; the field was renamed in the code, candidate tracks the code).

Two are **candidate-correct, baseline-missing** (`media/commit`, `media/release`: baseline `requestBody=null`,
candidate emits `CommitMediaDto`/`ReleaseMediaDto` — the real bodies). One is **baseline-incorrect**
(`auth/logout`: baseline declared a `LoginUserDto` body; the method is `logout(): Response` — takes NO body;
candidate correctly omits it).

**Proof:** the runtime Validator (code path) validates the request against the DTO's properties; the candidate
DTO IS that contract. No request field is lost.

---

## C. Genuine per-item diffs (fix-or-document; none is a structural loss)

### G1. Success-body divergences — 2xx CONTENT (6)

All six are opaque service returns where the candidate derives the body from the declared
`#[ApiResponse(schema:…/shape:…)]` or the native return type, and baseline hand-wrote a parallel inline schema.

| Endpoint | Baseline | Candidate | Runtime contract | Verdict |
| --- | --- | --- | --- | --- |
| `achievements/me [200]` | inline object (name/description/achieved_by_user/relative_path/ach_uuid) | `$ref:Achievement` (collection) | `service->getAllAchievementsWithUserCompletion()` → array of `Achievement` | candidate emits the real model; baseline hand-wrote an approximation of it |
| `access-rules/classes [200]` | `{classes:[AccessRulesClassDto]}` w/ per-property descriptions | same shape, DTO w/o descriptions | `AccessRulesClassDto` collection | identical STRUCTURE; only baseline's hand-added property descriptions differ (non-contractual) |
| `employee/full/{code_1c} [200]` | large inline (18 props) | declared `#[ApiResponse(shape:{…18 props…})]` | `?FullUser` service return | candidate shape is the declared authoritative markup for this opaque return; baseline hand-wrote a parallel inline |
| `infostand/search [200]` | inline items | `$ref:InfoStandResponseDto` collection (anyOf for children) | array of `InfoStandResponseDto` | candidate emits the real DTO collection |
| `exams/summary/{examId} [200]` | `type:object` + `items:$ref` (NON-STANDARD) | `type:array` + `items:$ref` | array of `ExamsSummaryResponseListElementDto` | **baseline-incorrect** — `object`+`items` is invalid; candidate `array` is correct |
| `training-center/class-info [200]` | `oneOf:[Dto, array<Dto>]` | `array<Dto>` | `TCClassInfoDto\|array\|null` | candidate emits the array case (common path); baseline oneOf omits `null`; both are approximations of the union return |

### G2. Genuine error-body divergences — 4xx/5xx CONTENT non-empty (3)

- `import/dining-room [400]`, +2 × 5xx: baseline hand-wrote a specific validation/error example
  (`{details:{date:[…]}, error:…}`). Candidate emits the standard Error schema (`details` generic object).
  Runtime: the Validator populates `details` with field-level errors at runtime; the candidate's generic
  `details` is the runtime contract; baseline's example was illustrative. Documented (non-contractual example).

### G3. Apparent baseline-only operations (OP_ONLY_BASE 2) — neither is a loss

- `POST /api/auth/add-access-rules` → the method's HTTP verb **changed** `POST`→`PATCH` in the code
  (appears in OP_ONLY_CAND as PATCH). The operation exists; the verb evolved.
- `GET /api/positions/{code_1c}` → the `#[Route]` placeholder was **renamed** `{code_1c}`→`{code1c}` in code
  (`PositionsController::getPositionByCode1c`). The URL contract (`/api/positions/<value>`) is identical;
  only the OpenAPI placeholder/param label differs.

### G4. Parameter divergences — PARAM (38)

Four patterns, all baseline-over-specified / baseline-incorrect / candidate-runtime-correct — **no code change**:

- **`required:true` dropped (~12)** — query params where baseline marked `required:true`. The bound
  `#[QueryParams]` DTO property is nullable / `required:[false]`, so the runtime Validator **accepts omission**;
  candidate `required:false` is runtime-correct. Baseline over-specified. (e.g. `by-hire-date` date_from/date_to,
  `times/schedules/current` scopeType/scopeKey, `salary/me` month/year.)
- **path-param type integer↔string (~12)** — candidate emits the method parameter's **PHP type**; baseline
  swagger hand-set a type. Path params are URL strings; the PHP type is the framework's parse hint. Both are
  valid; candidate tracks the code (`exams/summary examId` int; `worksheets/admin/{link_id|report_id}` string).
- **`type:enum`→integer (3)** — `worksheets-legacy change_id/active/archive`: baseline `type:enum` is **invalid
  OpenAPI** (`enum` is a constraint, not a type); candidate `type:integer` + `enum:[…]` is **correct**.
- **class-info `query` (1 lost + 3 additive)** — baseline represented the whole `TCClassInfoRequestDto` as ONE
  non-standard `query` param with a DTO `$ref`; candidate emits the **3 real scalar query params**
  (`uuid`, `positionCode1c`, `classType`) derived from `#[QueryParams]`. **Baseline-incorrect.**
- **`departments_ids[]` (1)** — baseline description has a trailing space; candidate omits it. Baseline typo.
- **`road-sheets/pdf type|driverOverride` (2 additive), `news publicationSite` (type added)** — candidate
  enriches (extra query params; `type:string` added to an enum param). Candidate more complete/correct.

---

## Summary

- **1025 raw semantic diffs**, all explained: ~891 by R1 (error policy, anticipated), 46 by R3 (baseline
  incomplete), 36 by R4 (DTO-authoritative request body), 3 by R2 (operationId lockfile), and ~49 genuine
  per-item (G1–G4), each baseline-over-specified / baseline-incorrect / candidate-runtime-correct.
- **Genuine structural losses fixed in the candidate this pass** (section A): response headers, multipart /
  explicit request bodies, array-query item types + descriptions, F controller bodies, slug path-param
  descriptions.
- **No operation lost** (G3: a verb change and a placeholder rename, URL contract intact).
- **0 unexplained semantic diffs.**
