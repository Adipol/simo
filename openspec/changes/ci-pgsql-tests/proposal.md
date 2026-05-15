# Proposal: ci-pgsql-tests

**Change**: `ci-pgsql-tests` · **Phase**: propose · **Date**: 2026-05-15

## Why

In the last two weeks, **4 production bugs shipped because the CI suite only runs against SQLite while production runs PostgreSQL 17**: PR #18 (`SET LOCAL ... = ?` parameter binding), PR #26 (`descartado = 1` vs `descartado = true` boolean handling), PR #27 (`ROUND(numeric, int)` signature mismatch), PR #28 (`ORDER BY` raw column after `GROUP BY` violation). Each one passed local + CI green on SQLite, then crashed within minutes of deploy. The pattern is now **proven, not theoretical** — every pgsql-only quirk we miss is a guaranteed production incident. Fase 1 (`ci-foundation`) established the workflow; this is Fase 2: close the driver gap.

## What Changes

- **Modify** `.github/workflows/test.yml`: rename current `test` job to `test-sqlite`; add a new parallel `test-pgsql` job
- **Add** `postgres:17` GitHub Actions service container to the `test-pgsql` job with `pg_isready` health check (retries=5)
- **Add** job-level `env:` block in `test-pgsql` overriding `DB_CONNECTION=pgsql` plus `DB_HOST/PORT/DATABASE/USERNAME/PASSWORD` so phpunit reads pgsql instead of in-memory SQLite
- **Reuse** existing `pg_trgm` extension migration (`2026_05_09_100001_enable_pg_trgm_extension.php`) — runs as superuser `postgres`, no extra setup
- **The 9 `markTestSkipped`/`skipIfNotPgsql` tests now RUN** under the pgsql job with zero code changes
- **Update** canonical `openspec/specs/ci-pipeline/spec.md` with a delta REQ for pgsql validation (Fase 2 extension)
- **Update** `DEPLOY.md`: document the manual GitHub UI step to require BOTH `test-sqlite` AND `test-pgsql` as branch-protection status checks
- **Update** `README.md`: one-line note that CI runs against both drivers

## Out of Scope

- Replacing SQLite for local development (SQLite stays default for fast iteration)
- Changing the production database (already PostgreSQL 17)
- Other CI features: lint, coverage, type checking (PHPStan/Psalm), security scanning
- Performance optimization of slow tests
- Refactoring application code to be more pgsql-friendly (already done by PRs #18/#26/#27/#28)
- Investigating `Cambio::scopeMultimodal()` (`jsonb_array_length(::jsonb)`) — pre-existing technical debt, separate SDD
- Migrating off Node 20 (GHA deprecation deadline 2026-06-02 is a separate concern)

## Capabilities

### New Capabilities
- None.

### Modified Capabilities
- `ci-pipeline`: extend with delta REQ that the workflow MUST run the same suite twice — once with SQLite (existing), once with PostgreSQL 17 + `pg_trgm` — as parallel required status checks. `OUT-2` ("Postgres service container for pgsql-only test paths") is REMOVED from the Out of Scope table because this change delivers it.

## Approach

Two parallel jobs (`test-sqlite` + `test-pgsql`) inside the same workflow file. Same checkout, same PHP setup, same Composer cache, same Node build, same `php -d memory_limit=512M artisan test` command — they differ ONLY in the `DB_CONNECTION` env and the presence of a `postgres:17` service container. GitHub Actions does NOT support conditional `services:` blocks via matrix expressions (verified in explore §Q4), so two separate jobs is the architecturally correct pattern — not a YAML hack. Both jobs run in parallel (~80s wall clock max), GHA reports them as independent checks, and branch protection requires BOTH green to merge. This closes the SQLite-vs-pgsql gap permanently.

## Affected Areas

| Area | Impact | Description |
|---|---|---|
| `.github/workflows/test.yml` | Modified | Rename `test` → `test-sqlite`; add `test-pgsql` job with `services.postgres` + DB env vars (~35 new LOC) |
| `openspec/specs/ci-pipeline/spec.md` | Modified | Delta REQ added for pgsql parallel job; `OUT-2` removed from Out of Scope table |
| `DEPLOY.md` | Modified | Document required branch-protection update (both checks required) |
| `README.md` | Modified | One-line CI note (dual-driver) |
| `phpunit.xml` | NOT touched | Defaults stay SQLite; pgsql job overrides via CI `env:` block |
| `config/database.php` | NOT touched | pgsql connection already wired (lines 86–99) |
| All migrations + services + tests | NOT touched | Pgsql-conditional code already guarded; 9 skipped tests auto-run |

## Estimated Size

| Layer | LOC |
|---|---|
| `.github/workflows/test.yml` (new YAML) | ~35 |
| `openspec/specs/ci-pipeline/spec.md` delta | ~5 |
| `DEPLOY.md` | ~8 |
| `README.md` | ~2 |
| **Total** | **~50 LOC** |

Well under the 400-line review budget. **Single PR** per cached `delivery_strategy: ask-on-risk` — no chaining needed.

## Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| `postgres:17` service startup race (tests start before DB ready) | Low | `--health-cmd pg_isready --health-retries 5` on the service container |
| `pg_trgm` extension creation fails | Very Low | Default `postgres` user is SUPERUSER in the official image; existing migration uses `CREATE EXTENSION IF NOT EXISTS` |
| `RefreshDatabase` + `CREATE INDEX CONCURRENTLY` deadlock | Low | All `CONCURRENTLY` migrations already set `$withinTransaction = false` (verified `add_titulo_trgm_index_to_resultados_scraping.php:27`, `add_sitio_id_idx_to_resultados_scraping.php:27`); `migrate:fresh` runs before per-test transactions |
| CI wall clock doubles (~40s → ~80s) | Certain | Acceptable — jobs run in parallel; public repo has unlimited Actions minutes; private repo budget ~160 min/month vs 2000 quota |
| First pgsql run reveals hidden pgsql-incompatible code | **Medium** | **That's the point.** Each failure → separate hotfix PR. Expected: 0 new failures based on explore audit (all hot paths already guarded). Worst-case: 1–2 new bugs surface — same cost as catching them in prod, but now caught pre-merge. |
| Composer cache key collision between jobs | Low | Both jobs share key `composer-${{ hashFiles('**/composer.lock') }}` — second job benefits from first job's cache population |
| GHA Node 20 deprecation warning (deadline 2026-06-02) | Low | Pre-existing concern, separate SDD; does not block this change |
| User forgets to configure branch protection after merge | Medium | `DEPLOY.md` documents the exact GitHub UI steps; success criterion gates the SDD on smoke-testing branch protection actually blocks a red PR |

## Rollback Plan

- **Workflow**: revert the commit to `.github/workflows/test.yml`. CI returns to single SQLite job. No data, no schema, no runtime dependency affected.
- **Branch protection**: in GitHub UI, remove `test-pgsql` from required status checks. Reverts to SQLite-only gating.
- **Spec + docs**: revert the doc-only changes; no operational impact.
- **No production rollback needed** — this is CI infrastructure only.

## Dependencies

- GitHub Actions service-container feature (stable since 2019).
- `postgres:17` Docker image on Docker Hub (matches production VPS).
- `shivammathur/setup-php@v2` action (already used, no version bump).
- `pg_trgm` extension bundled with `postgresql-contrib` in the official `postgres:17` image (verified in explore).

## Success Criteria

- [ ] `test.yml` contains exactly two jobs: `test-sqlite` and `test-pgsql`, both triggering on the same events (PR→main, push→main, workflow_dispatch).
- [ ] First PR run: BOTH jobs green; `test-pgsql` reports the 9 previously-skipped tests now executing (count visible in PHPUnit summary).
- [ ] Manual smoke: open a deliberately pgsql-broken PR (e.g., re-introduce `ROUND(x, 1)` on a numeric column); verify `test-pgsql` fails while `test-sqlite` would pass — confirming the gap is now closed.
- [ ] Branch protection configured (manual step) and verified: a red `test-pgsql` blocks merge.
- [ ] No regression in `test-sqlite` (same ~40s, same pass count as Fase 1).
- [ ] `DEPLOY.md` contains the exact branch-protection setup steps.

## Verification Plan

1. **Local pre-flight**: run `docker run --rm -e POSTGRES_PASSWORD=postgres -p 5432:5432 postgres:17` and `DB_CONNECTION=pgsql DB_USERNAME=postgres DB_PASSWORD=postgres DB_DATABASE=testing php -d memory_limit=512M artisan test` — confirm full suite passes against pgsql before pushing. This catches any sleeper bug pre-PR.
2. **Workflow lint**: `act` (or GitHub's workflow validator) on the new `test.yml` to verify YAML syntax + service block before push.
3. **PR run**: both jobs must show green. Inspect `test-pgsql` logs for "Skipped: 0" (or a smaller count than today's 9).
4. **Branch protection**: configure required checks in GitHub UI per `DEPLOY.md`.
5. **Negative smoke**: open a throwaway PR with a synthetic pgsql-only failure; verify CI blocks merge.
6. **Post-merge monitoring**: watch first 5 PRs after merge; if false positives appear, file follow-up.

## Open Questions

- None blocking. All major decisions FROZEN by orchestrator handoff: (1) two parallel jobs (not matrix), (2) both required for branch protection, (3) postgres:17, (4) pg_trgm via existing migration, (5) no `phpunit-pgsql.xml` file — CI env override only, (6) no local-dev changes. `sdd-spec` and `sdd-design` can proceed in parallel.

**Artifact files**: `openspec/changes/ci-pgsql-tests/proposal.md`
**Next phase**: `sdd-spec` (parallel to `sdd-design`)
