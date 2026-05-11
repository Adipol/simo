# Capability: dedupe-safety-net

**Version**: 1.0.0 · **Date**: 2026-05-10 · **Status**: draft

---

## Purpose

Provides a scheduled safety net that compensates for the Python scraper writing `resultados_scraping` rows via raw SQL, which bypasses Eloquent observers and prevents `DedupeArticulosJob` from ever being dispatched. A periodic command discovers rows that have not yet been processed for deduplication, dispatches one job per row, and records a persisted marker so rows are not re-dispatched on subsequent runs. The capability mirrors the proven `simo:analizar-gemini` pattern and isolates its queue worker to prevent Gemini API saturation from starving dedupe processing.

---

## Requirements

### REQ-1: Persisted dedupe processing marker

Every row in `resultados_scraping` MUST carry a `dedupe_processed_at` column (TIMESTAMP, nullable) that records whether the row has been through the deduplication pipeline. The column MUST default to `NULL` on insert. `DedupeArticulosService::procesar()` MUST set `dedupe_processed_at` to the current timestamp in BOTH of its exit paths: the post-transaction success path and the pre-transaction early-exit path (row already a secondary).

#### SCN-1.1: New row arrives with NULL marker

- **GIVEN** a new `resultados_scraping` row is inserted (by any client: Eloquent or raw SQL)
- **WHEN** the row is read back from the database
- **THEN** `dedupe_processed_at` IS NULL

#### SCN-1.2: Service sets marker on successful dedupe

- **GIVEN** a row with `dedupe_processed_at IS NULL`
- **WHEN** `DedupeArticulosService::procesar()` completes the full deduplication transaction
- **THEN** `dedupe_processed_at` is set to a non-null timestamp on that row

#### SCN-1.3: Service sets marker on early-exit (already a secondary)

- **GIVEN** a row with `dedupe_processed_at IS NULL` and `secundario_de` already set (row is already a secondary)
- **WHEN** `DedupeArticulosService::procesar()` detects the early-exit condition at the pre-transaction check
- **THEN** `dedupe_processed_at` is set to a non-null timestamp on that row

---

### REQ-2: Scheduled command dispatches pending dedupe jobs

The system MUST provide a command `simo:dedupar-pendientes` that queries `resultados_scraping` for rows where `dedupe_processed_at IS NULL` and dispatches one `DedupeArticulosJob` per row. The command MUST respect the kill switch (`services.dedupe.enabled`). The command MUST be scheduled to run every five minutes with `withoutOverlapping()` and `onOneServer()`.

#### SCN-2.1: Rows pending dedupe are dispatched

- **GIVEN** the kill switch is enabled
- **AND** N rows exist with `dedupe_processed_at IS NULL`
- **WHEN** `simo:dedupar-pendientes` runs
- **THEN** exactly N `DedupeArticulosJob` instances are pushed to the `dedupe` queue

#### SCN-2.2: Kill switch disables all dispatching

- **GIVEN** `services.dedupe.enabled` is `false`
- **WHEN** `simo:dedupar-pendientes` runs
- **THEN** zero jobs are pushed to any queue

#### SCN-2.3: No rows pending produces no dispatch

- **GIVEN** the kill switch is enabled
- **AND** zero rows exist with `dedupe_processed_at IS NULL`
- **WHEN** `simo:dedupar-pendientes` runs
- **THEN** zero jobs are pushed to any queue

#### SCN-2.4: Command is idempotent across runs

- **GIVEN** N rows had `dedupe_processed_at IS NULL` and were dispatched in run #1
- **AND** `DedupeArticulosService` set `dedupe_processed_at` on those rows
- **WHEN** `simo:dedupar-pendientes` runs again (run #2)
- **THEN** zero additional jobs are dispatched for those same rows

---

### REQ-3: Dedicated queue worker isolated from gemini queue

The `dedupe` queue MUST be served by a dedicated supervisor program (`simo-dedupe-worker`) that is separate from the `simo-gemini-worker` process. Neither worker MUST consume the other's queue. `DEPLOY.md` MUST document the `[program:simo-dedupe-worker]` supervisor block and include an ops checklist for deployment.

#### SCN-3.1: Dedupe worker processes dedupe queue

- **GIVEN** `simo-dedupe-worker` is running per the documented supervisor config
- **AND** a `DedupeArticulosJob` is pushed to the `dedupe` queue
- **WHEN** the worker processes the next job
- **THEN** the job is consumed from the `dedupe` queue

#### SCN-3.2: Gemini saturation does not block dedupe

- **GIVEN** `simo-gemini-worker` is saturated (processing Gemini jobs or down)
- **WHEN** a `DedupeArticulosJob` is pushed to the `dedupe` queue
- **THEN** the job remains available for `simo-dedupe-worker` and is not affected by the Gemini worker's state

#### SCN-3.3: Worker restart semantics documented

- **GIVEN** `DEPLOY.md` contains the `[program:simo-dedupe-worker]` supervisor block
- **WHEN** an operator runs `sudo supervisorctl reread && sudo supervisorctl update`
- **THEN** `simo-dedupe-worker` starts and appears as `RUNNING` in `supervisorctl status`

---

### REQ-4: Historical rows processed automatically (backfill via safety net)

Rows inserted before this capability is deployed MUST receive `dedupe_processed_at = NULL` via the migration default, making them visible to the safety-net command. No separate backfill command is required. The first scheduled execution MUST dispatch jobs for all pre-existing pending rows.

#### SCN-4.1: Pre-existing rows have NULL marker after migration

- **GIVEN** N rows exist in `resultados_scraping` before the migration runs
- **WHEN** the migration `add_dedupe_processed_at_to_resultados_scraping_table` is executed
- **THEN** all N rows have `dedupe_processed_at IS NULL`

#### SCN-4.2: First run dispatches all historical rows

- **GIVEN** N historical rows with `dedupe_processed_at IS NULL` (no cap applied)
- **WHEN** `simo:dedupar-pendientes` runs for the first time after deploy
- **THEN** exactly N `DedupeArticulosJob` instances are dispatched

---

### REQ-5: Configurability via kill switch

The system MUST read `config('services.dedupe.enabled')` to determine whether dedupe processing is active. The config key MUST default to `true` so production continues without operator intervention. Operators MUST be able to disable the safety net by setting `DEDUPE_ENABLED=false` in `.env` and running `php artisan config:cache`.

#### SCN-5.1: Kill switch defaults to enabled

- **GIVEN** `DEDUPE_ENABLED` is not set in `.env`
- **WHEN** `config('services.dedupe.enabled')` is evaluated
- **THEN** the value is `true`

#### SCN-5.2: Kill switch disables command via env var

- **GIVEN** `DEDUPE_ENABLED=false` is set in `.env` and `config:cache` has been run
- **WHEN** `simo:dedupar-pendientes` runs
- **THEN** zero jobs are dispatched and the command exits without error

---

### REQ-6: Observability — logs and failure routing

The `simo:dedupar-pendientes` command MUST emit at least one log line per run recording how many jobs were dispatched (including zero). Failed `DedupeArticulosJob` executions MUST land in `failed_jobs` with `queue = 'dedupe'` after exhausting retries, distinguishable from Gemini failures.

#### SCN-6.1: Command logs dispatch count per run

- **GIVEN** the command runs with the kill switch enabled
- **WHEN** it finishes (regardless of how many rows were pending)
- **THEN** a log entry exists recording the number of jobs dispatched in that run

#### SCN-6.2: Failed jobs routed to dedupe queue in failed_jobs

- **GIVEN** a `DedupeArticulosJob` fails all retry attempts (`tries=3`)
- **WHEN** the job is moved to `failed_jobs`
- **THEN** the `failed_jobs.queue` column contains `'dedupe'`
- **AND** it is distinguishable from Gemini failures (which use `queue = 'gemini'`)

---

## Glossary

| Term | Definition |
|------|-----------|
| **pending dedupe** | A `resultados_scraping` row where `dedupe_processed_at IS NULL` — has not yet passed through the deduplication pipeline |
| **primary** | A `resultados_scraping` row where `secundario_de IS NULL` — no duplicate cluster parent |
| **secondary** | A `resultados_scraping` row where `secundario_de` is set to another row's `id` — identified as a duplicate of the primary |
| **safety net** | The periodic scheduled process (`simo:dedupar-pendientes`) that compensates for the Python scraper bypassing Eloquent observers |
| **kill switch** | `config('services.dedupe.enabled')` — boolean that enables/disables all dedupe dispatching without a code deploy |
| **early-exit path** | The branch in `DedupeArticulosService::procesar()` that returns before opening a transaction, triggered when the row is already a secondary (`secundario_de IS NOT NULL`) |
