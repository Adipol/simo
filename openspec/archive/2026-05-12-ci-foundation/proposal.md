# Proposal: ci-foundation (Fase 1)

**Change**: `ci-foundation` · **Phase**: propose · **Date**: 2026-05-12

## Intent

SIMO has **zero CI**: PR #18 (`set_config` SQLite/pgsql divergence) and PR #20 (cluster-head logic) shipped latent bugs that lived for months because nothing exercised the test suite on push/PR. Today the project has 837 tests, of which **23 are red baseline failures** — stale assertions and one unmocked HTTP call — masking the fact that everything else passes. Fase 1 lands a single GitHub Actions workflow that runs the SQLite suite on every PR and push to `main`, after the 23 failures are fixed so CI is green on day one.

## Scope

### In Scope

- **T0 — Fix 23 baseline failures** (6 clusters, must precede the workflow file):
  - Cluster A (1): `tests/Feature/ExampleTest.php` — assert 302 redirect or remove.
  - Cluster B (5): `tests/Feature/ProfileTest.php` — fix or remove Breeze boilerplate.
  - Cluster C (2): `tests/Feature/Seeders/EntidadesPublicasBoliviaSeederTest.php` — `33` → `241`, YPFB lookup.
  - Cluster D (9): `tests/Unit/Gemini/FiltroResultadoDTOTest.php` — wrap fixtures in `['personas' => [...]]`.
  - Cluster E (1): `tests/Unit/Gemini/PromptReglasTest.php` — refresh assertion string.
  - Cluster F (5): `tests/Feature/Services/GeminiFiltroNormalizacionTest.php` — `Http::fake()` (no new abstraction).
- `.github/workflows/test.yml` — single workflow, single sequential job (Approach 1).
- PHP 8.2 via `shivammathur/setup-php@v2`, extensions: `pgsql, mbstring, xml, curl, zip, bcmath, intl, sqlite3`.
- Composer cache via `actions/cache@v4` keyed on `hashFiles('**/composer.lock')`.
- Test step: `php -d memory_limit=512M artisan test` (no `--testsuite` split — single invocation).
- Triggers: `pull_request` + `push: main` + `workflow_dispatch`.
- Env in job: `APP_ENV=testing`, `TZ=America/La_Paz` (match prod, avoid locale flake).
- Branch name suggestion: `ci/foundation-baseline`.
- README addition: short "CI" section explaining "every PR runs the suite".

### Out of Scope

- Postgres service container (Fase 2 — separate SDD `ci-pgsql-tests`).
- Branch protection rules / required status checks (manual GitHub UI step).
- Code coverage reporting (Codecov, Coveralls).
- Static analysis (PHPStan, Psalm) — no config exists.
- Security scanning (CodeQL, Snyk, Dependabot beyond defaults).
- Deployment automation.
- Frontend asset build (`npm run build` not needed for backend tests).
- Lint job — no `.php-cs-fixer` / `pint.json` config currently; future PR.
- Node/Vite caching.
- Slack/Discord notifications.

## Capabilities

### New Capabilities
- `ci-pipeline`: Automated test execution on PR open/sync, push to main, and manual dispatch. Defines triggers, runner, PHP setup, dependency cache, test invocation contract, and the green-on-day-1 invariant.

### Modified Capabilities
- None. Fase 1 introduces a new capability; no existing spec changes behavior.

## Approach

Fix-first, then ship. **T0** brings the suite to 0 failures by editing the six identified test files (no production code change anticipated; HTTP mocking uses Laravel's built-in `Http::fake()`). Only after `php -d memory_limit=512M artisan test` reports zero failures locally does **T1** add `.github/workflows/test.yml` with a single sequential job. Choosing single-job (vs parallel Feature/Unit jobs) saves ~50% of the 2000 min/month private-repo budget at a cost of ~15 s wall time — acceptable for a 70 s total run. The `-d memory_limit=512M` flag solves the OOM at the CI invocation layer without mutating `phpunit.xml` (keeps local dev behavior unchanged).

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `tests/Feature/ExampleTest.php` | Modified | Cluster A fix (~2 lines). |
| `tests/Feature/ProfileTest.php` | Modified or Removed | Cluster B — boilerplate, decide per route audit. |
| `tests/Feature/Seeders/EntidadesPublicasBoliviaSeederTest.php` | Modified | Cluster C — count + lookup. |
| `tests/Unit/Gemini/FiltroResultadoDTOTest.php` | Modified | Cluster D — wrap 9 fixtures. |
| `tests/Unit/Gemini/PromptReglasTest.php` | Modified | Cluster E — assertion string. |
| `tests/Feature/Services/GeminiFiltroNormalizacionTest.php` | Modified | Cluster F — add `Http::fake()` to 5 tests. |
| `app/Services/Gemini/*` | Untouched (expected) | Mock at test layer only; if prod code must change, STOP and re-scope. |
| `.github/workflows/test.yml` | New | Workflow file (~50 lines YAML). |
| `README.md` | Modified | Add brief "CI" section (~10 lines). |

**Estimated size**: ~250–350 LOC total. Rough split: T0 fixes ≈ 150–220 (Cluster F dominates with `Http::fake()` setup × 5 tests), workflow YAML ≈ 50, README ≈ 10, plus any minor test scaffolding helpers ≈ 20. Sits inside the 400-line PR review budget — single PR is viable.

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|-----------|
| A T0 cluster reveals a real architectural bug (not stale debt) | Medium | STOP T0, report to orchestrator for re-scope — do NOT push through. |
| OOM persists at 512 M in CI | Low | Fall back to scoped `--testsuite=Feature` / `--testsuite=Unit` split steps; escalate threshold = single step exceeds 1 GB resident. |
| Workflow YAML syntax error | Medium | Run `actionlint` (or `npx @rhysd/actionlint`) locally before push; document the validation step in T1. |
| Local-green / CI-red drift (locale, TZ, line endings) | Medium | Set `TZ: America/La_Paz` in workflow env; `.gitattributes` already pins `eol=lf`. |
| `composer install` exceeds default 5 min timeout | Low | Document `timeout-minutes: 10` on the install step if observed. |
| `Http::fake()` rewrite changes test intent | Low | Keep assertions identical; only swap HTTP layer. Code review checks each mocked test still asserts the same outcome. |
| 23 failures take longer than ~3 h estimate | Medium | Re-scope T0 into chained PR if it nears the 400-line review budget alone. |

## Rollback Plan

- **T0 fixes**: each cluster is its own commit; revert the offending commit if a fix breaks unrelated tests.
- **Workflow file**: `git rm .github/workflows/test.yml` + push to `main`. CI stops immediately; no runtime impact on the application.
- **Combined rollback** (if PR already merged): single revert commit on the merge — workflow disappears, test fixes revert atomically per cluster commit (preserved via `work-unit-commits` discipline).

## Dependencies

- GitHub Actions enabled on the repo (default for private repos under the org plan — verify before T1).
- `shivammathur/setup-php@v2` and `actions/cache@v4` (public Marketplace actions, no auth needed).
- 2000-min/month GitHub Actions budget. ~70 s/run → ~28 runs/day before approaching the cap. Sufficient for current team velocity.

## Success Criteria

- [ ] `php -d memory_limit=512M artisan test` exits 0 locally after T0 (0 failures, 0 errors; skipped pgsql-only tests stay skipped).
- [ ] PR for this change runs the new workflow on its OWN first push and finishes green.
- [ ] Smoke-test the smoke test: a throwaway commit that breaks one test produces a red CI run, then is reverted.
- [ ] No `--exclude-group` or test-allow-list lands in `phpunit.xml` or the workflow.
- [ ] CI run time stays under 3 minutes (target ~70 s).
- [ ] README explains how to read and re-run CI in one short paragraph.
- [ ] No secrets added to repo settings (Fase 1 invariant).

## Verification Plan

1. Run each Cluster's failing tests in isolation; confirm green after fix:
   `php artisan test --filter=ExampleTest`, etc.
2. Run full suite locally: `php -d memory_limit=512M artisan test` → expect 0 failures.
3. Open the PR; the workflow triggers; first run is green.
4. Push a deliberate failing assertion on a temporary branch; confirm CI reports red; revert.
5. Confirm `Closes #<issue>` is in the PR description and one `type:*` label is set (branch-pr rule).

## Open Questions

- ProfileTest (Cluster B): are Breeze routes still wired in `routes/web.php`? Answer determines fix-vs-remove. Resolve during T0; if the routes were removed deliberately, delete the tests with a one-line commit message explaining the boilerplate removal.
- README vs DEPLOY.md for the CI section — pick whichever the team already treats as the contributor entry point (default: README, since DEPLOY.md targets ops).
