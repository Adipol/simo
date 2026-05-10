# Exploration: source-health-tracking

**Change**: `source-health-tracking`
**Phase**: explore
**Date**: 2026-05-10
**Artifact store**: hybrid (engram + openspec/)

---

## Current State

The dashboard (from `redesign-dashboard` SDD) exposes a **pipeline health strip** with 5
macro-level pills: Scraper status, PEP Monitor status, Queue depth, Latency P50/P95, and
Gemini quota. None of these are per-source — if one of the 24 active fuentes fails silently,
the user has zero visibility.

The Python scraper (`pep_monitor.py`) processes each fuente individually in `procesar_fuente()`
called from `check_all()`. It already writes to `log_scripts` at cycle start/finish, but
there is **no per-source outcome record** anywhere. The only timestamp on `fuentes` is
`ultimo_check`, which is updated via `update_ultimo_check()` even for successful visits
(no indication of failure).

### Python scraper architecture (mapped)

```
runner.py (supervisor-managed)
  └── ejecutar_scraper()  ← subprocess
       └── pep_monitor.py main()
            └── PEPMonitor.run()
                 └── PEPMonitor.check_all()  ← iterates all fuentes
                      └── PEPMonitor.procesar_fuente(fuente: dict)
                           ├── _obtener_html_raw()     (HTTP / Playwright / PDF)
                           ├── limpiar_html() / limpiar_pdf()
                           ├── DatabaseManager.guardar_snapshot()
                           ├── DatabaseManager.guardar_cambio()
                           └── DatabaseManager.update_ultimo_check()
```

**DB connection**: psycopg2 raw — `DatabaseManager._connect()` uses `psycopg2.connect()` with
`autocommit=True`. No SQLAlchemy. Writes are per-row, not batched.

**Writing a new per-source log row** means adding a `registrar_fuente_run()` method to
`DatabaseManager` and calling it at the end of `procesar_fuente()`.

**Exact instrumentation point**: `procesar_fuente()` exits via `return` at several points
(no content, first snapshot, no change, etc.). The cleanest instrumentation pattern is a
try/finally wrapping the body with `started_at = datetime.utcnow()` at the top and an
insert in the `finally` block, capturing the outcome via a local variable.

---

## Affected Areas

### Python
- `scripts/website_monitor_pro/pep_monitor.py`
  - `DatabaseManager` — add `registrar_fuente_run()` method + `_verify_tables()` to include `log_fuente_runs`
  - `PEPMonitor.procesar_fuente()` — wrap with try/finally to capture outcome
  - `scripts/website_monitor_pro/tests/` — add `test_fuente_runs.py`

### Laravel — new
- `database/migrations/YYYY_create_log_fuente_runs_table.php`
- `app/Models/LogFuenteRun.php`
- `app/Services/Dashboard/DashboardSourceHealthService.php`
- `app/Services/Dashboard/DTOs/FuenteHealthDTO.php`
- `app/Services/Dashboard/DTOs/SourceHealthSummaryDTO.php`
- `tests/Feature/Services/Dashboard/DashboardSourceHealthServiceTest.php`

### Laravel — modified
- `app/Models/Fuente.php` — add `logFuenteRuns()` hasMany relationship
- `resources/views/components/dashboard/health-strip.blade.php` — add source-health pill
- `resources/views/livewire/dashboard.blade.php` (or equivalent) — pass source health data

### Not touched
- `DashboardHealthService.php` — stays focused on pipeline health (macro level)
- `PipelineHealthDTO.php` — not expanded; source health is its own DTO tree

---

## Schema Proposal: `log_fuente_runs`

```sql
CREATE TABLE log_fuente_runs (
    id              BIGSERIAL PRIMARY KEY,
    fuente_id       BIGINT NOT NULL REFERENCES fuentes(id) ON DELETE CASCADE,
    started_at      TIMESTAMPTZ NOT NULL,                    -- UTC, set at visit start
    finished_at     TIMESTAMPTZ NOT NULL,                    -- UTC, set at visit end
    duracion_ms     INTEGER NOT NULL,                        -- finished - started in ms
    resultado       VARCHAR(20) NOT NULL,                    -- 'ok' | 'error' | 'sin_contenido' | 'timeout'
    http_status     SMALLINT NULL,                           -- 200, 403, 500 etc — null if no HTTP call
    mensaje_error   VARCHAR(500) NULL,                       -- first 500 chars of exception if any
    cambio_detectado BOOLEAN NOT NULL DEFAULT FALSE          -- true if guardar_cambio() was called
);

CREATE INDEX idx_lfr_fuente_id       ON log_fuente_runs (fuente_id);
CREATE INDEX idx_lfr_started_at      ON log_fuente_runs (started_at DESC);
CREATE INDEX idx_lfr_fuente_started  ON log_fuente_runs (fuente_id, started_at DESC);
```

**Field-by-field rationale**:
- `fuente_id` — FK to fuentes; ON DELETE CASCADE so cleanup is automatic if a fuente is removed
- `started_at` / `finished_at` — UTC timestamps with tz (TIMESTAMPTZ); consistent with the
  rest of the schema. Python uses `datetime.utcnow()`.
- `duracion_ms` — integer milliseconds avoids float precision issues; mirrors `duracion_segundos`
  in `log_scripts` but with higher resolution (scraper visits are often sub-second)
- `resultado` — enum-like varchar; Python-side states: `ok`, `error`, `sin_contenido`, `timeout`.
  Not an actual PG ENUM to avoid migration friction when adding states.
- `http_status` — diagnostic: lets the UI say "403 Forbidden" vs "connection refused" without
  parsing `mensaje_error`
- `mensaje_error` — first 500 chars of exception message or HTTP error (matches `log_scripts` size)
- `cambio_detectado` — boolean flag; avoids joining cambios table just to know if a visit
  produced a change. Enables fast dashboard queries.

**No `created_at` / `updated_at`** — rows are immutable once written, following `log_scripts`
convention. `started_at` serves as the creation timestamp.

---

## Approaches

### 1. Schema Granularity

**Option A — Row per scraper visit** ← RECOMMENDED
- Pros: full history, enables failure-rate computation over any window, enables "last N runs"
  pattern in the UI, consistent with `log_scripts` pattern
- Cons: 24 fuentes × scraper cycles = higher row count; manageable with retention
- Effort: Low

**Option B — Row per state-change only**
- Pros: very small table
- Cons: can't compute failure rate, can't detect "stuck at OK" transitions, harder to query
  "how many consecutive failures?", diverges from established `log_scripts` pattern
- Effort: Medium (need state machine logic in Python)

**Option C — Row per visit + monthly aggregates**
- Pros: best of both worlds long-term
- Cons: requires cron for aggregation, over-engineering for current scale (24 fuentes)
- Effort: High

**Decision**: Option A with a retention policy (mirror `LogScriptRetentionService`). Keep
last 90 days of OK runs, 30 days of error runs. At 24 fuentes × ~12 cycles/day = 288 rows/day
→ 90-day retention ≈ 26,000 rows. Trivial at PostgreSQL scale.

---

### 2. Service Architecture

**Option A — New `DashboardSourceHealthService`** ← RECOMMENDED
- Pros: single responsibility, follows existing pattern (`DashboardHealthService` focuses on
  pipeline, new service focuses on sources), independently cacheable, independently testable
- Cons: one more service to inject in Livewire component
- Effort: Low

**Option B — Extend `DashboardHealthService` with `sourceHealth()` method**
- Pros: one injection point
- Cons: violates SRP (pipeline health vs source health are different concerns), bloats an
  already-complete service, cache key conflicts
- Effort: Low but messier

**Option C — Static method on `Fuente` model**
- Pros: feels "Eloquent-y"
- Cons: business logic in model, violates project AGENTS.md rules
- Effort: Low but wrong

**Decision**: Option A — `DashboardSourceHealthService` with its own DTOs and cache key
`dashboard:source-health`. Cache TTL: 60s (source-health aggregations are heavier than
macro health checks). PHP-side SQL dual-driver pattern for tests (same as latency).

---

### 3. DTO Structure

```php
// Per-source status
final readonly class FuenteHealthDTO {
    public string $nombre;
    public int    $fuente_id;
    public string $status;           // 'ok' | 'degradado' | 'muerto' | 'sin_info'
    public ?int   $consecutive_failures;
    public ?float $success_rate_7d;  // 0..1
    public ?\DateTimeImmutable $last_run_at;
    public ?\DateTimeImmutable $last_ok_at;
}

// Aggregate summary for the health-strip pill
final readonly class SourceHealthSummaryDTO {
    public int   $total;
    public int   $ok_count;
    public int   $degradado_count;
    public int   $muerto_count;
    public int   $sin_info_count;
    public string $status;           // worst-case: 'ok' | 'warning' | 'error'
    /** @var FuenteHealthDTO[] */
    public array $fuentes;
}
```

---

### 4. Status Derivation

**Proposed thresholds** (configurable via `config/dashboard.php`):

```php
'source_health' => [
    'degradado_consecutive_failures' => 3,   // N consecutive errors → degradado
    'muerto_consecutive_failures'    => 10,  // N consecutive errors → muerto
    'success_rate_window_days'       => 7,   // window for rate computation
    'min_runs_for_rate'              => 5,   // min runs before rate is meaningful
],
```

- `sin_info` — no runs in `log_fuente_runs` yet (first days post-deploy)
- `ok` — last run was `resultado='ok'` AND consecutive_failures < degradado threshold
- `degradado` — consecutive_failures ≥ 3 AND < 10
- `muerto` — consecutive_failures ≥ 10

**SQL derivation approach**: PHP-side computation on read (query recent window, compute in
service). No materialized column on `fuentes` — avoids coupling Python writes to an Observer
and keeps the `fuentes` table stable. The service computes status at read time, cached.

---

### 5. UI Integration

**Option A — New pill in health-strip ("N ok / M degradadas")** ← RECOMMENDED
- Pros: zero new UI surface, consistent with existing health-strip pattern, pill is reusable
  component, minimal change to Livewire component
- Cons: no per-source detail visible without a second click/expand
- Effort: Low

**Option B — Collapsible panel below health-strip with per-source table**
- Pros: full visibility at a glance
- Cons: significant UI complexity, Alpine.js expand/collapse needed, Livewire re-render on expand
- Effort: High

**Option C — New page `/dashboard/source-health`**
- Pros: clean separation
- Cons: navigation disruption, out of scope for "make data visible" goal
- Effort: Medium

**Decision**: Option A for this SDD (pill in strip showing "24 ok" or "22 ok / 2 degradadas").
Option B can be a follow-up SDD once data exists and user validates the need for drill-down.

The pill click action can open a simple modal or Alpine.js popover with the per-source table —
scoped to a MAYBE for this SDD.

---

### 6. Python–Laravel Boundary

**Option A — Python writes raw rows to `log_fuente_runs` (Laravel reads only)** ← RECOMMENDED
- Pros: mirrors the established pattern for `log_scripts`, `snapshots`, `cambios`; no API
  surface needed; trusted backend-to-backend; simple
- Cons: Python must know the schema
- Effort: Low

**Option B — Python writes via a Laravel API endpoint**
- Pros: decoupled schema
- Cons: overkill, introduces HTTP dependency in the scraper, auth complexity
- Effort: High

**Option C — Python writes raw + Laravel Observer cleans up**
- Pros: retention logic in PHP
- Cons: Observer triggers on Python inserts? Observers don't fire on direct DB writes
- Effort: Medium, but architecturally broken (observers don't fire on psycopg2 inserts)

**Decision**: Option A. Python writes directly via psycopg2. Laravel reads via Eloquent.
Retention cron in PHP (`LogFuenteRunRetentionService`).

---

### 7. Cache TTL

- Health-strip macro: 15s (existing config `dashboard.health_cache_ttl`)
- Source health summary: 60s (recommended) — aggregate SQL is heavier, and source status
  doesn't change in seconds

---

## Risks

1. **`procesar_fuente()` has 6+ early return paths** — the try/finally instrumentation must
   be carefully placed to capture ALL exit paths (no content, first snapshot, error, timeout,
   no change, etc.). Missing one path means dark periods in the health data.

2. **Python test infrastructure exists but is shallow** — `tests/test_runner.py`,
   `test_cascade.py`, `test_ssl_verify.py`, `test_storage.py` all use `unittest.mock` and
   pytest. No DB fixtures exist for per-source run tests. A new `test_fuente_runs.py` is
   needed, but the pattern is established.

3. **Supervisor deploy coordination** — modifying `pep_monitor.py` requires
   `supervisorctl restart simo-runner` on the VPS. This must be documented in the task list
   and communicated to the operator. Laravel migrations (the new table) must be run FIRST,
   because Python will try to insert into `log_fuente_runs` on next cycle.
   **Deploy order**: `php artisan migrate` → `supervisorctl restart simo-runner`.

4. **UTC consistency** — `pep_monitor.py` currently uses `datetime.now()` (local time) in
   some places (e.g., `mostrar_alerta()`). The new `started_at` / `finished_at` MUST use
   `datetime.utcnow()` or `datetime.now(timezone.utc)` to be consistent with PostgreSQL
   `NOW()` (which is session timezone-aware). Mismatch causes wrong dashboard time display.

5. **"Sin info" period** — no historical data exists. For the first days after deploy, all
   24 fuentes will show `sin_info`. Same pattern as latency/quota in PR2. The pill must
   handle this gracefully (show "Recolectando datos…" instead of 0 ok / 0 degradadas).

---

## Open Questions for User

These are the **4 product decisions** that need your input before the proposal is written:

**Q1 — Granularity** (RESOLVED in exploration: recommend Option A — row per visit)
> Do you confirm row-per-visit with a 90-day retention policy? Or do you want state-change-only?

**Q2 — "Degradado" definition** (OPEN)
> Proposed: 3 consecutive failures → degradado, 10 consecutive → muerto.
> Are these thresholds right for your operational reality? Or should the threshold be based
> on a success-rate window (e.g., < 80% success rate over the last 7 days)?
> Recommendation: consecutive-failures approach is simpler to compute and more intuitive.

**Q3 — Retroactivity** (RESOLVED: no historical data, "sin_info" pattern from latency/quota)
> Confirmed: no retroactive data. First days will show "Recolectando datos…" per fuente.
> Is that acceptable?

**Q4 — Alerts** (OPEN — DEFER or include?)
> Should this SDD include active alerts (Slack/email/webhook) when a fuente goes degradado/muerto?
> Recommendation: DEFER alerts to a future SDD. This one scopes to "make the data visible."
> Confirm?

**Q5 — Drill-down UI** (OPEN — MAYBE)
> Should this SDD include a per-source detail table (modal/panel) on click of the
> health-strip pill? Or is the pill summary ("22 ok / 2 degradadas") sufficient for now?
> Recommendation: summary pill only in this SDD; detail view is a follow-up.

---

## Recommended Path Forward

1. **Confirm Q2, Q4, Q5** with the user (Q1 and Q3 are resolved).
2. Proceed to `sdd-propose` with:
   - `log_fuente_runs` table (row per visit, 90-day retention)
   - Python instrumentation in `procesar_fuente()` via try/finally
   - `DashboardSourceHealthService` + `FuenteHealthDTO` + `SourceHealthSummaryDTO`
   - Health-strip pill showing aggregate status
   - Tests: PHPUnit dual-driver (SQLite/PG) + new `test_fuente_runs.py`
3. Deploy order must be documented: migrate first, then restart supervisor.

---

## Ready for Proposal

**Yes** — pending user confirmation on Q2 (degradado thresholds), Q4 (alerts scope), Q5 (drill-down scope).

The architecture is clear, risks are mitigated, and the Python instrumentation point is
precisely identified (`procesar_fuente()` try/finally pattern).
