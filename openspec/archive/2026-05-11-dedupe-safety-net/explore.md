# Exploration: dedupe-safety-net

**Change**: `dedupe-safety-net`
**Phase**: explore
**Date**: 2026-05-10
**Artifact store**: hybrid (engram + openspec/changes/dedupe-safety-net/explore.md)

---

## Current State

### The Bug (confirmed)

`DedupeArticulosJob` is never dispatched in production. Root cause:

- `ResultadoScrapingObserver::created()` (`app/Observers/ResultadoScrapingObserver.php:13-25`) dispatches `DedupeArticulosJob::dispatch($resultado->id)` only for Eloquent-triggered inserts.
- The Python scraper (`scripts/scraper_v2.2/core/database.py:339` `save_result()` and `:426-431` `save_results_batch()`) uses `psycopg2` raw SQL — Eloquent observers never fire.
- Production evidence: 97 rows in `resultados_scraping`, 0 with `secundario_de` set.
- The team already knew this for Gemini and fixed it via `routes/console.php:17-21` (`simo:analizar-gemini` every 5 min). Dedupe was never given the same treatment.

### Existing Safety-Net Pattern (Gemini — the template)

**Command**: `app/Console/Commands/AnalizarGemini.php`
**Schedule**: `routes/console.php:18-21` — every 5 minutes, `withoutOverlapping()`, `onOneServer()`

How it works:
1. Checks `config('services.gemini.enabled')` — kill switch.
2. Queries `ResultadoScraping::where('gemini_analyzed', false)->count()` — **flag-based idempotency**: the `gemini_analyzed` boolean column is the "already processed" signal.
3. If count > 0, dispatches ONE aggregate job: `AnalizarScrapingConFlash::dispatch()->onQueue('gemini')`.
4. `AnalizarScrapingConFlash` (NOT `ShouldBeUnique`) fetches a batch of 50 pending rows, processes them, and self-re-dispatches if more remain.
5. No per-row uniqueness needed at the command level because the aggregate job handles its own batch cursor.

Key distinction vs. dedupe: Gemini dispatches ONE aggregate job that processes N rows internally. Dedupe (`DedupeArticulosJob`) is per-row (one job per `resultadoId`). This changes the safety-net dispatch strategy.

---

## Affected Areas

- `app/Console/Commands/AnalizarGemini.php` — template to mirror
- `routes/console.php` — add new schedule entry
- `app/Jobs/DedupeArticulosJob.php` — target job; already `ShouldBeUnique`
- `app/Services/Dedupe/DedupeArticulosService.php` — underlying service; already idempotent
- `app/Models/ResultadoScraping.php` — may need new column or not (see Q3)
- `DEPLOY.md` — supervisor config section must be updated
- `/etc/supervisor/conf.d/simo-gemini-worker.conf` — ops-side change (not in repo)
- `tests/Feature/Gemini/AnalizarGeminiCommandTest.php` — test template (197 lines, 9 tests)

---

## Q1 — Existing Safety-Net Pattern Deep-Dive

**File**: `app/Console/Commands/AnalizarGemini.php`

| Aspect | How Gemini does it |
|---|---|
| Pending detection | `where('gemini_analyzed', false)` — boolean flag column |
| Dispatch strategy | ONE aggregate job if count > 0 |
| Avoids re-dispatch | Boolean flag flipped inside the job after processing |
| Batching | Inside the job: `limit(50)`, self-re-dispatches if more remain |
| Idempotency | `gemini_analyzed=true` makes the row invisible to future queries |
| Overlapping | `withoutOverlapping()` on schedule; no per-job uniqueness needed |
| Kill switch | `config('services.gemini.enabled')` at command entry |

**Key insight**: Gemini's model works because `gemini_analyzed` provides a natural "processed" flag that the job sets. Dedupe does NOT have an equivalent flag — and `secundario_de IS NOT NULL` only means "is a secondary", not "was ever processed by dedupe".

---

## Q2 — DedupeArticulosJob Shape

**File**: `app/Jobs/DedupeArticulosJob.php`

| Property | Value |
|---|---|
| Queue | `dedupe` |
| `$tries` | 3 |
| `$backoff` | `[5, 25, 125]` seconds |
| `$uniqueFor` | 300 seconds (5-minute lock) |
| `uniqueId()` | `"dedupe-{$this->resultadoId}"` |
| Idempotency | Line 63: `if ($article === null || $article->secundario_de !== null) return;` |

**`ShouldBeUnique` behavior**: Laravel uses the cache store (`CACHE_STORE=database` in this project) to hold a lock keyed `"laravel_unique_job:dedupe-{id}"` for 300 seconds. If the same `resultadoId` is dispatched again within 5 minutes, the second dispatch is a no-op — the job is never enqueued.

**Critical caveat**: `ShouldBeUnique` prevents double-enqueuing the same `resultadoId` within 5 minutes, but the lock is released when the job starts processing (or after 300s). If the safety-net runs every 5 minutes and dispatches per row, each run would succeed for rows whose lock expired — meaning every 5 min we'd re-dispatch every "unprocessed" row that isn't identified as done.

**Service idempotency**: `DedupeArticulosService::procesar()` (`app/Services/Dedupe/DedupeArticulosService.php:45`) checks `secundario_de !== null` before doing work, and uses `DB::transaction` + `lockForUpdate` (line 56) to prevent race conditions between concurrent jobs. A primary article (no match found) will have `secundario_de = null` forever — this is the problem for "processed as primary" tracking.

---

## Q3 — "Already Processed" Signal: Options Analysis

The core problem: `secundario_de IS NOT NULL` means "is a secondary". But a row can be `secundario_de IS NULL` because:
- (a) It was never processed by dedupe at all, or
- (b) It was processed and no match was found (it's a genuine primary).

Without distinguishing (a) from (b), the safety net can't avoid re-processing millions of historical rows on every run.

### Option A — Add `dedupe_processed_at TIMESTAMP NULL`
- Set inside `DedupeArticulosService::procesar()` after processing (both when secondary AND when no match found).
- Safety net queries: `WHERE dedupe_processed_at IS NULL`.
- **Pros**: Clean semantics. Perfectly mirrors `gemini_analyzed`. Simple query. Easy to backfill (NULL = pending). Handles the "processed as primary" case. Index can be partial.
- **Cons**: New migration. Needs fillable update in model. Must update service to set flag. `ShouldBeUnique` still protects against double-dispatch within 5 min.
- **Effort**: Low (1 migration + 2 line change in service + model fillable).

### Option B — `secundario_de IS NOT NULL` for secondaries + no column for primaries
- Safety net must re-dispatch ALL rows with `secundario_de IS NULL` every run (includes both pending AND processed-primaries).
- Rely on `ShouldBeUnique` (300s lock) to absorb re-dispatches of already-in-flight rows.
- **Pros**: No migration needed. No model change.
- **Cons**: Every 5-minute run dispatches ALL `secundario_de IS NULL` rows (97 today, growing). `ShouldBeUnique` only blocks within 300s — after 5 min the locks expire and you re-dispatch the same 97+ rows every single run. Queue fills with permanently-primary articles forever. Catastrophic at scale. Does NOT work.

### Option C — Separate `dedupe_runs` log table
- Overkill. Not needed for this scope.
- **Effort**: High. Explicitly out of scope.

### Option D — Dispatch per-row within a recent window (last N days only)
- Safety net only dispatches rows from the last N days (same window as `ventanaDias` — 7 days by default).
- Rows older than N days are never re-dispatched.
- `ShouldBeUnique` + job idempotency absorbs duplicates within the window.
- **Pros**: No migration. Bounded scope. Naturally expires.
- **Cons**: Rows within the window (7 days) are re-dispatched every 5 min unless `ShouldBeUnique` is still active. 300s lock expires before 5-min schedule → rows re-queued on every run. At 97 rows, low risk. At 1000 rows, queue saturation. Also, historical backfill is impossible (rows > 7 days are never processed).

### **Recommendation: Option A** ✅

Add `dedupe_processed_at TIMESTAMP NULL` to `resultados_scraping`. Set it inside `DedupeArticulosService::procesar()` unconditionally (whether the row becomes secondary OR stays primary). Safety net queries `WHERE dedupe_processed_at IS NULL`. This exactly mirrors `gemini_analyzed` and is the only approach that is correct at scale.

**Partial index for performance**:
```sql
CREATE INDEX resultados_scraping_dedupe_pending_idx
ON resultados_scraping (fecha_encontrado DESC)
WHERE dedupe_processed_at IS NULL
```
Mirrors the existing `resultados_scraping_pending_idx` for Gemini.

---

## Q4 — Backfill Strategy

**Current state**: 97 rows, all with `dedupe_processed_at = NULL` after the migration runs.

Two sub-questions:
1. Should the safety-net command handle all 97 on first run?
2. Do we need a separate `simo:dedupar-backfill`?

**Analysis**:

The safety-net command (`simo:dedupar-pendientes`) would find 97 pending rows and dispatch 97 `DedupeArticulosJob` instances. Given:
- `ShouldBeUnique` + 5-min lock means no duplicates within one run
- 97 jobs in the `dedupe` queue is trivial
- Each job does one pg_trgm query + optional UPDATE
- No separate backfill command is needed

The safety-net itself IS the backfill mechanism on first run. All 97 historical rows get dispatched, processed, and flagged as processed within minutes.

**Recommendation**: No separate backfill command. The safety-net covers it. Add a `--dry-run` option (following `BackfillZombieResultados` pattern) for ops verification. Add optional `--limit` for throttled runs if needed.

**Concern**: If the system grows to thousands of rows before this fix is deployed, the first run dispatches thousands of jobs simultaneously. But:
- Each job is fast (one indexed pg_trgm query)
- Worker processes them sequentially
- `ShouldBeUnique` prevents accumulation on re-runs
- This is an acceptable one-time cost

---

## Q5 — Worker Config / Deploy Infrastructure

**In repo**: `DEPLOY.md` has a `## Supervisor` section (lines 219-244) with the canonical config block:

```ini
[program:simo-gemini-worker]
command=php /var/www/simo/artisan queue:work --queue=gemini --sleep=3 --tries=3 --max-time=3600
```

**Not in repo**: No `deploy/` folder, no `conf.d/` tracked files. The actual `/etc/supervisor/conf.d/simo-gemini-worker.conf` on the VPS is manually managed.

**Impact of this change**: The `dedupe` queue is currently never consumed by any worker. Fixing requires either:
1. Adding `dedupe` to the existing gemini worker: `--queue=gemini,dedupe`
2. Adding a second `[program:simo-dedupe-worker]` entry

Both approaches must be documented in `DEPLOY.md` AND applied manually on the VPS.

**Decision needed for propose phase**: Which approach (1 or 2)? See Risk section.

---

## Q6 — Risk Areas

### Risk 1: Poisoned row — infinite retry
`DedupeArticulosJob.failed()` only logs. After 3 tries the job goes to `failed_jobs` table. No infinite loop. **Safe.**

However, what counts as "poison"? The `DedupeArticulosService` could throw if:
- DB is unreachable (pg connection error)
- `SET LOCAL pg_trgm.similarity_threshold` fails (e.g., `pg_trgm` not installed)

If `pg_trgm` is not loaded: `DB::statement('SET LOCAL ...')` throws `QueryException`. The job would fail all 3 retries and land in `failed_jobs`. `dedupe_processed_at` would NOT be set (the service throws before reaching that point). The safety net would re-dispatch on next run → same failure → `failed_jobs` again. This is acceptable behavior (fail loud, not silently), but the DBA must ensure `pg_trgm` is enabled.

**pg_trgm check**: Migration `2026_05_09_100003_add_titulo_trgm_index_to_resultados_scraping.php` runs `CREATE INDEX USING GIST (titulo gist_trgm_ops)` — if this migration succeeded, pg_trgm IS enabled. No additional check needed at runtime.

### Risk 2: Worker starvation (gemini vs dedupe queue)
Current worker: `--queue=gemini`. Adding `--queue=gemini,dedupe` means dedupe jobs only run when gemini queue is empty. During Gemini API outages (133 failed_jobs observed in prod), the gemini queue accumulates retries. Dedupe would starve.

**Recommendation**: Separate `[program:simo-dedupe-worker]` process. Minimal overhead (one `php artisan queue:work` process). Gemini gets its own dedicated worker, dedupe gets its own. This is the laravel:queues-and-horizon rule: named queues for independent throttling.

Config to document in `DEPLOY.md`:
```ini
[program:simo-dedupe-worker]
command=php /var/www/simo/artisan queue:work --queue=dedupe --sleep=3 --tries=3 --max-time=3600
```

### Risk 3: `ShouldBeUnique` cache dependency
`ShouldBeUnique` requires an atomic lock. With `CACHE_STORE=database`, uniqueness locks go to the `cache` table. This works correctly for the `database` driver (uses `cache_locks` table). Confirmed: `config/cache.php:18` — default is `database`. **No risk.**

### Risk 4: `withoutOverlapping()` cache dependency
`Schedule::withoutOverlapping()` also uses the cache driver. Same driver, same analysis. **No risk.**

### Risk 5: `onOneServer()` without redis
`Schedule::onOneServer()` requires a cache driver that supports atomic locks. The `database` cache driver DOES support atomic locks (via `cache_locks` table in Laravel 11+). **No risk** for production, which uses `CACHE_STORE=database`.

### Risk 6: SQLite test environment
`DedupeArticulosService::queryCandidates()` (line 102) detects `driver !== 'pgsql'` and returns `[]`. Tests on SQLite will never cluster. Tests for the new command must use `Bus::fake()` / `Queue::fake()` (not real clustering) — exactly what `AnalizarGeminiCommandTest` does. This is the correct pattern.

---

## Q7 — Testing Capabilities

**Test runner**: `php artisan test` (confirmed via `openspec/config.yaml:18`).
**Framework**: PHPUnit 11.5.3.
**Strict TDD**: active (config.yaml:14).

**Existing command test pattern**: `tests/Feature/Gemini/AnalizarGeminiCommandTest.php` (197 lines, 9 tests).
- Uses `$this->artisan('simo:analizar-gemini')` → `assertExitCode(0)`.
- Uses `Queue::fake()` → `Queue::assertPushed(...)` / `Queue::assertNotPushed(...)`.
- Uses `config(['services.gemini.enabled' => false])` for kill switch test.
- Uses `flushEventListeners()` to avoid observer interference during setup.

**No `tests/Feature/Console/` directory** — Gemini command test lives in `tests/Feature/Gemini/`. New test should live at `tests/Feature/Console/DeduparPendientesCommandTest.php` (or mirror pattern at `tests/Feature/Dedupe/DeduparPendientesCommandTest.php`).

**Existing dedupe tests**:
- `tests/Feature/Jobs/Dedupe/DedupeArticulosJobTest.php` — 8 tests, observer + job behavior
- `tests/Feature/Services/Dedupe/DedupeArticulosServiceTest.php` — 7 tests, service logic
- `tests/Unit/Models/ConfigScriptDedupeTest.php` — ConfigScript dedupe helpers

---

## Approaches

### 1. Mirror Gemini exactly — one aggregate job (NOT RECOMMENDED)
Create `DeduparPendientesJob` that fetches N pending rows and calls `DedupeArticulosService::procesar()` in a loop. Safety net dispatches ONE aggregate job.

- **Pros**: Fewer queue items.
- **Cons**: Breaks existing per-row architecture. `DedupeArticulosJob.ShouldBeUnique` is wasted. Transaction/locking model in the service was designed for single-row calls. Requires rewriting job.
- **Effort**: Medium.

### 2. Safety-net dispatches per-row `DedupeArticulosJob` (RECOMMENDED) ✅
New command `simo:dedupar-pendientes` queries `WHERE dedupe_processed_at IS NULL` and dispatches one `DedupeArticulosJob::dispatch($id)` per row.

- **Pros**: Reuses existing tested job and service unchanged. `ShouldBeUnique` provides natural dedup within 5-min window. Per-row isolation (one poisoned row doesn't block others). Clean failure path.
- **Cons**: More queue items on first run (97 now, bounded by growth). Requires `dedupe_processed_at` migration.
- **Effort**: Low.

### 3. Option D (window-based dispatch, no migration)
Query `WHERE secundario_de IS NULL AND fecha_encontrado >= NOW() - 7 * INTERVAL '1 day'`.

- **Pros**: No migration.
- **Cons**: Re-dispatches all within-window primaries every 5 min. Queue grows proportionally with window size × article volume. `ShouldBeUnique` lock expires before next run → re-enqueues same rows. Unacceptable at scale.
- **Effort**: Low (but wrong).

---

## Recommendation

**Implement Option A + Approach 2**:

1. **Migration**: Add `dedupe_processed_at TIMESTAMP NULL` to `resultados_scraping`. Add partial index `WHERE dedupe_processed_at IS NULL`.
2. **Service update**: In `DedupeArticulosService::procesar()`, after the transaction block, set `$locked->update(['dedupe_processed_at' => now()])`. Also set it in the early-exit path (`secundario_de !== null` already) to handle rows pre-existing as secondaries.
3. **New command**: `app/Console/Commands/DeduparPendientes.php` — `simo:dedupar-pendientes`. Checks `config('services.dedupe.enabled')`. Queries `ResultadoScraping::whereNull('dedupe_processed_at')->select('id')`. Dispatches `DedupeArticulosJob::dispatch($id)` per row. Outputs count.
4. **Schedule**: `routes/console.php` — add `Schedule::command('simo:dedupar-pendientes')->everyFiveMinutes()->withoutOverlapping()->onOneServer()`.
5. **Worker**: Add dedicated `[program:simo-dedupe-worker]` to supervisor config. Document in `DEPLOY.md`.
6. **Backfill**: Not needed separately — first run of safety-net processes all 97 historical rows.

---

## Risks (Summary)

| Risk | Severity | Mitigation |
|---|---|---|
| Worker starvation (gemini + dedupe on same process) | High | Separate `simo-dedupe-worker` supervisor process |
| pg_trgm not enabled in some env | Medium | Migration M3 already requires it; failures land in `failed_jobs` (loud, not silent) |
| `dedupe_processed_at` not set on pre-existing secondaries | Medium | Service early-exit path must also set the flag for rows already `secundario_de IS NOT NULL` |
| Queue saturation on first run (97 jobs) | Low | 97 is trivial; grows bounded |
| `ShouldBeUnique` lock expiry = re-enqueue on next 5-min cycle | Low | Mitigated by `dedupe_processed_at` flag (processed rows are invisible to future queries) |
| Supervisor config not tracked in repo | Medium | Must update `DEPLOY.md` and apply manually on VPS; document in tasks |

---

## Open Questions for Orchestrator

1. **Worker separation**: Should `simo-dedupe-worker` be a new separate supervisor process (recommended) or should `dedupe` be added to `simo-gemini-worker`'s `--queue` list? User preference for VPS resource usage?
2. **`dedupe_processed_at` naming**: Consistent with `gemini_analyzed_at`? Alternatives: `dedupe_checked_at`, `dedupe_at`. Current `gemini_analyzed` is bool + `gemini_analyzed_at` is timestamp — should dedupe follow same dual-column (bool + timestamp) or just timestamp (NULL = pending, non-NULL = done)?
3. **Chunk size for safety-net dispatch**: Dispatch all pending or cap at N per run? (Gemini caps at 50/batch internally; dedupe safety-net currently proposed to dispatch all pending per run.)

---

## Ready for Proposal

**Yes** — all concrete questions are answered. The propose phase can produce a full proposal with rollback plan, affected modules, and infrastructure checklist.
