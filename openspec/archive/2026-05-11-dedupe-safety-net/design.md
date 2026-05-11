# Design: dedupe-safety-net

## 1. Architecture overview

```
Python scraper (scripts/scraper_v2.2/core/database.py:339)
        │  raw INSERT (bypasses Eloquent observer)
        ▼
resultados_scraping row { dedupe_processed_at = NULL, secundario_de = NULL }
        │
        ▼  every 5 min
Schedule → simo:dedupar-pendientes (kill-switch via services.dedupe.enabled)
        │  SELECT id WHERE dedupe_processed_at IS NULL
        ▼
foreach($ids) DedupeArticulosJob::dispatch($id)        → queue=dedupe
        │
        ▼  consumed by simo-dedupe-worker (separate process)
DedupeArticulosJob::handle() → DedupeArticulosService::procesar()
        │  sets dedupe_processed_at = now() in BOTH paths
        ▼
Row stable: dedupe_processed_at NOT NULL → never re-dispatched
```

## 2. Components

| Path | Responsibility | Key API |
|---|---|---|
| `app/Console/Commands/DeduparPendientes.php` (NEW) | Safety-net dispatcher; mirrors `AnalizarGemini` | `handle(): int`, signature `simo:dedupar-pendientes` |
| `app/Services/Dedupe/DedupeArticulosService.php` (MOD) | Add `dedupe_processed_at = now()` in both code paths of `procesar()` | unchanged ctor `(BackingDetector)` |
| `app/Models/ResultadoScraping.php` (MOD) | Expose new column | add `dedupe_processed_at` to `$fillable` and `$casts => 'datetime'` |
| `routes/console.php` (MOD) | Schedule entry every 5 min | place after gemini schedule |
| `config/services.php` (already has block at :38-40) | Kill-switch already wired | no change required |
| DB migration (NEW) | Column + partial index | additive |
| DEPLOY.md (MOD) | New supervisor block | manual ops checklist |

**Discovery**: `config('services.dedupe.enabled')` already exists at `config/services.php:38-40` (env `DEDUPE_ENABLED`, default `true`) — wired during the original dedupe feature. Reuse as-is, no config diff.

## 3. Data model

**Migration**: `2026_05_10_110002_add_dedupe_processed_at_to_resultados_scraping_table.php`

```php
Schema::table('resultados_scraping', function (Blueprint $t) {
    $t->timestamp('dedupe_processed_at')->nullable()->default(null);
});
if (DB::getDriverName() === 'pgsql') {
    DB::statement('CREATE INDEX resultados_scraping_dedupe_pending_idx
                   ON resultados_scraping (id) WHERE dedupe_processed_at IS NULL');
}
// down(): drop index (pgsql only) then column
```

Partial index keeps the safety-net query O(pending), not O(table). Skip on SQLite (test driver).

Model: add `'dedupe_processed_at'` to `$fillable` and `'dedupe_processed_at' => 'datetime'` to `$casts`.

## 4. Service change — `DedupeArticulosService::procesar()`

Two paths must stamp the timestamp:

- **Path A — early-exit at line 45** (article null OR already secondary): set `dedupe_processed_at = now()` BEFORE returning, only when article exists. Skip if `find()` returned null.
- **Path B — post-transaction at line 75**: AFTER the `DB::transaction(...)` block returns, refresh and `update(['dedupe_processed_at' => now()])`. Runs whether the cluster lookup matched or not.

**Decision: stamp OUTSIDE the transaction.** Rationale: the timestamp is a processing receipt, not part of the cluster invariant. Keeping it outside avoids extending the lock window and avoids re-firing the secundario_de update. If the post-tx update fails, the next safety-net cycle re-dispatches — the job is already idempotent (line 63 + service line 58 re-check).

Logging: reuse existing `Log::channel('gemini')` (matches service line 77). A separate `dedupe` channel is unjustified churn and would split related logs.

## 5. Command — `app/Console/Commands/DeduparPendientes.php`

```php
public function handle(): int
{
    if (! config('services.dedupe.enabled', true)) {
        $this->warn('Dedupe está deshabilitado (DEDUPE_ENABLED=false).');
        return self::SUCCESS;
    }

    $ids = ResultadoScraping::query()
        ->whereNull('dedupe_processed_at')
        ->pluck('id');

    $this->line("Dedupe: {$ids->count()} pendientes");

    foreach ($ids as $id) {
        DedupeArticulosJob::dispatch($id); // job sets onQueue('dedupe') in ctor
    }

    $this->info("Dispatched {$ids->count()} dedupe jobs.");
    return self::SUCCESS;
}
```

No chunking at current volume (~97). Threshold: revisit if pending > 10 000 (memory ~80 KB of bigints — still fine; chunk at 50 000).

Always returns `SUCCESS`; dispatch failures are logged by the queue layer and re-attempted on next cycle.

## 6. Schedule — `routes/console.php`

Append after the existing gemini block (lines 17-21):

```php
// Dedupe: dispatch jobs para registros pendientes (safety net para Python)
Schedule::command('simo:dedupar-pendientes')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();
```

## 7. Config

No change. `services.dedupe.enabled` already present at `config/services.php:38-40`. Document `DEDUPE_ENABLED` in DEPLOY.md env section (no `.env.example` in repo).

## 8. Supervisor (DEPLOY.md)

Insert AFTER the `[program:simo-gemini-worker]` block (DEPLOY.md:224-234), before `[program:simo-pep-monitor]`:

```ini
[program:simo-dedupe-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/simo/artisan queue:work --queue=dedupe --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/simo/storage/logs/dedupe-worker.log
```

Ops checklist: copy block → `sudo supervisorctl reread` → `update` → `start simo-dedupe-worker` → verify with `sudo supervisorctl status simo-dedupe-worker` and `ps aux | grep dedupe`.

## 9. Testing strategy

**`tests/Feature/Dedupe/DeduparPendientesCommandTest.php` (NEW)** — uses `RefreshDatabase` + `Queue::fake()`, mirrors `AnalizarGeminiCommandTest`:

| Test | Asserts |
|---|---|
| `it_dispatches_jobs_only_for_rows_with_null_dedupe_processed_at` | rows with timestamp set are NOT dispatched; null rows ARE |
| `it_respects_the_kill_switch_when_dedupe_is_disabled` | `config services.dedupe.enabled false` → `Queue::assertNothingPushed()` + warn output |
| `it_does_not_dispatch_when_no_pending_rows_exist` | empty pending → `assertNothingPushed`, exit 0 |
| `it_logs_the_count_of_dispatched_jobs` | `expectsOutputToContain('Dispatched 3 dedupe jobs.')` |
| `it_dispatches_to_the_dedupe_queue` | `assertPushed(DedupeArticulosJob::class, fn($j) => $j->queue === 'dedupe')` |

**`tests/Feature/Services/Dedupe/DedupeArticulosServiceTest.php` (MOD)** — add three tests:

| Test | Asserts |
|---|---|
| `it_sets_dedupe_processed_at_after_successful_processing` | non-pgsql path: row gets timestamp even with no candidates |
| `it_sets_dedupe_processed_at_even_when_row_is_already_secondary` | pre-set `secundario_de` → early-exit still stamps |
| `it_does_not_stamp_when_article_does_not_exist` | `procesar(99999)` returns silently, no DB error |

**Migration test** (new, in same file or `MigrationTest.php`): `it_adds_dedupe_processed_at_column_with_partial_index` — assert column exists; on pgsql assert `pg_indexes` row for `resultados_scraping_dedupe_pending_idx`.

TDD order: migration → model → service tests → service code → command tests → command code → schedule + DEPLOY.md.

## 10. Rollback plan

| Phase | Action | Effect |
|---|---|---|
| 1 — soft kill | `DEDUPE_ENABLED=false` + `php artisan config:cache` | command no-ops; existing rows untouched |
| 2 — stop worker | `sudo supervisorctl stop simo-dedupe-worker` | jobs queue but don't process; safe to leave pending |
| 3 — full revert | remove schedule entry → `php artisan migrate:rollback --step=1` | additive migration → safe; column drop is non-destructive (no FK) |

## 11. Risks deep-dive

| Risk | Mitigation |
|---|---|
| Worker starvation (133 Gemini failed_jobs proves shared = bad) | Dedicated `simo-dedupe-worker` (decided) |
| Poisoned row throws → infinite re-dispatch | Job has `tries=3` + backoff `[5,25,125]` → lands in `failed_jobs`; `dedupe_processed_at` never stamped → ops sees backlog grow → manual triage |
| pg_trgm missing in non-prod | Service line 102 returns `[]` gracefully → row marked processed without clustering → acceptable |
| Pre-existing 133 Gemini failed_jobs | Different queue (`gemini` vs `dedupe`); document as unrelated in DEPLOY troubleshooting |
| First-run burst (97 jobs) | Trivial — single worker handles in <1 min |
| Backfill needed for old rows | None — migration sets column NULL by default → all 97 historical rows automatically eligible |

## 12. Open questions / deferred to sdd-tasks

- [ ] Column position in table (cosmetic — Postgres appends; defer)
- [ ] Queued-monitoring helper command (separate change if needed)
- [ ] Whether the migration test belongs in a new `tests/Feature/Database/` folder or inline with the service test (defer to tasks per project test convention)
