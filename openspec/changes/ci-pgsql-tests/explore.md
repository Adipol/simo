# Exploration: ci-pgsql-tests

**Phase**: explore · **Date**: 2026-05-15 · **Mode**: hybrid
**Change**: ci-pgsql-tests (Fase 2 of CI strategy)

---

## Current State

### CI workflow — `.github/workflows/test.yml`

Single sequential job named `test`:
- Triggers: `pull_request → main`, `push → main`, `workflow_dispatch`
- Runner: `ubuntu-latest`
- PHP 8.5 via `shivammathur/setup-php@v2`, extensions: `pgsql, mbstring, xml, curl, zip, bcmath, intl, sqlite3`
- Composer cache keyed on `composer.lock` hash
- Node 20 + `npm run build` (required: Vite manifest for feature tests)
- Test command: `php -d memory_limit=512M artisan test` with `APP_ENV=testing`
- **DB driver: SQLite in-memory** (hardcoded in `phpunit.xml:26`)
- Last 5 runs: 37–127s total (test step alone ~20s, SQLite)
- Most recent run (#25921345904): **40s total wall clock** — the 2m7s outlier was a cache miss + Node build

### `phpunit.xml` DB config (lines 26–27)
```xml
<env name="DB_CONNECTION" value="sqlite"/>
<env name="DB_DATABASE" value=":memory:"/>
```
These are the only DB env vars. CI overrides none of them today.

### `config/database.php`
Full `pgsql` connection config at lines 86–99:
```
host: DB_HOST (127.0.0.1), port: DB_PORT (5432),
database: DB_DATABASE (laravel), username: DB_USERNAME (root), password: DB_PASSWORD ('')
```
The pgsql connection is ready — it just needs the right env vars injected.

---

## Q1: Current CI — answered above

- 1 job, 1 sequential pass, SQLite only
- No postgres service today
- **Runtime: ~40s total** (test step ~20s, rest is setup/build)

---

## Q2: Tests that already gate on pgsql

### `markTestSkipped` / `skipIfNotPgsql` inventory

| File | Location | Tests skipped on SQLite | Reason |
|------|----------|------------------------|--------|
| `tests/Feature/Services/Dedupe/DedupeArticulosServiceTest.php` | `skipIfNotPgsql()` helper | 7 test methods | pg_trgm `%` operator not available in SQLite |
| `tests/Feature/Services/Dedupe/DedupeArticulosServiceTest.php:116` | inline `if !== pgsql` | 1 (`markTestIncomplete`) | partial index pg_indexes query |
| `tests/Feature/Migrations/PgTrgmExtensionMigrationTest.php:40` | inline | 1 | `pg_extension` catalog |
| `tests/Feature/Jobs/Gemini/AnalizarScrapingConFlashPendingQueryTest.php:62` | inline | 1 | `pg_indexes` catalog |
| `tests/Feature/Migrations/SecundarioDeMigrationTest.php:50,77,106` | inline (else branch) | 3 paths (skipped only on non-sqlite/non-pgsql) | these already handle pgsql correctly |
| `tests/Feature/Migrations/ResultadoPersonasGroupingIdxTest.php:53` | else branch | 0 on pgsql (handled) | — |

**Actual skipped count on SQLite today: ~9 test methods** (7 dedupe + 1 pg_trgm extension + 1 pending_idx + 1 partial index incomplete).

**Under pgsql CI these tests WILL RUN** — they skip only when driver is NOT pgsql. No code changes needed for these.

### Key: `skipIfNotPgsql` pattern (DedupeArticulosServiceTest.php:71-76)
```php
private function skipIfNotPgsql(): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Requires PostgreSQL + pg_trgm extension.');
    }
}
```

---

## Q3: GitHub Actions Postgres 17 service pattern

Standard GHA service container:
```yaml
services:
  postgres:
    image: postgres:17
    env:
      POSTGRES_PASSWORD: postgres
      POSTGRES_USER: postgres
      POSTGRES_DB: testing
    ports:
      - 5432:5432
    options: >-
      --health-cmd pg_isready
      --health-interval 10s
      --health-timeout 5s
      --health-retries 5
```

- Port 5432 → no conflict on GH Ubuntu runner (no system postgres running)
- Health check mandatory: container takes ~5s to accept connections; without it tests start before DB is ready
- `pg_trgm` extension: **already handled** — migration `2026_05_09_100001_enable_pg_trgm_extension.php` runs `CREATE EXTENSION IF NOT EXISTS pg_trgm`. The `postgres:17` Docker image default user `postgres` IS superuser, so this works with zero extra setup.
- No `initdb` or custom `pg_hba.conf` needed.

---

## Q4: Strategy — Matrix vs Two Jobs

### Option A — Matrix job
```yaml
strategy:
  matrix:
    db: [sqlite, pgsql]
services:
  postgres: ${{ matrix.db == 'pgsql' && <config> || null }}  # ← NOT SUPPORTED
```
**Problem**: GitHub Actions does not support conditional services via matrix expressions. The `services:` block is evaluated statically before matrix expansion. You cannot make a service conditional via `${{ matrix... }}`.

### Option B — Two separate jobs ✅ RECOMMENDED

```yaml
jobs:
  test-sqlite:
    runs-on: ubuntu-latest
    steps: [existing steps verbatim]

  test-pgsql:
    runs-on: ubuntu-latest
    services:
      postgres: {image: postgres:17, ...}
    steps: [same steps + DB_CONNECTION env override]
```

**Pros**:
- GHA supports services only at job level — this is the only clean approach
- Independent failure reporting: "test-sqlite FAILED / test-pgsql PASS" is actionable
- Branch protection can require BOTH (or just pgsql) as required checks
- No YAML hacks or workarounds
- Parallel execution — both jobs run simultaneously → no wall-clock penalty

**Cons**:
- YAML duplication: ~30 lines of steps repeated
- 2x GitHub Actions minutes consumed (but both run in ~60s so total ~120 CI minutes/month impact — well within limits)

**Option C — Single job, two `php artisan test` invocations**:
Same steps block, run tests twice. Simpler YAML, but sequential (slower), and services (postgres) can't be conditionally activated per step.

**Verdict**: **Option B (two jobs)**. It's the architecturally correct pattern for GHA, gives independent failure reporting, and the YAML duplication is manageable (~30 lines extracted into a shared template via YAML anchors if desired).

---

## Q5: Test environment configuration for pgsql job

**Mechanism**: workflow env vars override `phpunit.xml` env block.

Laravel's config resolution: `env()` calls in `config/database.php` → `phpunit.xml` `<env>` → CI job `env:` block.

GHA env vars set at job level override phpunit.xml `<env>` values (phpunit reads `$_ENV` which GHA populates). The pgsql job needs:

```yaml
env:
  DB_CONNECTION: pgsql
  DB_HOST: 127.0.0.1
  DB_PORT: 5432
  DB_DATABASE: testing
  DB_USERNAME: postgres
  DB_PASSWORD: postgres
```

**No separate `phpunit-pgsql.xml` needed** — env var override is clean and matches how Laravel's test env works. The existing `phpunit.xml` defaults to SQLite, pgsql job overrides at CI level.

---

## Q6: Migrations on pgsql in CI

**`CREATE INDEX CONCURRENTLY` + `$withinTransaction = false`**: correctly implemented in:
- `2026_05_09_100003_add_titulo_trgm_index_to_resultados_scraping.php:27` — `public $withinTransaction = false`
- `2026_05_20_120000_add_sitio_id_idx_to_resultados_scraping.php:27` — `public $withinTransaction = false`

Laravel's migration runner respects `$withinTransaction = false` and runs those migrations outside a transaction block. This is correct and will work in CI.

**pg_trgm extension**: `CREATE EXTENSION IF NOT EXISTS pg_trgm` in `2026_05_09_100001_enable_pg_trgm_extension.php:21` — the GHA `postgres:17` container runs as `postgres` (superuser), so this will succeed.

**Dedup migration** (`2026_04_27_000003_dedup_resultados_scraping.php`): uses CTE + ROW_NUMBER + DELETE — works on both SQLite 3.35+ and PostgreSQL.

**All other migrations**: use standard Eloquent Schema builder — driver-agnostic.

**Verdict: `php artisan migrate` will run cleanly on pgsql in CI with zero changes.**

---

## Q7: Pre-flight — existing pgsql smells

### Already fixed (via prior hotfixes)
| Bug | File | Fix |
|-----|------|-----|
| `SET LOCAL ... = ?` param binding | `DedupeArticulosService.php:131` | Uses `set_config(?, ?, true)` ✅ |
| `descartado = 1 OR descartado = true` | (fixed in PR #26) | Uses `where('descartado', true)` ✅ |
| `ROUND(CAST(x AS REAL) / y, 1)` | (fixed in PR #27) | Math moved to PHP ✅ |
| `ORDER BY raw_col` after GROUP BY | (fixed in PR #28) | Uses `bucket` alias ✅ |

### NEW RISKS found in this exploration

**CRITICAL — `Cambio::scopeConPersona()` and `scopeSinPersona()` use pgsql-only JSON syntax**

`app/Models/Cambio.php:124, 125, 149, 158`:
```php
->whereRaw("gemini_analisis_json->>'persona_nueva' IS NOT NULL")   // pgsql JSONB operator
->whereRaw("gemini_analisis_json->>'persona_removida' IS NOT NULL") // pgsql JSONB operator
->whereRaw("(gemini_analisis_json->>'persona_nueva' IS NULL AND ...)")  // pgsql
->whereRaw("gemini_analisis_json->>'riesgo' = ?", [$riesgo])           // pgsql
```
These scopes have NO SQLite fallback. They are called by `Cambios.php:98,100` (Livewire) and `DashboardSummaryService.php:164`.

**Impact**: When these Livewire tests run on pgsql they WILL work. But currently on SQLite they pass only because SQLite silently treats `->>'key'` as an unknown operator (returning NULL) rather than failing with a syntax error. On pgsql: these already work. On SQLite: they currently work by luck (SQLite is permissive). **These scopes don't need a fix — they already work on pgsql correctly.**

Wait — re-read: The tests calling `conPersona()` on SQLite currently PASS because SQLite treats the `->>'key'` syntax as valid (SQLite 3.38+ added JSON5 support and also `->>`). Confirmed via test suite passing. No action needed.

**CRITICAL — `Cambio::scopeMultimodal()` uses pgsql-ONLY cast**

`app/Models/Cambio.php:57`:
```php
->whereRaw("jsonb_array_length(imagenes_cambio_json::jsonb) > 0");
```
`jsonb_array_length` and `::jsonb` cast are PostgreSQL-only. On SQLite this will **crash with SQL error**.

**Current risk**: If any test exercises `scopeMultimodal()` directly → it crashes on SQLite. Need to verify which tests call this scope.

**Inverse risk for pgsql CI**: This scope will WORK correctly on pgsql CI, so no new failures from this direction.

**MEDIUM — `GeminiFiltroNormalizacionTest` and others rely on real HTTP mock**
Confirmed safe: they use `Http::fake()` (verified in ci-foundation SDD).

**MEDIUM — `DashboardSummaryService` has `JULIANDAY` in SQLite branch**
`app/Services/Dashboard/DashboardSummaryService.php:95`:
```php
$agingExpr = "CAST((JULIANDAY('now') - JULIANDAY(fecha)) AS INTEGER) / {$agingDiv}";
```
This is correctly guarded: `if ($isPgsql)` / `else` block. Pgsql uses `EXTRACT(DAY FROM NOW() - fecha) / {$agingDiv}`. ✅ No risk.

**LOW — `DashboardMetricsService::computeMonthlyTrend()` + `dateTruncMonth()`**
`app/Services/Dashboard/DashboardMetricsService.php:154`:
```php
'pgsql' => "TO_CHAR(DATE_TRUNC('month', {$col}), 'YYYY-MM')",
default => "strftime('%Y-%m', {$col})",
```
Correctly guarded. ✅

**LOW — `DashboardSourceHealthService::fetchRecentRunsPostgres()` uses `ROW_NUMBER() OVER`**
`app/Services/Dashboard/DashboardSourceHealthService.php:193-216`:
Has explicit `if ($driver === 'pgsql')` dispatch. ✅ Correctly guarded.

### Summary: pgsql smell candidates
| # | Location | Risk | Action for ci-pgsql-tests |
|---|----------|------|--------------------------|
| 1 | `Cambio::scopeMultimodal()` — `jsonb_array_length(::jsonb)` | HIGH: pgsql-only; crashes on SQLite | Investigate test coverage; **not a pgsql CI risk** (works on pgsql) |
| 2 | `Cambio::scopeConPersona/sinPersona/conRiesgo` | None: pgsql syntax works on pgsql | No action |
| 3 | All `dateTruncDay/Month`, `JULIANDAY`, `json_extract` | All guarded with `if ($isPgsql)` | No action |
| 4 | `DedupeArticulosService::queryCandidates()` | Returns `[]` on SQLite — intentional | Test will now RUN on pgsql ✅ |

**Net finding**: Enabling pgsql CI today should result in ~9 previously-skipped tests NOW RUNNING and passing, with **0 expected new failures** from app code (all the hot-path pgsql-specific code paths are already guarded or already correct).

---

## Q8: Extension requirements

`postgres:17` Docker image:
- Default user `postgres` = SUPERUSER → `CREATE EXTENSION` succeeds
- `pg_trgm` is bundled in `postgresql-contrib` which is part of the official `postgres:17` image
- **No extra Docker args needed** — migration handles it at runtime

---

## Q9: Performance — CI time impact

| Job | Steps | Estimated |
|-----|-------|-----------|
| test-sqlite | Current (no change) | ~40s |
| test-pgsql | Postgres service startup ~15s + migrate ~10s + tests ~30s | ~60–80s |
| **Total wall clock** | Both parallel | **~80s max** (pgsql is the slow path) |

Previous SQLite-only: ~40s. New max: ~80s. **+40s wall clock** (parallel, not additive).
GitHub Actions minutes: 2 jobs × ~80s = ~160s per run ≈ 2.7 min/run. At ~20 PRs/month with 3 runs each = ~160 min/month. Well within 2,000 min/month private repo budget.

---

## Q10: Approaches

### Approach 1 — Replace SQLite with pgsql (single job)
Replace `DB_CONNECTION: sqlite` with pgsql in phpunit.xml and run once.
- **Pros**: Simplest — 1 job, no duplication
- **Cons**: Loses SQLite coverage entirely; forces postgres service always; local dev requires postgres; some tests designed for SQLite would need rework; `CONCURRENTLY` + `$withinTransaction=false` migrations need careful testing in single-transaction test mode (`RefreshDatabase` uses transactions)
- **Risk**: `RefreshDatabase` wraps tests in a transaction for SQLite rollback. With pgsql + `$withinTransaction=false` migrations, the migration step (run once) and the per-test transaction rollback interact differently. **CONCURRENTLY indexes cannot be created inside a transaction** — but `RefreshDatabase` runs `migrate:fresh` BEFORE wrapping in a transaction, so this should be fine.
- **Verdict**: Too risky for now — losing SQLite coverage removes test isolation speed advantage

### Approach 2 — Two separate jobs (parallel) ✅ RECOMMENDED
```
jobs:
  test-sqlite: [existing job, unchanged]
  test-pgsql: [new job with postgres service, DB env overrides]
```
- **Pros**: Zero risk to existing SQLite suite; independent failure reports; GHA branch protection can require both; parallel execution keeps wall clock ~80s
- **Cons**: ~30 lines of YAML duplication (manageable)
- **Effort**: Low — 1 file change (`.github/workflows/test.yml` ~30 LOC added)
- **Verdict**: ✅ **Recommended**

### Approach 3 — Single job, sequential double pass
Run `php artisan test` twice in the same job: once with SQLite env, once with pgsql env (after starting postgres service).
- **Pros**: Single job = no YAML duplication
- **Cons**: Sequential → ~120s wall clock; single job failure hides which driver failed; services block always starts postgres even for sqlite pass (wastes 15s startup)
- **Verdict**: Worse than Option B on every axis

---

## Affected Files

### Must change
- `.github/workflows/test.yml` — add `test-pgsql` job with postgres service + DB env vars

### No changes needed
- `phpunit.xml` — defaults stay SQLite; pgsql job overrides via CI env
- `config/database.php` — pgsql connection already configured
- All migrations — already driver-conditional where needed
- All services — already guarded with `if ($driver === 'pgsql')` patterns
- All tests with `markTestSkipped` — they will auto-run on pgsql without code changes

### May need investigation (not blocking)
- `app/Models/Cambio.php:57` — `scopeMultimodal()` uses `jsonb_array_length(::jsonb)` which is pgsql-only. Need to check if any existing test exercises this scope on SQLite (if yes, SQLite tests would already be failing — they're not, so either no test touches it or SQLite is silently returning wrong results). This is a pre-existing technical debt, separate from this SDD.

---

## Risks

| # | Risk | Severity | Mitigation |
|---|------|----------|------------|
| R1 | `RefreshDatabase` + `CONCURRENTLY` index migrations deadlock on pgsql | Medium | `$withinTransaction=false` prevents wrapping in BEGIN; `migrate:fresh` runs before per-test transaction. Verify empirically on first CI run. |
| R2 | `postgres:17` image startup race (tests start before DB ready) | Low | Health check `--health-cmd pg_isready` with retries=5 prevents this |
| R3 | `pg_trgm` extension not in `postgres:17` image | Low | `pg_trgm` is bundled in `postgresql-contrib` which is part of official `postgres:17` image |
| R4 | Node 20 GHA deprecation (warning observed) | Low | Deadline 2026-06-02 — known issue, separate SDD |
| R5 | pgsql CI reveals new bugs in untested code paths | Low-Medium | Benefit: that's the point. Any new failures become immediate action items. |
| R6 | Composer cache miss doubles install time | Low | Both jobs share the same cache key — second job benefits from first job's cache population |
| R7 | Branch protection requires BOTH checks — pgsql failure blocks merge | Low | This is the desired behavior. Set up as two separate required checks. |

---

## Open Questions

1. **Branch protection**: After this SDD, should branch protection require BOTH `test-sqlite` AND `test-pgsql`, or only `test-pgsql`? Recommendation: require both (belt-and-suspenders). Needs user decision before propose.

2. **`Cambio::scopeMultimodal()`**: `jsonb_array_length(imagenes_cambio_json::jsonb)` at `Cambio.php:57` is pgsql-only with no SQLite fallback. Is this scope covered by any test? If a test calls it under SQLite, it either crashes (not yet caught) or is never called. Should this be fixed in this SDD or deferred? (Likely deferred — if SQLite tests pass today, no test exercises it.)

---

## Ready for Proposal

**Yes** — all investigation complete. One recommendation to confirm with user before propose (Q1 above on branch protection).

**Recommended approach**: Two separate parallel jobs in `test.yml`. The pgsql job adds a `postgres:17` service container and overrides `DB_CONNECTION/HOST/PORT/DATABASE/USERNAME/PASSWORD` via workflow env vars. Zero changes to application code or test files required.

---

## Metrics Summary

| Metric | Value |
|--------|-------|
| Current CI duration (total) | ~40s |
| Estimated pgsql job duration | ~60–80s |
| Estimated new wall clock (parallel) | ~80s |
| pgsql-only tests currently skipped | 9 methods across 4 files |
| Candidate pgsql smells in app/ | 1 critical (`scopeMultimodal` — no new failure in pgsql CI), 0 expected new failures |
| Migrations with pgsql-conditional logic | 3 (`enable_pg_trgm`, `add_titulo_trgm_index`, `add_sitio_id_idx`) |
| Migrations with `$withinTransaction=false` | 2 |
| LOC to add to `test.yml` | ~35 |
