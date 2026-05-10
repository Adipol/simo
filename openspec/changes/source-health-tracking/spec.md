# Specification: source-health-tracking

**Change**: `source-health-tracking`
**Phase**: spec
**Date**: 2026-05-10
**Artifact store**: hybrid (engram + openspec/)

---

## Capability: source-health-tracking

### Purpose

Track per-source scraper run outcomes in `log_fuente_runs` and expose an aggregate health status on the dashboard health-strip, making silent source failures visible to operators in real time.

---

### Stakeholders

- **Reads**: Dashboard health-strip component, `DashboardSourceHealthService`, admin/operator users
- **Permissions**: Pill visible to all authenticated users; no sensitive details exposed (same principle as Gemini queue pill)

---

### Data Contracts

#### Inputs

- No external inputs — service reads from `log_fuente_runs` written by the Python scraper

#### Schema: `log_fuente_runs`

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | BIGSERIAL | NO | — | Primary key |
| `fuente_id` | BIGINT | NO | — | FK → `fuentes(id)` ON DELETE CASCADE |
| `started_at` | TIMESTAMP WITHOUT TIME ZONE | NO | — | UTC; captured before visit begins |
| `finished_at` | TIMESTAMP WITHOUT TIME ZONE | YES | NULL | UTC; null if visit aborted before completion |
| `estado` | VARCHAR(20) | NO | — | See valid states below |
| `http_status` | INTEGER | YES | NULL | HTTP response code; null if no HTTP call made |
| `cambios_detectados` | INTEGER | NO | 0 | Count of changes saved this visit |
| `error_mensaje` | TEXT | YES | NULL | First 500 chars of exception or error detail |
| `duracion_segundos` | FLOAT | YES | NULL | `finished_at - started_at` in seconds |

**Valid states** (check constraint or app-level validation):
`success`, `http_error`, `timeout`, `captcha`, `parse_error`, `other`

**Failure definition**: `estado != 'success'` — all non-success states count as a failure for threshold computation.

**Indexes**:

| Index | Columns | Purpose |
|---|---|---|
| `idx_lfr_fuente_started` | `(fuente_id, started_at)` | Per-source ordered history queries |
| `idx_lfr_estado_started` | `(estado, started_at)` | Queries by state across fuentes |
| `idx_lfr_started_at` | `(started_at)` | Retention sweep (no fuente_id needed) |

**No `created_at`/`updated_at`** — rows are immutable. `started_at` serves as creation timestamp.

#### Outputs

```php
final readonly class SourceHealthSummaryDTO {
    public int               $total_fuentes_activas;
    public int               $ok;
    public int               $degradadas;
    public int               $muertas;
    public int               $sin_info;
    public bool              $available;           // false when total_fuentes_activas == 0
    public \DateTimeImmutable $last_aggregation_at;
}

final readonly class SourceHealthDTO {
    public int               $fuente_id;
    public string            $nombre;
    public string            $status;              // 'ok' | 'degradado' | 'muerto' | 'sin_info'
    public int               $consecutive_failures;
    public ?\DateTimeImmutable $last_run_at;
    public ?\DateTimeImmutable $last_ok_at;
}
```

#### Service API

```php
final class DashboardSourceHealthService {
    public function __construct(private readonly DashboardCacheManager $cache) {}

    public function getSummary(): SourceHealthSummaryDTO;
    public function getPerSourceStatus(int $fuenteId): SourceHealthDTO;
}
```

Cache key: `dashboard:source-health` | TTL: 60s

#### Config keys

```php
// config/dashboard.php → source_health block
'source_health' => [
    'consecutive_failures_degraded' => 3,   // default
    'consecutive_failures_dead'     => 10,  // default
]
```

---

### Requirements

#### REQ-1: log_fuente_runs schema

The system MUST create a `log_fuente_runs` table with the schema defined in the Data Contracts section, including all indexes and the FK with CASCADE delete.

##### Scenario: Table creation via migration

- GIVEN the migration `create_log_fuente_runs_table` has not been run
- WHEN `php artisan migrate` executes
- THEN `log_fuente_runs` exists with all 9 columns, 3 indexes, and FK → `fuentes(id)` ON DELETE CASCADE

##### Scenario: Invalid estado rejected

- GIVEN a row insert is attempted with `estado = 'unknown_state'`
- WHEN the insert reaches the application validation layer
- THEN a validation error is raised and the row is NOT written

##### Scenario: Composite index supports per-source history query

- GIVEN `log_fuente_runs` has rows for fuente_id=5
- WHEN querying `WHERE fuente_id = 5 ORDER BY started_at DESC LIMIT 10`
- THEN the query uses index `idx_lfr_fuente_started` without a sequential scan

---

#### REQ-2: Python instrumentation — try/finally coverage

The Python scraper MUST write exactly one row to `log_fuente_runs` for every execution of `procesar_fuente(fuente)`, regardless of which exit path is taken.

Exit paths that MUST be captured: `success`, `no_content`, `first_snapshot`, `no_change`, `http_error`, `timeout`, `captcha`, `parse_error`, `exception`.

##### Scenario: Successful visit logged

- GIVEN `procesar_fuente()` completes normally (HTML fetched, no changes detected)
- WHEN the finally block executes
- THEN one row is written with `estado = 'success'`, valid `started_at`, valid `finished_at`, `duracion_segundos` >= 0

##### Scenario: Exception during visit logged

- GIVEN an unhandled exception occurs inside `procesar_fuente()`
- WHEN the finally block executes
- THEN one row is written with `estado = 'other'` (or specific error state), `error_mensaje` containing the first 500 chars of the exception, and `finished_at` set to NOW(UTC)

##### Scenario: Log insert failure does not crash scraper

- GIVEN the database is unreachable when `registrar_fuente_run()` is called
- WHEN the INSERT fails with a psycopg2 exception
- THEN the error is logged to the scraper logger AND `procesar_fuente()` returns normally — the scraper cycle continues

---

#### REQ-3: UTC consistency for timestamps

The system MUST use UTC exclusively for all timestamps in `log_fuente_runs`. Python MUST use `datetime.now(timezone.utc)` (NOT `datetime.now()` without tz). The DB column type is `TIMESTAMP WITHOUT TIME ZONE`, interpreted as UTC, consistent with the rest of the schema.

##### Scenario: Python writes UTC-correct timestamps

- GIVEN `procesar_fuente()` starts at 15:30:00 UTC on a server with local timezone UTC-3
- WHEN the row is written
- THEN `started_at` contains `15:30:00`, NOT `12:30:00`

##### Scenario: Laravel reads timestamps without offset error

- GIVEN a row exists with `started_at = '2026-05-10 15:30:00'` written by Python
- WHEN `DashboardSourceHealthService` reads and exposes `last_run_at`
- THEN `last_run_at` matches the UTC value without timezone shift

---

#### REQ-4: DashboardSourceHealthService — getSummary contract

`getSummary()` MUST query all active fuentes (`fuentes.activo = true`), compute per-source status, and return a `SourceHealthSummaryDTO`. Results MUST be cached at key `dashboard:source-health` for 60 seconds.

##### Scenario: Returns accurate counts

- GIVEN 24 active fuentes: 20 ok, 2 degradadas, 1 muerta, 1 sin_info
- WHEN `getSummary()` is called
- THEN the DTO has `total_fuentes_activas=24`, `ok=20`, `degradadas=2`, `muertas=1`, `sin_info=1`, `available=true`

##### Scenario: Cache hit returns stale data within TTL

- GIVEN `getSummary()` was called 30 seconds ago and cached
- WHEN `getSummary()` is called again
- THEN no DB query is issued; the cached DTO is returned

##### Scenario: No active fuentes → available false

- GIVEN `fuentes` has zero rows with `activo = true`
- WHEN `getSummary()` is called
- THEN the DTO has `available=false`, `total_fuentes_activas=0`

---

#### REQ-5: Status derivation per source

The system MUST derive per-source status by counting the most recent consecutive failures from `log_fuente_runs`.

Rules (evaluated in order):
1. No rows in `log_fuente_runs` for this fuente → `sin_info`
2. Consecutive tail failures ≥ `config('dashboard.source_health.consecutive_failures_dead')` (default 10) → `muerto`
3. Consecutive tail failures ≥ `config('dashboard.source_health.consecutive_failures_degraded')` (default 3) → `degradado`
4. Otherwise → `ok`

"Failure" = `estado != 'success'`. Consecutive failures are counted from the most recent run backwards until a `success` row is found or all rows are exhausted.

##### Scenario: Source with 12 consecutive failures → muerto

- GIVEN a fuente has 12 most-recent rows all with `estado != 'success'`
- WHEN status is derived
- THEN status = `muerto`

##### Scenario: Source with 4 consecutive failures → degradado

- GIVEN a fuente has 4 most-recent rows all with `estado != 'success'`, then a success before that
- WHEN status is derived
- THEN status = `degradado`

##### Scenario: Source with 2 consecutive failures then success → ok

- GIVEN a fuente's last 3 rows are: `http_error`, `http_error`, `success` (newest first)
- WHEN status is derived
- THEN status = `ok` (consecutive_failures = 2, below degraded threshold of 3)

##### Scenario: Source with zero runs → sin_info

- GIVEN a fuente was just created with no rows in `log_fuente_runs`
- WHEN status is derived
- THEN status = `sin_info`

##### Scenario: Config thresholds respected

- GIVEN `consecutive_failures_degraded = 5` and `consecutive_failures_dead = 15` in config
- WHEN a fuente has 7 consecutive failures
- THEN status = `degradado` (not muerto, because 7 < 15)

---

#### REQ-6: SourceHealthSummaryDTO field contracts

The DTO MUST satisfy these field invariants:

- `ok + degradadas + muertas + sin_info == total_fuentes_activas` (always)
- `available = (total_fuentes_activas > 0)`
- `last_aggregation_at` is the timestamp of the most recent `getSummary()` computation (not cache retrieval)

##### Scenario: Count invariant holds across all states

- GIVEN 10 active fuentes with mixed statuses
- WHEN `getSummary()` returns a DTO
- THEN `ok + degradadas + muertas + sin_info == 10`

##### Scenario: last_aggregation_at reflects computation time

- GIVEN `getSummary()` is called at 10:00:00 UTC and result is cached
- WHEN `getSummary()` is called at 10:00:45 UTC (within 60s TTL)
- THEN `last_aggregation_at` is still `10:00:00 UTC` (from cached result, not current time)

---

#### REQ-7: Health-strip pill rendering

The `<x-dashboard.health-strip>` component MUST render a "Fuentes" pill displaying aggregate source health. The pill dot color MUST reflect worst-case status: green (all ok or sin_info), amber (any degradadas), red (any muertas).

##### Scenario: All sources ok → green pill

- GIVEN `SourceHealthSummaryDTO` has `ok=24`, `degradadas=0`, `muertas=0`
- WHEN the health-strip renders
- THEN the pill shows "24 ok" with a green dot

##### Scenario: Mixed degraded → amber pill

- GIVEN `SourceHealthSummaryDTO` has `ok=22`, `degradadas=2`, `muertas=0`
- WHEN the health-strip renders
- THEN the pill shows "22 ok / 2 degradadas" with an amber dot

##### Scenario: Any dead source → red pill

- GIVEN `SourceHealthSummaryDTO` has `ok=22`, `degradadas=1`, `muertas=1`
- WHEN the health-strip renders
- THEN the pill shows "22 ok / 1 degradada / 1 muerta" with a red dot

---

#### REQ-8: Empty state during warmup

When `log_fuente_runs` has no data yet (first deploy, `sin_info` for all fuentes), the pill MUST show "Recolectando datos…" in grey — not "0 ok / 0 degradadas".

##### Scenario: All fuentes sin_info → warmup message

- GIVEN `SourceHealthSummaryDTO` has `sin_info=24`, `ok=0`, `degradadas=0`, `muertas=0`
- WHEN the pill renders
- THEN text is "Recolectando datos…" with a grey dot

##### Scenario: No active fuentes → available false

- GIVEN `SourceHealthSummaryDTO.available = false`
- WHEN the pill renders
- THEN text is "Sin fuentes activas" with a grey dot

##### Scenario: Partial warmup — some fuentes have data

- GIVEN `SourceHealthSummaryDTO` has `ok=10`, `sin_info=14`
- WHEN the pill renders
- THEN text shows real counts (e.g., "10 ok / 14 sin datos") — NOT the warmup message

---

#### REQ-9: FK cascade on fuente deletion

When a row is deleted from `fuentes`, all related rows in `log_fuente_runs` MUST be deleted automatically by the database CASCADE constraint. No application-level cleanup is required.

##### Scenario: Deleting a fuente cascades to log rows

- GIVEN `log_fuente_runs` has 50 rows for `fuente_id = 7`
- WHEN `fuentes` row with `id = 7` is deleted
- THEN all 50 rows with `fuente_id = 7` are deleted from `log_fuente_runs` automatically

##### Scenario: Deleting other fuente does not affect unrelated rows

- GIVEN `log_fuente_runs` has rows for both `fuente_id = 7` and `fuente_id = 8`
- WHEN `fuentes` row with `id = 7` is deleted
- THEN rows for `fuente_id = 8` remain intact

---

#### REQ-10: Performance budget

`getSummary()` MUST complete its DB query in ≤ 100ms on cold cache across all 24 active fuentes. The query MUST NOT execute one query per fuente (N+1 prohibited). A single query using GROUP BY or LATERAL JOIN is required.

##### Scenario: Single query for all fuentes

- GIVEN 24 active fuentes each with up to 90 days of run history
- WHEN `getSummary()` computes health on cold cache
- THEN exactly one SQL query is issued against `log_fuente_runs` (verified by query log)

##### Scenario: Cold cache completes within budget

- GIVEN 24 active fuentes with typical data volume
- WHEN `getSummary()` runs on cold cache
- THEN total DB time is ≤ 100ms

##### Scenario: Warm cache returns instantly

- GIVEN `getSummary()` was cached within the last 60 seconds
- WHEN `getSummary()` is called again
- THEN response time is ≤ 10ms (cache hit, no DB query)

---

### Out of Scope

- Active alerts (Slack/email/webhook) → future SDD `source-health-alerts`
- Per-source drill-down panel or modal → deferred until data validates the need
- Auto-disable of dead fuentes → separate SDD
- Admin "Test connection" button
- Retroactive historical data — first days show "Recolectando datos…" (by design)
- Changes to scraping logic itself — instrumentation only
- Modification of `DashboardHealthService` — pipeline health and source health are separate services

---

### Edge Cases

| Case | Expected Behavior |
|---|---|
| `log_fuente_runs` empty (no runs ever) | All fuentes → `sin_info`; pill shows "Recolectando datos…" |
| `fuente.activo = false` | Excluded from all counts; does not appear in any status bucket |
| `finished_at` NULL (visit aborted mid-flight) | Row still written with `estado = 'other'`; `duracion_segundos = NULL` |
| DB down during Python `registrar_fuente_run()` | Error logged; scraper cycle continues; no row written (gap in history) |
| Config thresholds changed after deploy | Effective immediately on next cache bust (60s max lag); no migration needed |
| Fuente deleted while getSummary() in progress | COUNT of fuente_id rows = 0 after CASCADE; harmless — service recomputes on next call |
| All 24 fuentes degraded simultaneously | Pill shows red dot; `muertas=0`, `degradadas=24`; correct by definition |
| Python sends `datetime.now()` without tz (bug) | Query returns wrong time offset; REQ-3 test catches this at CI |
| Duplicate inserts (Python retry on transient error) | Two rows for same visit window; consecutive_failures still computed correctly from `started_at` ordering |
