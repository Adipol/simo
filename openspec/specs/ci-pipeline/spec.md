# Capability: ci-pipeline

**Version**: 1.0.0 · **Date**: 2026-05-12 · **Status**: draft

## Purpose

Provides automated test execution for every relevant code change via a single GitHub Actions workflow. The workflow runs the full SQLite-backed PHPUnit suite (Feature + Unit) on PR open/sync to `main`, push to `main`, and manual dispatch — ensuring no regression ships without a green suite. The capability also establishes the green-on-day-1 invariant: all 23 pre-existing baseline failures MUST be resolved (T0) before the workflow file lands.

## Requirements

### REQ-1: Workflow triggers on all relevant code-change events

The workflow MUST trigger on: (a) pull request opened or synchronized targeting `main`; (b) push directly to `main`; (c) `workflow_dispatch` for manual invocation. The workflow MUST NOT trigger on pull requests targeting branches other than `main`.

#### SCN-1.1: PR opened to main triggers the workflow

- **Given**: A contributor opens a pull request with `main` as the base branch
- **When**: The PR creation event is received by GitHub Actions
- **Then**: The CI workflow starts a new run for that commit

#### SCN-1.2: New commit pushed to existing PR re-triggers the workflow

- **Given**: A PR targeting `main` is already open
- **When**: A new commit is pushed to the PR's head branch
- **Then**: The CI workflow starts a new run for the updated commit

#### SCN-1.3: Push to main directly triggers the workflow

- **Given**: A commit is pushed (or merged) directly to `main`
- **When**: The push event is received by GitHub Actions
- **Then**: The CI workflow starts a new run for that commit

#### SCN-1.4: Maintainer invokes workflow manually via `workflow_dispatch`

- **Given**: The maintainer is on the Actions tab of the repository
- **When**: They select "Run workflow" for the CI workflow
- **Then**: A new run starts immediately on the selected branch

#### SCN-1.5: PR targeting a branch other than main does NOT trigger

- **Given**: A contributor opens a pull request with a base branch that is NOT `main` (e.g., `develop`)
- **When**: The PR creation event is received by GitHub Actions
- **Then**: The CI workflow does NOT start a run for that event

---

### REQ-2: Zero test failures required for workflow success

The workflow MUST exit with a non-zero status code if any test fails. The workflow MUST NOT use `--exclude-group`, allow-lists, or `markTestSkipped` bypasses to suppress known failures.

#### SCN-2.1: Test failure causes workflow to fail with non-zero exit

- **Given**: The test suite is installed and the workflow is running
- **When**: One or more tests fail
- **Then**: The `php artisan test` step exits with a non-zero status code
- **And**: The workflow run is marked as failed

#### SCN-2.2: All tests passing causes workflow to succeed

- **Given**: The test suite is installed and the workflow is running
- **When**: All 837+ tests pass (0 failures, 0 errors)
- **Then**: The `php artisan test` step exits with code 0
- **And**: The workflow run is marked as successful

#### SCN-2.3: No exclusion groups hide failing tests

- **Given**: The workflow's test step is inspected
- **When**: The step command is read
- **Then**: No `--exclude-group`, `--group`, allow-lists, or skip bypasses are present

---

### REQ-3: PHP runtime and extensions match production requirements

The workflow MUST use PHP 8.2 and MUST install the extension set required to boot the application: `pgsql, mbstring, xml, curl, zip, bcmath, intl, sqlite3`. Tests run against SQLite in Fase 1, but the application bootstrap requires `pgsql` at load time.

#### SCN-3.1: Workflow uses PHP 8.2

- **Given**: The workflow starts a new run
- **When**: The "Setup PHP" step executes
- **Then**: `php --version` reports `PHP 8.2.x`

#### SCN-3.2: All required PHP extensions are installed

- **Given**: The PHP setup step has completed
- **When**: The application boots (e.g., `php artisan test` initializes)
- **Then**: Extensions `pgsql`, `mbstring`, `xml`, `curl`, `zip`, `bcmath`, `intl`, `sqlite3` are all available

#### SCN-3.3: Composer installs all dependencies including dev

- **Given**: `composer.lock` is present in the repository
- **When**: The dependency installation step runs
- **Then**: `vendor/` is populated with both production and dev dependencies (default `composer install` flags; no `--no-dev`)

---

### REQ-4: Composer dependencies are cached across runs

The workflow MUST cache the resolved Composer vendor directory between runs. The cache key MUST be derived from the hash of `composer.lock` so that a changed lock file invalidates the cache automatically.

#### SCN-4.1: Cache hit reuses vendor directory

- **Given**: A previous workflow run cached `vendor/` with the same `composer.lock` hash
- **When**: A new run starts with an unchanged `composer.lock`
- **Then**: The cache-restore step reports a hit and `composer install` is skipped or completes in under 10 s

#### SCN-4.2: Cache miss rebuilds and re-caches vendor

- **Given**: `composer.lock` has changed since the last cached run (or no cache exists)
- **When**: The cache-restore step runs
- **Then**: It reports a miss, `composer install` runs in full, and the new `vendor/` is saved to cache with the updated key

#### SCN-4.3: Cache key includes composer.lock hash

- **Given**: The workflow's cache step configuration is inspected
- **When**: The cache key expression is read
- **Then**: It includes `hashFiles('**/composer.lock')` (or an equivalent content hash)

---

### REQ-5: Test runtime memory must accommodate the full suite without OOM

The `php artisan test` invocation MUST override PHP's default `memory_limit` to `512M` via the `-d memory_limit=512M` CLI flag. This flag MUST be applied at the workflow invocation level — NOT by mutating `phpunit.xml` or any ini file.

#### SCN-5.1: Test command runs with 512M memory override

- **Given**: The workflow's test step configuration is inspected
- **When**: The command string is read
- **Then**: It matches the pattern `php -d memory_limit=512M artisan test`

#### SCN-5.2: OOM beyond 512M surfaces as workflow failure, not silent flake

- **Given**: A future regression causes total test memory to exceed 512M
- **When**: PHP exceeds the limit
- **Then**: PHP emits a fatal "Allowed memory size exhausted" error
- **And**: The workflow step exits non-zero and the run is marked failed (not silently retried)

---

### REQ-6: T0 — all 23 baseline failures MUST be resolved before the workflow lands

The T0 batch MUST fix all 23 pre-existing failures across 6 clusters. No exclusion or skip mechanism may be used as a substitute. T0 is a prerequisite for merging the workflow file.

#### SCN-6.1: Full suite passes locally after T0

- **Given**: All T0 cluster fixes have been applied
- **When**: `php -d memory_limit=512M artisan test` is run locally
- **Then**: It exits 0 with 0 failures and 0 errors

#### SCN-6.2: GeminiFiltroNormalizacionTest uses Http::fake() — no real HTTP

- **Given**: Cluster F tests in `tests/Feature/Services/GeminiFiltroNormalizacionTest.php` have been fixed
- **When**: `php artisan test --filter=GeminiFiltroNormalizacionTest` runs (with no network access)
- **Then**: All 5 tests pass using `Http::fake()` without making any real HTTP call to Gemini API

#### SCN-6.3: First CI run on the workflow PR is green

- **Given**: T0 is complete and `php -d memory_limit=512M artisan test` exits 0 locally
- **When**: The PR that adds `.github/workflows/test.yml` triggers its first workflow run
- **Then**: The run completes successfully without any test exclusion or manual override

---

### REQ-7: Workflow output makes failures debuggable

Workflow step boundaries MUST be clearly named and test output MUST include failing test names and assertion messages in the run log.

#### SCN-7.1: Failed test names and assertion messages appear in log

- **Given**: One or more tests fail in a workflow run
- **When**: The workflow log for the test step is opened
- **Then**: The failing test class name, method name, and assertion message are visible without downloading any artifact

#### SCN-7.2: Workflow step boundaries are clearly labeled

- **Given**: A workflow run completes (pass or fail)
- **When**: The GitHub Actions run summary is viewed
- **Then**: Discrete steps labeled (at minimum) "Setup PHP", "Cache dependencies", "Install dependencies", and "Run tests" are visible and their durations are reported individually

---

## Out of Scope

The following items are explicitly deferred and MUST NOT be added to this capability without a separate SDD change:

| ID | Item | Deferred To |
|----|------|-------------|
| OUT-1 | Nightly / scheduled workflow runs | Future SDD |
| ~~OUT-2~~ | ~~Postgres service container for pgsql-only test paths~~ | ~~`ci-pgsql-tests` (Fase 2)~~ — **Delivered in Fase 2** |
| OUT-3 | Code coverage reporting (Codecov, Coveralls) | Future SDD |
| OUT-4 | Static analysis (PHPStan, Psalm) | Future SDD |
| OUT-5 | Security scanning (CodeQL, Snyk, Dependabot security alerts) | Future SDD |
| OUT-6 | Deployment automation | Future SDD |
| OUT-7 | Branch protection / required status checks configuration | Manual GitHub UI |
| OUT-8 | Lint job (php-cs-fixer, pint) | Future SDD |
| OUT-9 | Frontend asset build (`npm run build`) | Not needed for backend tests |
| OUT-10 | Slack / Discord notifications | Future SDD |
| OUT-A1 | Replacing SQLite with pgsql in local development | Never (out of scope permanently) |
| OUT-A2 | Performance optimization of slow tests | Future SDD |
| OUT-A3 | Other CI capabilities: linting, coverage, type checking, security scanning | Separate SDDs |
| OUT-A4 | Production database changes | Out of scope |
| OUT-A5 | Test data seeders that vary by driver | Future SDD |
| OUT-A6 | Refactoring app code for pgsql compatibility (already done — PRs #18/#26/#27/#28) | Closed |

---

## Glossary

| Term | Definition |
|------|-----------|
| `baseline failure` | A test that fails before this change, representing pre-existing debt, not a regression introduced by this SDD |
| `workflow run` | A single execution of the CI workflow triggered for a specific commit SHA |
| `cache hit` | A workflow run where the `vendor/` directory is restored from a prior cached run, skipping `composer install` |
| `cache miss` | A workflow run where no cached `vendor/` matches the current `composer.lock` hash, requiring a full install |
| `T0` | The pre-workflow task batch that resolves all 23 baseline failures across 6 clusters |
| `T1` | The task that adds `.github/workflows/test.yml` after T0 is complete |
| `test-sqlite` | **Added Fase 2** — The CI job that runs the full PHPUnit suite against an in-memory SQLite database (renamed from `test`) |
| `test-pgsql` | **Added Fase 2** — The CI job that runs the full PHPUnit suite against a PostgreSQL 17 service container (matches production) |
| `service container` | **Added Fase 2** — A GitHub Actions feature that runs auxiliary Docker containers (e.g., `postgres:17`) alongside the job for the duration of its run |

---

## Delta: Fase 2 — ci-pgsql-tests

**Added**: 2026-05-15 · **Change**: `ci-pgsql-tests` · **Status**: Added

> The requirements below extend the Fase 1 spec. Fase 1 REQs (1–7) are unchanged.
> Full delta spec: `openspec/changes/ci-pgsql-tests/spec.md`

---

### REQ-A1: PostgreSQL test execution

**Status: Added in Fase 2 (ci-pgsql-tests)**

The CI pipeline MUST run the full test suite against a PostgreSQL 17 database in addition to SQLite.

#### SCN-A1.1: Parallel jobs on PR

- **Given**: A pull request targeting `main` is opened or synchronized
- **When**: The workflow run starts
- **Then**: Both `test-sqlite` and `test-pgsql` jobs execute in parallel within the same workflow run

#### SCN-A1.2: pgsql job targets PostgreSQL 17

- **Given**: The `test-pgsql` job starts
- **When**: The database service container is provisioned
- **Then**: The running database server reports version 17 (`postgres:17` image)

#### SCN-A1.3: Service container health check before tests

- **Given**: The `test-pgsql` job has started
- **When**: The health check probe runs (max 5 retries)
- **Then**: The job waits until `pg_isready` succeeds before executing any test step
- **And**: If the database does not become ready within 5 retries, the job fails with a clear timeout error

#### SCN-A1.4: pg_trgm extension present before migrations

- **Given**: The pgsql service container is healthy
- **When**: Migrations run (before any test executes)
- **Then**: The `pg_trgm` extension exists in the test database
- **And**: Subsequent migrations that depend on `%` trigram operators succeed without error

---

### REQ-A2: Test driver isolation

**Status: Added in Fase 2 (ci-pgsql-tests)**

Each CI job MUST run tests against exactly one database driver, with no cross-contamination between jobs.

#### SCN-A2.1: test-sqlite uses in-memory SQLite

- **Given**: The `test-sqlite` job starts
- **When**: The `php artisan test` step executes
- **Then**: The active database connection is `sqlite` with an in-memory database
- **And**: No connection to any external database service is attempted

#### SCN-A2.2: test-pgsql uses the service container

- **Given**: The `test-pgsql` job starts
- **When**: The `php artisan test` step executes
- **Then**: The active database connection is `pgsql` pointing to the job's `postgres:17` service container

#### SCN-A2.3: pgsql-only tests execute under test-pgsql

- **Given**: Tests that call `markTestSkipped` (or `skipIfNotPgsql`) for non-pgsql drivers exist in the suite
- **When**: The `test-pgsql` job runs
- **Then**: Those tests are NOT skipped — they execute and their assertions are evaluated

#### SCN-A2.4: Driver-agnostic tests run under both jobs

- **Given**: A test has no driver-specific dependency and no skip guard
- **When**: Both `test-sqlite` and `test-pgsql` jobs run
- **Then**: The test executes and passes under both jobs independently

---

### REQ-A3: Branch protection enforcement

**Status: Added in Fase 2 (ci-pgsql-tests)**

The repository MUST require BOTH CI jobs to pass before any PR can be merged to `main`.

#### SCN-A3.1: test-sqlite is a required check on main

- **Given**: Branch protection is configured on `main`
- **When**: A PR is viewed in the GitHub UI
- **Then**: The status check named `test-sqlite` is listed as required and must be green to enable the merge button

#### SCN-A3.2: test-pgsql is a required check on main

- **Given**: Branch protection is configured on `main`
- **When**: A PR is viewed in the GitHub UI
- **Then**: The status check named `test-pgsql` is listed as required and must be green to enable the merge button

#### SCN-A3.3: DEPLOY.md documents the manual GH UI steps

- **Given**: Branch protection is NOT managed by code (manual GitHub UI step)
- **When**: A maintainer reads `DEPLOY.md`
- **Then**: They find step-by-step instructions to add `test-sqlite` and `test-pgsql` as required status checks on `main`

---

### REQ-A4: Failure visibility

**Status: Added in Fase 2 (ci-pgsql-tests)**

When either job fails, the failure MUST be clearly attributable to that specific driver.

#### SCN-A4.1: pgsql failure is labeled in workflow log

- **Given**: One or more tests fail in the `test-pgsql` job
- **When**: The workflow log for that job is opened
- **Then**: The failing test names and assertion messages are visible under the `test-pgsql` job heading — distinct from any SQLite output

#### SCN-A4.2: sqlite failure is labeled in workflow log

- **Given**: One or more tests fail in the `test-sqlite` job
- **When**: The workflow log for that job is opened
- **Then**: The failing test names and assertion messages are visible under the `test-sqlite` job heading — distinct from any pgsql output

#### SCN-A4.3: PR status shows both checks distinctly

- **Given**: Both jobs have completed (either pass or fail) for a PR
- **When**: The PR checks section is viewed
- **Then**: Two separate check entries appear — one labeled `test-sqlite`, one labeled `test-pgsql` — each with its own pass/fail status

---

### REQ-A5: Local development is unaffected

**Status: Added in Fase 2 (ci-pgsql-tests)**

The local development workflow MUST remain unchanged by this CI addition.

#### SCN-A5.1: php artisan test locally still uses SQLite

- **Given**: A developer runs `php artisan test` on their local machine with the default `.env.testing` or `phpunit.xml` configuration
- **When**: The test suite executes
- **Then**: The active database connection is `sqlite` (in-memory) — no pgsql service required

#### SCN-A5.2: No local Postgres setup required

- **Given**: A developer has not installed or configured a local PostgreSQL instance
- **When**: They run `php artisan test` locally
- **Then**: The full SQLite-backed suite completes without error or connection warning

#### SCN-A5.3: Developers MAY run pgsql tests locally by opt-in

- **Given**: A developer has a local PostgreSQL 17 instance available
- **When**: They override `DB_CONNECTION=pgsql` (and related connection vars) before running `php artisan test`
- **Then**: The suite runs against their local pgsql instance, including the previously-skipped pgsql-only tests
- **And**: The steps to do this are documented in `DEPLOY.md` or `README.md`

---

### REQ-A6: First-run mitigation

**Status: Added in Fase 2 (ci-pgsql-tests)**

The first CI run after this change is merged MAY reveal previously-hidden pgsql incompatibilities. These discoveries MUST be treated as bugs found, not as failures of this SDD.

#### SCN-A6.1: New pgsql failures on first run are follow-up PRs

- **Given**: The `test-pgsql` job reveals a test failure on the first run after merge
- **When**: The team reviews the failure
- **Then**: A separate follow-up PR is created to fix the incompatibility — it does NOT block or revert this SDD

#### SCN-A6.2: Known pre-existing pgsql bugs are already fixed

- **Given**: PRs #18, #26, #27, and #28 have been merged before this SDD ships
- **When**: The `test-pgsql` job runs the full suite
- **Then**: The four categories of pgsql incompatibility fixed by those PRs do NOT cause new failures
