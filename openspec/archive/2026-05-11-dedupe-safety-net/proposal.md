# Proposal: dedupe-safety-net

**Change**: `dedupe-safety-net` · **Phase**: propose · **Date**: 2026-05-10
**Artifact store**: hybrid (engram `sdd/dedupe-safety-net/proposal` + this file)

---

## Why

`DedupeArticulosJob` is **never dispatched in production**. The Eloquent observer that dispatches it (`app/Observers/ResultadoScrapingObserver.php:13-25`) only fires for ORM inserts, but the Python scraper writes via raw `psycopg2` (`scripts/scraper_v2.2/core/database.py:339,426-431`). Production evidence: **97 rows in `resultados_scraping`, 0 with `secundario_de` set**. The team already solved the same shape of bug for Gemini via `simo:analizar-gemini` (`routes/console.php:17-21`); dedupe needs the equivalent safety net. Concurrent context: 133 Gemini failures in `failed_jobs` from API 503s — proves a shared worker would starve dedupe, hence the dedicated worker decision.

## What Changes

- **Migration** `2026_05_10_110002_add_dedupe_processed_at_to_resultados_scraping_table.php` — adds `dedupe_processed_at TIMESTAMP NULL` + partial index `(fecha_encontrado DESC) WHERE dedupe_processed_at IS NULL`.
- **Service** `app/Services/Dedupe/DedupeArticulosService.php` — set `dedupe_processed_at = now()` in BOTH paths: post-transaction success AND pre-transaction early-exit (`secundario_de !== null`, line 45).
- **Model** `app/Models/ResultadoScraping.php` — add `dedupe_processed_at` to `$fillable` and cast to `datetime`.
- **Command** `app/Console/Commands/DeduparPendientes.php` (NEW) — `simo:dedupar-pendientes`. Kill switch `config('services.dedupe.enabled')`. Queries `whereNull('dedupe_processed_at')`, dispatches one `DedupeArticulosJob` per row.
- **Schedule** `routes/console.php` — `Schedule::command('simo:dedupar-pendientes')->everyFiveMinutes()->withoutOverlapping()->onOneServer()`.
- **Supervisor docs** `DEPLOY.md` § Supervisor — new `[program:simo-dedupe-worker]` block (`--queue=dedupe --tries=3 --max-time=3600`) + ops checklist.
- **Tests** — feature test for command, service tests for both timestamp-setting paths, migration test.

## Capabilities

### New Capabilities
- `dedupe-safety-net`: scheduled command that finds `resultados_scraping` rows where `dedupe_processed_at IS NULL` and dispatches `DedupeArticulosJob` per row, isolated on its own queue and worker, idempotent via flag column + `ShouldBeUnique`.

### Modified Capabilities
- None. (No `openspec/specs/` exists yet — this is the first formal capability spec.)

## Out of Scope

- Changing the dedup similarity algorithm or threshold (0.90) or window (7 days) — they work.
- Installing Horizon.
- Auto-applying the supervisor config on the VPS — manual ops step, documented in `DEPLOY.md`.
- Separate one-shot backfill command — the safety net IS the backfill (97 rows go through naturally on first run).
- Per-run dispatch cap (`--limit`) — dispatch is cheap DB inserts; add later if volume grows 100×.
- Retrying the 133 pre-existing Gemini `failed_jobs` — unrelated.

## Approach

Mirror the proven `simo:analizar-gemini` pattern but **per-row** instead of aggregate, because `DedupeArticulosJob` is already designed per-row (`ShouldBeUnique` + 300s lock keyed by `resultadoId`). Idempotency is layered: (1) `dedupe_processed_at` flag eliminates re-dispatch across schedule runs, (2) `ShouldBeUnique` absorbs intra-window double-dispatch, (3) service `lockForUpdate` + re-check inside transaction prevents row-level races. Worker isolation via dedicated `simo-dedupe-worker` supervisor process protects against Gemini API saturation starving the dedupe queue.

## Affected Areas

| Area | Impact | Description |
|---|---|---|
| `database/migrations/2026_05_10_110002_add_dedupe_processed_at_to_resultados_scraping_table.php` | New | Column + partial index |
| `app/Services/Dedupe/DedupeArticulosService.php` | Modified | Set `dedupe_processed_at` in both success and early-exit paths |
| `app/Models/ResultadoScraping.php` | Modified | Fillable + datetime cast |
| `app/Console/Commands/DeduparPendientes.php` | New | Safety-net dispatcher command |
| `routes/console.php` | Modified | New schedule entry (every 5 min) |
| `config/services.php` | Modified | Add `dedupe.enabled` kill switch |
| `DEPLOY.md` | Modified | New supervisor block + ops checklist |
| `tests/Feature/Console/DeduparPendientesCommandTest.php` | New | Mirror `AnalizarGeminiCommandTest` |
| `tests/Feature/Services/Dedupe/DedupeArticulosServiceTest.php` | Modified | Cover both timestamp-setting paths |

## Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| Worker starvation if shared with `gemini` queue | High | Dedicated `simo-dedupe-worker` supervisor process |
| `dedupe_processed_at` not set on early-exit (already-secondary) rows → infinite re-dispatch | Medium | Service-level test covers BOTH code paths; explicit assertion |
| Supervisor not in repo → ops forgets to deploy worker | Medium | `DEPLOY.md` is source of truth; tasks include explicit ops checklist; PR description must reference it |
| First-run dispatches 97 jobs simultaneously | Low | Trivial volume; bounded growth; per-row indexed pg_trgm query is fast |
| Pre-existing 133 Gemini `failed_jobs` confused with new dedupe failures | Low | Different queue (`dedupe` vs `gemini`); document in DEPLOY.md ops note |
| `pg_trgm` extension missing in non-prod env | Low | Migration `2026_05_09_100003` already fails loudly if absent; failures land in `failed_jobs`, not silent |

## Rollback Plan

1. Disable: `php artisan tinker --execute="config(['services.dedupe.enabled' => false]);"` or set `DEDUPE_ENABLED=false` in `.env` and `php artisan config:cache`. Schedule still runs but command exits early (zero dispatches).
2. Stop worker: `sudo supervisorctl stop simo-dedupe-worker`.
3. Optional revert: `php artisan migrate:rollback --step=1` (drops `dedupe_processed_at`). Safe — column is additive, no data loss for existing functionality.
4. Pre-existing dispatched jobs drain naturally (`tries=3`, then `failed_jobs`).

## Dependencies

- PostgreSQL `pg_trgm` extension (already required by migration `2026_05_09_100001`).
- Laravel cache driver supporting atomic locks (`database` driver — already in use, `config/cache.php:18`).
- Manual VPS ops step: install supervisor block from `DEPLOY.md`.

## Estimated Size

~250-350 LOC across implementation + tests:
- Migration: ~30 LOC
- Service change: ~6 LOC (2 update calls)
- Model change: ~4 LOC
- Command: ~50 LOC
- Schedule + config: ~10 LOC
- Tests: ~150-200 LOC (command feature + service path coverage + migration)
- DEPLOY.md: ~25 LOC

Single PR is feasible. No chained-PR split needed.

## Verification Plan

- **Unit/Feature**: `tests/Feature/Console/DeduparPendientesCommandTest.php` mirrors `AnalizarGeminiCommandTest.php` (197 LOC, 9 tests). Uses `Queue::fake()` + `assertPushed`/`assertNotPushed`. Covers: kill switch, no pending → no dispatch, N pending → N dispatches, idempotent flag prevents re-dispatch.
- **Service**: extend `DedupeArticulosServiceTest.php` to assert `dedupe_processed_at` is set in BOTH success-path AND early-exit-path.
- **Migration**: assert column exists, nullable, and partial index present (PostgreSQL-only; skip on SQLite).
- **Manual smoke**: on staging, `php artisan simo:dedupar-pendientes`, then verify `failed_jobs` stays empty and `dedupe_processed_at` populates for processed rows.

## Success Criteria

- [ ] After deploy, `SELECT COUNT(*) FROM resultados_scraping WHERE dedupe_processed_at IS NULL` trends to 0 within one schedule cycle.
- [ ] Zero new entries in `failed_jobs` for `DedupeArticulosJob` post-deploy (excluding pre-existing Gemini failures).
- [ ] At least one row gets `secundario_de` populated (proves end-to-end pipeline works).
- [ ] `simo-dedupe-worker` shows in `sudo supervisorctl status` as `RUNNING`.
- [ ] All new tests pass; existing dedupe/gemini tests unaffected.
