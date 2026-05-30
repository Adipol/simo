# Exploration: ci-foundation

**Change**: `ci-foundation` · **Phase**: explore · **Date**: 2026-05-12
**Scope**: Fase 1 — baseline CI with existing SQLite test suite (Fase 2 pgsql is explicitly out of scope)

---

## Q1. Current Testing Reality

**Test runner**: `composer test` → `@php artisan config:clear --ansi && @php artisan test`
Source: `composer.json` scripts block, line `"test": ["@php artisan config:clear --ansi", "@php artisan test"]`.

**Default DB driver**: SQLite `:memory:` in CI.
- `phpunit.xml:26` → `<env name="DB_CONNECTION" value="sqlite"/>`
- `phpunit.xml:27` → `<env name="DB_DATABASE" value=":memory:"/>`
- `config/database.php:19` → `'default' => env('DB_CONNECTION', 'sqlite')`

**Suite sizes and durations** (measured locally 2026-05-12):
- Feature suite: 13 failed, 1 incomplete, 9 skipped, 459 passed (1057 assertions) — **55.41s**
- Unit suite: 10 failed, 378 passed (745 assertions) — **8.65s**
- Full `php artisan test`: **OOM crash** at 128M PHP memory limit (confirmed — see Q4)
- Total passing (suites run separately): ~837 tests

**No `.env.testing`** file exists — `phpunit.xml` inline `<env>` entries serve that purpose.

---

## Q2. The 23 Baseline Failures — Classified

Running the two suites separately yields **23 failures total** (13 Feature + 10 Unit).
They fall into **4 distinct root causes** — not a broad regression, tightly clustered:

### Cluster A — Stale test (Laravel boilerplate, 1 failure)
- `Tests\Feature\ExampleTest` → `test_the_application_returns_a_successful_response`
  - **Root cause**: Laravel boilerplate test expects `GET /` → 200, but SIMO redirects to `/dashboard` (302 because auth required). Test was never updated when auth was added.
  - **Fix**: 2-line change — assert 302 or remove test.

### Cluster B — Stale boilerplate profile tests (5 failures)
- `Tests\Feature\ProfileTest` (4 tests: profile page, update, email unchanged, delete account, wrong password)
  - **Root cause**: Laravel boilerplate profile tests use Breeze/Fortify routes that may not be active in SIMO's routing config, or the User model factory structure changed.
  - **Fix**: Investigate routes + update or remove tests. Low-priority boilerplate debt.

### Cluster C — Seeder record count changed (2 failures)
- `Tests\Feature\Seeders\EntidadesPublicasBoliviaSeederTest` → `seeder inserts records` + `known entities exist after seeding`
  - **Root cause**: Test asserts `33` records (`tests/Feature/Seeders/EntidadesPublicasBoliviaSeederTest.php:20`), but seeder now inserts `241`. YPFB record lookup also fails (seeder data changed).
  - **Fix**: Update hardcoded count `33` → `241` and fix YPFB lookup in test. Trivial.

### Cluster D — FiltroResultadoDTO schema changed, tests not updated (9 Unit failures)
- `Tests\Unit\Gemini\FiltroResultadoDTOTest` (9 tests)
  - **Root cause**: `FiltroResultadoDTO::fromArray()` was refactored to expect a `personas` array wrapper (`app/Services/Gemini/DTOs/FiltroResultadoDTO.php:21`), but `FiltroResultadoDTOTest` still passes flat data without the `personas` key → throws `GeminiInvalidResponseException`.
  - **Fix**: Update test fixture to wrap data in `['personas' => [...]]` structure. Trivial.

### Cluster E — GeminiPromptBuilder text changed, assertion stale (1 Unit failure)
- `Tests\Unit\Gemini\PromptReglasTest` → `prompt contiene reglas de clasificacion`
  - **Root cause**: Test asserts the string `'REGLAS DE CLASIFICACIÓN'` in the prompt, but the prompt text was restructured. The assertion text no longer matches current `GeminiPromptBuilder::filtroPEP()` output.
  - **Fix**: Update assertion string to match current prompt. Trivial.

### Cluster F — GeminiFiltroNormalizacion: real HTTP call to Gemini API not mocked (5 Feature failures)
- `Tests\Feature\Services\GeminiFiltroNormalizacionTest` (5 tests)
  - **Root cause**: Test makes real HTTP calls to `generativelanguage.googleapis.com` using `key=test-key`. On Windows dev machine, SSL cert verification fails (`cURL error 60`). In CI (Linux), SSL would pass but the API key `test-key` would return 400/403. **The test is fundamentally not mocked.** This is a genuine test bug — Gemini HTTP client must be faked.
  - **Fix**: Use `Http::fake()` in these tests. Medium effort.

### Critical question for propose: block or allow-list?

**Recommendation: fix all 23 before merging the CI PR**, NOT use an allow-list.

Rationale:
- Clusters A, B, C, D, E are all **trivial fixes** (1-5 lines each). Total effort: ~30 min.
- Cluster F (5 tests) needs `Http::fake()` — maybe 1-2 hours but is the RIGHT fix.
- An allow-list (via `--exclude-group`) is technical debt that survives forever. These aren't pgsql-only tests that require infrastructure — they're stale assertions and an unmocked HTTP call.
- Starting with a permanently red CI defeats the purpose. Fix the failures first, land a green CI.

---

## Q3. Hosting and Platform

- **GitHub**: `git remote -v` → `origin https://github.com/Adipol/simo.git` (confirmed)
- **`.github/` directory**: DOES NOT EXIST. Clean slate.
- **Other CI files**: `git ls-files | grep -iE "ci|workflow|jenkinsfile"` → no results. Confirmed zero CI configuration.
- **Repo visibility**: `PRIVATE` (confirmed via `gh repo view --json visibility`).

---

## Q4. The OOM Problem

**Confirmed**: Full `php artisan test` (both suites in one process) crashes with:
```
PHP Fatal error: Allowed memory size of 134217728 bytes exhausted
(tried to allocate 20480 bytes) in vendor/laravel/framework/src/Illuminate/Foundation/Exceptions/Handler.php:378
```

- **Memory limit**: 128M (`phpunit.xml` has no `memoryLimit` attribute; PHP `ini_get('memory_limit')` = `128M`).
- **Trigger**: The crash happens when running both suites together. Running Feature (55s) and Unit (8.6s) **separately** completes without OOM.
- **No workaround in project**: No Makefile, no scripts, no README mention. The user runs suites separately as manual practice.
- **DashboardSourceHealthServiceTest**: Found at `tests/Feature/Services/Dashboard/DashboardSourceHealthServiceTest.php`. Running Dashboard suite alone passes cleanly (56 tests, 1.73s). The OOM is a cumulative memory issue when the full suite loads all test classes at once, not a single file trigger.

**GitHub Actions runners**: 7GB RAM. The OOM at 128M is a LOCAL WINDOWS DEV limit. On GitHub Actions ubuntu-latest runners with 7GB available, PHP will use the OS default (usually 128M or -1). The workflow MUST set `PHPUNIT_MEMORY_LIMIT` or pass `-d memory_limit=512M` to guarantee no OOM in CI.

**CI strategy**: Run Feature and Unit as separate `php artisan test --testsuite=Feature` / `php artisan test --testsuite=Unit` jobs, OR single job with `-d memory_limit=512M`. Either works. Separate jobs give faster parallel feedback.

---

## Q5. Dependencies and Runtime

**PHP version**: `^8.2` (`composer.json` `require.php`). GitHub Actions `ubuntu-latest` + `actions/setup-php@v2` with `php-version: '8.2'` is the standard approach.

**PHP extensions** (no `ext-*` in `composer.json` require block — checked). However:
- `config/database.php` defines a `pgsql` connection → `pdo_pgsql` must be loadable for the app to boot even with SQLite tests.
- `pg_trgm` extension tests are skipped on SQLite (already handled in tests).
- `actions/setup-php` installs `pdo_pgsql` by default on ubuntu-latest. No special configuration needed for Fase 1.
- Recommend explicitly listing: `extensions: mbstring, pdo, pdo_sqlite, pdo_pgsql, curl, openssl` in the workflow.

**Node version**: No `.nvmrc`, no `engines` in `package.json`. Scripts are just `build` and `dev`.

**Frontend build for tests**: Feature tests do NOT require `npm run build`. Confirmed: all 459 passing Feature tests run without compiled assets (SQLite in-memory, no asset pipeline call in test execution). **Skip `npm run build` in Fase 1 CI.**

---

## Q6. CI Trigger Decisions

**Recommended triggers**:
```yaml
on:
  push:
    branches: [main]
  pull_request:
    branches: [main]
  workflow_dispatch:
```

- `pull_request` on `main`: catches regressions before merge ✅
- `push` to `main`: catches anything that snuck through (hotfixes, direct pushes) ✅
- `workflow_dispatch`: manual trigger for debugging ✅
- No schedule in Fase 1 (nightly pgsql is Fase 2 scope)
- No branch pattern filtering — all PRs targeting main should be gated

---

## Q7. Composer Cache

**`vendor/` size**: 85 MB.
**Cache strategy**: `actions/cache@v4` with key `composer-${{ hashFiles('**/composer.lock') }}`. This is the GitHub Actions standard and means cache hit on every subsequent run unless `composer.lock` changes.
**Estimated cold `composer install` time**: 60-120s on GitHub Actions (typical for a 85MB vendor dir). With cache: ~5-10s.

---

## Q8. Secret Needs

**Confirmed: Fase 1 requires ZERO secrets.**
- `phpunit.xml` uses `DB_CONNECTION=sqlite`, `QUEUE_CONNECTION=sync`, `CACHE_STORE=array` — no external services.
- The `GeminiFiltroNormalizacionTest` failure is a test bug (unmocked HTTP) — not a secrets problem. Fix is `Http::fake()` in the test.
- `phpunit.xml:21` sets `APP_ENV=testing` inline.
- The only needed CI env vars: `APP_KEY` (can be generated fresh with `php artisan key:generate` in CI) and `APP_ENV=testing`.
- `DB_DATABASE=:memory:` is already set in `phpunit.xml`.

---

## Q9. Frontend Build

**Not needed for Fase 1.** Feature tests pass without compiled assets (confirmed empirically). Adding `npm run build` to CI would add 1-2 minutes with no benefit for the test suite. Skip entirely.

---

## Q10. Workflow File Naming

**Recommendation**: `.github/workflows/ci.yml`
- `test.yml` is too narrow — CI may eventually include lint or other checks
- `ci.yml` is the clear GitHub Actions convention for a CI pipeline

---

## Approaches

### Approach 1 — Single workflow, single job
```yaml
jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - checkout
      - setup-php (8.2)
      - cache composer
      - composer install
      - php artisan key:generate
      - php artisan test --testsuite=Feature
      - php artisan test --testsuite=Unit
```
- **Pros**: Simplest YAML, easiest to debug, single status check, single log stream
- **Cons**: Feature (55s) + Unit (8.6s) run sequentially; total ~70s. No separation of "install failed" vs "test failed" in status.
- **Effort**: Low

### Approach 2 — Single workflow, two parallel test jobs
```yaml
jobs:
  test-feature:
    runs-on: ubuntu-latest
    # ...php artisan test --testsuite=Feature
  test-unit:
    runs-on: ubuntu-latest
    # ...php artisan test --testsuite=Unit
```
- **Pros**: Feature and Unit run in parallel (~55s wall time vs ~65s sequential). Clear signal: if Unit passes but Feature fails, the issue is Feature-specific. Both jobs independently consume composer cache.
- **Cons**: 2x runner minutes consumed (2 × ~70s = ~140 minutes/month per run vs 70). For a private repo with 2000 min/month free, this matters. At even 10 PRs/day, that's 1400 min/month for tests alone on two jobs.
- **Effort**: Low-Medium

### Approach 3 — Workflow per concern
- Separate `test.yml`, `lint.yml`, etc.
- **Pros**: Maximum separation, each can be independently disabled
- **Cons**: Overkill for a project this size. No linter currently in use (no `.php-cs-fixer.dist.php`, no PHPStan config found).
- **Effort**: Medium

### RECOMMENDATION: Approach 1 — Single job

**Justification**: The repo is private (2000 min/month limit). Feature suite = 55s, Unit = 8.6s. Sequential total is ~70s. This is well within acceptable CI feedback time. The OOM is solved by `-d memory_limit=512M` in the PHP invocation, NOT by separate jobs. Approach 2 saves ~15s wall time but doubles minutes consumed. Not worth it at this project scale.

If the suite grows significantly and feedback time becomes a concern, upgrade to Approach 2 later.

---

## Risks

| Risk | Severity | Mitigation |
|------|----------|-----------|
| 23 baseline failures block CI from day 1 | HIGH | Fix all 23 before landing CI (estimates: ~2-4h total) |
| OOM with default 128M PHP limit | HIGH | Pass `-d memory_limit=512M` in CI workflow step |
| Private repo: 2000 min/month | MEDIUM | Single-job approach. ~70s/run → ~28 runs/day before hitting limit. Realistic for a dev team. |
| `GeminiFiltroNormalizacionTest` makes real HTTP | HIGH | Must add `Http::fake()` before CI goes live — will fail in CI even with valid SSL |
| FiltroResultadoDTO tests: schema drift | MEDIUM | Fix before CI (Cluster D above) |
| Flaky tests: time/locale dependent | LOW | No obvious time-dependent tests found. `QUEUE_CONNECTION=sync` eliminates queue timing. |
| pgsql-only tests skip silently on SQLite | ACCEPTABLE | By design — `markTestSkipped()` already in place. These will graduate to Fase 2 CI. |
| Branch protection not enforced yet | LOW | Out of scope for Fase 1 — document as follow-up |

---

## Out of Scope (Confirmed)

- Postgres service in CI → Fase 2 (`ci-pgsql-tests`)
- Branch protection rules → manual GitHub UI config
- Deployment automation
- Code coverage reporting (Codecov/Coveralls)
- Static analysis (PHPStan/Psalm) — no config exists in project
- Security scanning (CodeQL/Snyk)
- `npm run build` in CI

---

## Pre-flight: Fix 23 Baseline Failures First

Before CI can be green, these files need changes:

| File | Change needed | Effort |
|------|--------------|--------|
| `tests/Feature/ExampleTest.php:17` | `assertStatus(302)` or remove | 2 min |
| `tests/Feature/ProfileTest.php` | Fix routes/model factory or remove boilerplate | 30 min |
| `tests/Feature/Seeders/EntidadesPublicasBoliviaSeederTest.php:20` | `33` → `241`, fix YPFB lookup | 5 min |
| `tests/Unit/Gemini/FiltroResultadoDTOTest.php` | Wrap fixtures in `['personas' => [...]]` | 15 min |
| `tests/Unit/Gemini/PromptReglasTest.php:22` | Update assertion to match current prompt | 5 min |
| `tests/Feature/Services/GeminiFiltroNormalizacionTest.php` | Add `Http::fake()` to all 5 tests | 60-90 min |

Total estimated effort: ~2-3 hours. Should be done as part of this SDD (Task T0 before workflow file creation).

---

## Ready for Proposal

**Yes**, with one prerequisite:

The orchestrator must confirm with the user: **"Fix the 23 failures inside this SDD (as T0), OR create a separate issue and use `--exclude-group` temporarily?"**

Recommendation is fix-first, but the user decides.

---

## Metrics Summary

| Metric | Value |
|--------|-------|
| Test runner | `php artisan test` (via `composer test`) |
| DB driver in tests | SQLite `:memory:` |
| Feature suite: tests passing | 459 |
| Feature suite: failures | 13 |
| Feature suite: skipped | 9 (pgsql-only) |
| Feature suite: incomplete | 1 |
| Feature suite: duration | 55.41s |
| Unit suite: tests passing | 378 |
| Unit suite: failures | 10 |
| Unit suite: duration | 8.65s |
| Total baseline failures | 23 |
| OOM on full suite run | YES (128M local limit; mitigated with 512M in CI) |
| GitHub Actions runner RAM | 7GB (OOM is not a CI problem if memory_limit set) |
| Repo visibility | PRIVATE |
| Existing CI | NONE |
| `.github/` directory | DOES NOT EXIST |
| PHP version required | ^8.2 |
| No `ext-*` in composer.json | TRUE (extensions loaded by default on ubuntu-latest) |
| Frontend build needed for tests | NO |
| Secrets needed in Fase 1 CI | NONE |
| Composer cache (vendor/) | 85MB |
| Recommended workflow approach | Single job, Approach 1 |
| Estimated CI run time | ~70s |
