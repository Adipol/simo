# Delta Spec: ci-pgsql-tests → ci-pipeline

**Change**: `ci-pgsql-tests` · **Phase**: spec · **Date**: 2026-05-15
**Base capability**: `openspec/specs/ci-pipeline/spec.md` (Fase 1)
**Delta type**: ADDED requirements only — no Fase 1 REQs modified or removed

---

## ADDED Requirements

### REQ-A1: PostgreSQL test execution

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

The first CI run after this change is merged MAY reveal previously-hidden pgsql incompatibilities. These discoveries MUST be treated as bugs found, not as failures of this SDD.

#### SCN-A6.1: New pgsql failures on first run are follow-up PRs

- **Given**: The `test-pgsql` job reveals a test failure on the first run after merge
- **When**: The team reviews the failure
- **Then**: A separate follow-up PR is created to fix the incompatibility — it does NOT block or revert this SDD

#### SCN-A6.2: Known pre-existing pgsql bugs are already fixed

- **Given**: PRs #18, #26, #27, and #28 have been merged before this SDD ships
- **When**: The `test-pgsql` job runs the full suite
- **Then**: The four categories of pgsql incompatibility fixed by those PRs do NOT cause new failures

---

## REMOVED from Out of Scope

| ID | Original text | Reason |
|----|---------------|--------|
| OUT-2 | "Postgres service container for pgsql-only test paths" (`ci-pgsql-tests` Fase 2) | Delivered by this change — remove from `ci-pipeline` Out of Scope table |

---

## Out of Scope (delta additions)

| ID | Item |
|----|------|
| OUT-A1 | Replacing SQLite with pgsql in local development |
| OUT-A2 | Performance optimization of slow tests |
| OUT-A3 | Other CI capabilities: linting, coverage, type checking, security scanning |
| OUT-A4 | Production database changes |
| OUT-A5 | Test data seeders that vary by driver |
| OUT-A6 | Refactoring application code for pgsql compatibility (already completed in PRs #18/#26/#27/#28) |

---

## Glossary (additions to ci-pipeline)

| Term | Definition |
|------|-----------|
| `test-sqlite` | The CI job that runs the full PHPUnit suite against an in-memory SQLite database (default driver, fast) |
| `test-pgsql` | The CI job that runs the full PHPUnit suite against a PostgreSQL 17 service container (matches production) |
| `service container` | A GitHub Actions feature that runs auxiliary Docker containers (e.g., `postgres:17`) alongside the job for the duration of its run |
