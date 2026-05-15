# Design: ci-pgsql-tests (Fase 2)

**Change**: `ci-pgsql-tests` · **Phase**: design · **Date**: 2026-05-15

## Technical Approach

Split the single `test` job in `.github/workflows/test.yml` into two parallel jobs sharing all setup but differing in DB driver: `test-sqlite` (renamed identity, current behavior) and `test-pgsql` (new, `postgres:17` service container, job-level `env:` overriding `DB_CONNECTION=pgsql`). Same checkout, same PHP setup, same Composer/Node steps, same `php artisan test` command. Branch protection (manual GH UI) requires BOTH green.

Implements the Fase 2 delta on `openspec/specs/ci-pipeline/spec.md` and lifts `OUT-2`.

## Architecture

```
PR→main / push→main / workflow_dispatch
        │
        ├──► test-sqlite (renamed from `test`, unchanged behavior)
        │      checkout → setup-php 8.5 → composer cache → npm build
        │      → cp .env.example .env → key:generate → php artisan test
        │      (DB_CONNECTION inherits sqlite from phpunit.xml)
        │
        └──► test-pgsql (NEW, parallel)
               services.postgres: postgres:17 + pg_isready health
               job env: DB_CONNECTION=pgsql + DB_HOST/PORT/DB/USER/PASS
               same setup steps → wait pg_isready → psql CREATE EXTENSION
               → cp .env.example .env → key:generate → php artisan test
```

Wall-clock unchanged (~80s max, both run in parallel). Public repo → unlimited Actions minutes.

## Architecture Decisions

| Decision | Choice | Alternatives | Rationale |
|---|---|---|---|
| Job topology | Two separate jobs | `matrix.driver` with conditional `services` | GHA does NOT support conditional `services:` per matrix variant (verified explore §Q4). Two jobs is the architecturally correct pattern, not a hack. |
| Env vars location | **Job-level** `env:` | Step-level `env:` on each step | Cleaner, single source of truth per job, overrides `phpunit.xml` `<env>` defaults (PHPUnit only applies `<env>` if `force="true"`; ours is not, so workflow env wins). |
| pgsql extension | Explicit `psql -c "CREATE EXTENSION IF NOT EXISTS pg_trgm"` step BEFORE `artisan test` | Rely on migration `2026_05_09_100001_enable_pg_trgm_extension.php` alone | Defense in depth. The migration already does this, but a pre-flight `psql` step (a) verifies DB reachability beyond the service health check, (b) gives a crisp early failure if `postgres-contrib` is ever missing, (c) costs <1s. Idempotent (`IF NOT EXISTS`). |
| Postgres image | `postgres:17` | `postgres:17-alpine` | Matches production VPS (`postgres:17` Debian-based) and bundles `postgresql-contrib` (which ships `pg_trgm`). Alpine is smaller but a different libc; not worth the deviation. |
| Health check | Service `options.health-cmd pg_isready` + retries=5 | Bash `until pg_isready` loop in a step | GHA service `health-cmd` blocks the job's first step until healthy. That's the canonical pattern; no extra step needed. The `psql` extension step doubles as a connectivity smoke test. |
| Concurrency | Existing group unchanged | Per-job groups | Both jobs share `tests-${{ github.workflow }}-${{ github.ref }}` so a PR sync cancels BOTH stale runs together. Correct. |
| Local dev | No `.env`, no docker-compose, no script changes | Add `pgsql` to dev `.env` | Out of scope per FROZEN decision; SQLite stays the dev default for fast iteration. |

## File Changes

| File | Action | Description |
|---|---|---|
| `.github/workflows/test.yml` | Modify | Rename `test` → `test-sqlite`; add new `test-pgsql` job with `services.postgres` + job env (~35 LOC) |
| `openspec/specs/ci-pipeline/spec.md` | Modify | Append Fase 2 delta REQ (parallel pgsql validation); remove `OUT-2` from Out of Scope table |
| `DEPLOY.md` | Modify | Add `## CI / Branch Protection` section under "Workflow de actualización" with click-path for required status checks |
| `README.md` | Modify | Append 2-sentence dual-driver note to existing "Continuous Integration" section (line 61-63). Fix lingering "PHP 8.2" → "PHP 8.5" in same section. |
| `phpunit.xml` | **NOT touched** | `<env>` defaults without `force="true"` are overridden by workflow `env:` automatically |
| Application code, migrations, configs | **NOT touched** | All pgsql-conditional code already guarded; 9 `markTestSkipped` tests auto-run |

## Workflow YAML structure

Two top-level `jobs:` keys. `test-sqlite` is the current `test` job verbatim with the new name. `test-pgsql` adds:

- `timeout-minutes: 15` (vs 10 — pgsql migrations slower than `:memory:`)
- `env:` block with `DB_CONNECTION=pgsql, DB_HOST=127.0.0.1, DB_PORT=5432, DB_DATABASE=testing, DB_USERNAME=postgres, DB_PASSWORD=postgres`
- `services.postgres:` with `image: postgres:17`, matching `POSTGRES_*` env, `ports: 5432:5432`, `options: --health-cmd pg_isready --health-interval 10s --health-timeout 5s --health-retries 5`
- Extra step "Enable pg_trgm extension" after composer/npm, before `artisan test`: `PGPASSWORD=postgres psql -h 127.0.0.1 -U postgres -d testing -c "CREATE EXTENSION IF NOT EXISTS pg_trgm;"`

## Step ordering (test-pgsql)

1. `actions/checkout@v4`
2. `shivammathur/setup-php@v2` (PHP 8.5, extensions: `pgsql, pdo_pgsql, mbstring, xml, curl, zip, bcmath, intl, sqlite3`)
3. Composer cache key (shared with `test-sqlite` via `composer-${{ runner.os }}-${{ hashFiles('**/composer.lock') }}`)
4. `composer install --prefer-dist --no-progress --no-interaction`
5. `actions/setup-node@v4` (Node 20) — needed for `@vite` directive in layouts (see comment in current `test.yml:46-49`)
6. `npm ci --prefer-offline --no-audit`
7. `npm run build`
8. **Enable pg_trgm** (psql one-liner)
9. `cp .env.example .env`
10. `php artisan key:generate`
11. `php -d memory_limit=512M artisan test` with `APP_ENV: testing`

## Spec delta integration

`openspec/specs/ci-pipeline/spec.md` updates:
- Append section `## Fase 2 — pgsql Testing (added 2026-05-15)` with new REQ "Workflow runs full suite against PostgreSQL 17 + pg_trgm in parallel job"
- Delete row `OUT-2` from the Out of Scope table
- `sdd-spec` phase produces the exact diff (delta artifact). This design only commits to the integration shape.

## DEPLOY.md — Branch Protection section

Insert new `## CI / Branch Protection` section between line 40 (`---`) and line 42 (`## Variables de entorno`). Content:

```
## CI / Branch Protection

After merging this PR, configure required status checks in GitHub:
1. Settings → Branches → Branch protection rules → Edit rule for `main`
2. Check "Require status checks to pass before merging"
3. Add BOTH: `test-sqlite` and `test-pgsql`
4. Save. Verify by opening a draft PR — Merge button blocks until both are green.
```

## README.md note

Append to the existing "Continuous Integration" section (after line 63):

> Since Fase 2, the workflow runs the suite twice **in parallel**: once against SQLite (fast, default for local dev) and once against **PostgreSQL 17 + pg_trgm** matching production. Both jobs must pass — branch protection blocks merges otherwise. This closes the SQLite-vs-pgsql parity gap that caused 4 production bugs in May 2026.

Also fix line 64: "PHP 8.2" → "PHP 8.5" (lingering inconsistency with `test.yml:30`).

## Testing Strategy

| Layer | What | How |
|---|---|---|
| Workflow syntax | Valid YAML, valid GHA schema | First push runs CI; GitHub rejects malformed workflows immediately |
| Smoke (pgsql job works) | First PR run shows BOTH jobs green; `test-pgsql` reports 9 previously-skipped tests now executing | Inspect PHPUnit summary in `test-pgsql` log; expect "Skipped: 0" (or <9) |
| Negative smoke | Deliberately broken pgsql code (e.g., `SELECT NOW(123)` or `ROUND(numeric, int)` regression) on a scratch branch | `test-pgsql` fails, `test-sqlite` passes — confirms the gap is closed |
| Branch protection | Red `test-pgsql` blocks Merge button | Configure per DEPLOY.md, verify with negative smoke PR |

No new PHPUnit tests. The CI runs themselves are the verification.

## Concurrency & cost

- Same `concurrency.group` → PR sync cancels BOTH stale runs in lockstep (correct)
- Wall clock: `max(test-sqlite, test-pgsql)` ≈ 80s (vs 40s today)
- Cost: public repo = unlimited Actions minutes. N/A.

## Failure scenarios

| Scenario | Behavior |
|---|---|
| `postgres:17` fails to start | Service health check times out (retries=5 × 10s = 50s), job fails fast |
| `pg_trgm` creation fails | `psql` step exits non-zero, job fails with clear error |
| Test fails on pgsql only | Branch protection blocks merge; user fixes in same PR |
| Test fails on sqlite only | Same — both checks required |
| `phpunit.xml` env unexpectedly overrides workflow env | Verify in first run via log: `DB_CONNECTION` should print `pgsql`. If overridden, add `force="true"` to phpunit `<env>` — but ONLY if defaults silently win. (Should not happen.) |

## Rollback Plan

| Phase | Action | Reversible? |
|---|---|---|
| 1 | Remove `test-pgsql` from required status checks (GH UI) | Yes, re-add anytime |
| 2 | Revert the workflow commit (single PR) | Yes, git revert |
| 3 | Revert spec/docs commits | Yes, git revert |

No data, no schema, no runtime impact. Pure CI infra.

## Risks (design-level mitigations)

| Risk | Mitigation |
|---|---|
| Hidden pgsql bugs in `app/` surface on first run | That's the point — separate hotfix PRs. Explore §audit shows 0 expected new failures. |
| `pg_trgm` missing from `postgres:17` image | Bundled in `postgresql-contrib` (verified explore). Migration + psql step both check. |
| `RefreshDatabase` + `CREATE INDEX CONCURRENTLY` deadlock | Already mitigated: `$withinTransaction = false` on relevant migrations (`add_titulo_trgm_index_*.php:27`, `add_sitio_id_idx_*.php:27`) |
| `phpunit.xml` `<env>` overrides workflow env | Without `force="true"` attribute, PHPUnit `<env>` is a DEFAULT, not an override. Workflow `env:` wins. Verify in first log. |
| Composer cache contention between jobs | Same cache key → second job benefits from first's population; no contention (read-mostly) |

## Open Questions (deferred to sdd-tasks)

- [ ] Confirm whether to add an explicit `Wait for Postgres` bash step in addition to the service `health-cmd` (defense in depth) — currently NO, relying on service health alone is GHA-canonical. Reconsider if first runs show flakes.
- [ ] Should the spec delta REQ require `Skipped: 0` in the `test-pgsql` log, or just "fewer skips than test-sqlite"? Lean toward "0 markTestSkipped from `skipIfNotPgsql`" — `sdd-spec` decides.
- [ ] `actions/setup-node@v4` + Node 20 deprecation deadline 2026-06-02 — out of scope per proposal; flag for separate SDD.

---

**Next phase**: `sdd-tasks`
