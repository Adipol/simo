# Tasks: ci-pgsql-tests

**Change**: ci-pgsql-tests
**Phase**: tasks
**Date**: 2026-05-15
**Mode**: hybrid

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~170 (workflow YAML ~50, DEPLOY.md ~15, README.md ~5, openspec/specs delta ~50, change spec already exists) |
| 400-line budget risk | Low |
| Chained PRs recommended | No |
| Single PR | Yes |
| Decision needed before apply | No |

Decision needed before apply: No
Chained PRs recommended: No
Chain strategy: size-exception
400-line budget risk: Low

### Per-file estimate

| File | LOC | Type |
|---|---|---|
| `.github/workflows/test.yml` | ~50 (rename `test`→`test-sqlite` + full `test-pgsql` job) | MOD |
| `DEPLOY.md` | ~15 (new `## CI / Branch Protection` section after line 40) | MOD |
| `README.md` | ~5 (dual-driver note + PHP 8.2→8.5 fix on line 63) | MOD |
| `openspec/specs/ci-pipeline/spec.md` | ~50 (append Fase 2 delta REQs + remove OUT-2) | MOD |
| `openspec/changes/ci-pgsql-tests/spec.md` | 0 (already drafted by sdd-spec — confirm only) | EXISTS |
| **Total** | **~120** | |

### Suggested Work Units

| Unit | Goal | Likely PR | Notes |
|------|------|-----------|-------|
| 1 | All changes (workflow + docs + spec delta) | PR 1 | Single PR; all under 400-line budget |

## Dependency Graph

```
[T1-T4] Workflow core
    │
    ▼
[T5] YAML validation (optional lint)
    │
    ▼
[T6-T8] Documentation (DEPLOY.md, README.md)
    │
    ▼
[T9-T10] Spec delta (openspec)
    │
    ▼
[T11] Local sanity check
    │
    ▼
[T13-T15] PR open + CI verification
    │
    ▼
[T16-T18] Post-merge manual ops (user)
```

## Phase 1: Workflow modification (core change)

- [x] 1.1 In `.github/workflows/test.yml`, rename job key `test:` → `test-sqlite:` and its `name:` field to `test-sqlite`. Add `env: DB_CONNECTION: sqlite` at job level for explicitness. No other step changes.
- [x] 1.2 Add new `test-pgsql:` job with `timeout-minutes: 15` and `env:` block: `DB_CONNECTION: pgsql`, `DB_HOST: 127.0.0.1`, `DB_PORT: 5432`, `DB_DATABASE: testing`, `DB_USERNAME: postgres`, `DB_PASSWORD: postgres`.
- [x] 1.3 Add `services.postgres:` under `test-pgsql` with `image: postgres:17`, matching `POSTGRES_*` env, `ports: ["5432:5432"]`, and `options: --health-cmd pg_isready --health-interval 10s --health-timeout 5s --health-retries 5`.
- [x] 1.4 Add all setup steps to `test-pgsql` in order: `checkout@v4` → `setup-php@v2` (PHP 8.5, ext: `pgsql, pdo_pgsql, mbstring, xml, curl, zip, bcmath, intl, sqlite3`) → composer cache (key: `composer-${{ runner.os }}-${{ hashFiles('**/composer.lock') }}`) → `composer install` → `setup-node@v4` (Node 20) → `npm ci` → `npm run build` → **Enable pg_trgm** (`PGPASSWORD=postgres psql -h 127.0.0.1 -U postgres -d testing -c "CREATE EXTENSION IF NOT EXISTS pg_trgm;"`) → `cp .env.example .env` → `php artisan key:generate` → `php -d memory_limit=512M artisan test` with `env: APP_ENV: testing`.
- [x] 1.5 (Optional) Validate YAML syntax: visual review passed; GH will validate on PR push.

## Phase 2: Documentation

- [x] 2.1 In `DEPLOY.md`, insert new `## CI / Branch Protection` section between line 40 (`---`) and line 42 (`## Variables de entorno`) with the click-path for GH branch protection (Settings → Branches → Edit rule for `main` → add both `test-sqlite` and `test-pgsql` as required checks).
- [x] 2.2 In `README.md` line 63 (the "Continuous Integration" paragraph), append a 2-sentence note: CI now runs the suite twice in parallel — SQLite (fast, local default) and PostgreSQL 17 + pg_trgm (matches production). Both jobs must pass; branch protection blocks merges otherwise. Closes the SQLite-vs-pgsql parity gap (4 production bugs, May 2026).
- [x] 2.3 In `README.md` same paragraph, fix the stale "PHP 8.2" string → "PHP 8.5" to match `test.yml:30` and production VPS reality.

## Phase 3: Spec delta (openspec)

- [x] 3.1 Append `## Fase 2 — pgsql Testing (added 2026-05-15)` section to `openspec/specs/ci-pipeline/spec.md` containing REQ-A1 through REQ-A6 + their scenarios (copy from `openspec/changes/ci-pgsql-tests/spec.md` — already authored).
- [x] 3.2 In `openspec/specs/ci-pipeline/spec.md` Out of Scope table, strike through (or remove) `OUT-2` ("Postgres service container") — delivered by this change.
- [x] 3.3 Confirm `openspec/changes/ci-pgsql-tests/spec.md` exists and contains all 6 REQs / 19 SCNs. No changes needed if present and complete.

## Phase 4: Pre-PR validation

- [x] 4.1 Open `.github/workflows/test.yml` in editor and visually verify: two top-level job keys (`test-sqlite`, `test-pgsql`), `services:` block only on `test-pgsql`, env vars only on `test-pgsql`, pg_trgm step present and ordered before `artisan test`.
- [x] 4.2 Confirm DEPLOY.md section is inserted between `---` (line 40) and `## Variables de entorno` (line 42) — not duplicating existing content.

## Phase 5: PR + verification (post-implementation)

- [x] 5.1 Open PR with title `feat(ci): add PostgreSQL test job (Fase 2 of ci-pipeline)`. Body must reference SCN-A1.1, SCN-A2.2, SCN-A3.3, REQ-A6. → PR #29: https://github.com/Adipol/simo/pull/29
- [~] 5.2 CI run #25922967919: test-sqlite PASSED (886 tests) ✅ — test-pgsql FAILED (11 failures) ❌ per REQ-A6 first-run policy. Failures documented as hotfix candidates below. Do NOT revert this SDD.
- [ ] 5.3 Open hotfix PRs for each failure cluster (see apply-progress engram #967 for full details):
  - Cluster 1 (HIGH): json_extract() → pgsql ->> operator — ProPipelineIntegrationTest, CambiosGeminiTest
  - Cluster 2 (MEDIUM): Test asserts SQLite SQL string for date trunc — DashboardMetricsServiceTest
  - Cluster 3 (MEDIUM): AnalizarCambioConProTest personas JSON extraction on pgsql
  - Cluster 4 (HIGH): SQLSTATE[25P02] aborted transaction cascade in DedupResultadosScrapingTest (invalid '?' categoria fixture)
  - Cluster 5 (LOW): Percentile precision — DashboardHealthServiceLatencyTest
  - Cluster 6 (LOW): pg_trgm similarity threshold — DedupeArticulosServiceTest

## Phase 6: Post-merge ops (manual — user action required)

- [ ] 6.1 **User**: merge the PR to `main`.
- [ ] 6.2 **User**: configure GitHub branch protection → Settings → Branches → Edit rule for `main` → Require status checks → add BOTH `test-sqlite` AND `test-pgsql` → Save. (Follow DEPLOY.md `## CI / Branch Protection` section.)
- [ ] 6.3 **User (smoke test)**: open a scratch PR with a deliberate pgsql-only break (e.g., `$this->markTestFailed('pgsql smoke')` inside a pgsql-guarded test) and confirm `test-pgsql` fails while `test-sqlite` passes — and that the Merge button is blocked.

## Open questions resolved by tasks

| Question | Resolution |
|---|---|
| `until pg_isready` extra step? | NO — service `health-cmd` is sufficient; `psql` extension step doubles as connectivity check |
| Node 20 deprecation (2026-06-02) | OUT OF SCOPE — separate SDD |
| Composer cache key shared vs separate per job? | Shared key (`composer-${{ runner.os }}-${{ hashFiles('**/composer.lock') }}`) — second job benefits from first's population; no write contention |
| `phpunit.xml` force="true"? | NOT needed — workflow `env:` overrides `<env>` defaults (no `force="true"` present, so workflow wins) |

## Out of scope (NOT tasks)

- OUT-A1: Replacing SQLite with pgsql in local development
- OUT-A2: Performance optimization of slow tests
- OUT-A3: Other CI capabilities (linting, coverage, security scanning)
- OUT-A4: Production database changes
- OUT-A5: Test data seeders that vary by driver
- OUT-A6: Refactoring app code for pgsql compatibility (done in PRs #18/#26/#27/#28)
- Node 20 → 24 upgrade (separate SDD, deadline 2026-06-02)
