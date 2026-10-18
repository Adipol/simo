# PR 107 SQLite test portability

## Goal and authorization
Fix the two `test-sqlite` failures observed in GitHub Actions run 37866400212 for draft tracker PR #107 without changing production behavior. The user authorized a correction and tests, received the result and diff **before any new commit**, then separately authorized one local work-unit commit. No push, PR mutation, merge, or VPS deployment is authorized in this unit. Preserve unrelated staged `.gitignore` and `odd/tasks/kpi-bandeja-clickthrough.md` exactly.

## Evidence and boundaries
- PR head: `655987a3d76b2fb5ae25d1219e7527c20942a138`. `test-pgsql` and `test-python` succeeded; `test-sqlite` failed with two assertions at `tests/Feature/Services/AuthorityRemovalReviewServiceTest.php:187,240` because SQLite omits SQL `FOR UPDATE`.
- Allowed source edit surface: `tests/Feature/Services/AuthorityRemovalReviewServiceTest.php` only. Preserve PostgreSQL lock-order and same-second ordering assertions. SQLite must still exercise confirm/reject and same-second behavior; a backend-specific lock assertion may be skipped on SQLite with an explicit reason.
- Observed RED is the exact published-HEAD CI SQLite result. GREEN requires focused SQLite and PostgreSQL tests plus proportionate suite verification in an isolated runtime. Do not claim subsequent assertions were verified before they run.

## Tasks
- [x] Diagnose and record exact failed assertions and CI runtime difference.
- [x] Make a minimal test-only dialect-aware adjustment, preserving PostgreSQL lock assertions and SQLite behavioral checks.
- [x] Independently run focused SQLite/PostgreSQL and full suite checks; verify exact diff, index integrity, and no unintended Git changes.
- [x] Show the user the test outcomes and diff before any commit or publication.
- [ ] Create one audited local work-unit commit containing only the test correction and this task document; record the commit ID in the Engram mirror afterward. Do not push.

## Evidence
- The correction changes only `tests/Feature/Services/AuthorityRemovalReviewServiceTest.php`: SQLite skips the PostgreSQL-specific SQL lock-order assertion; its same-second test still exercises confirm/reject with and without a refreshed observation and asserts the two-column order. PostgreSQL additionally checks `FOR UPDATE` on the snapshot query and all source/review/snapshot locks.
- Isolated syntax and Pint checks passed. Focused SQLite passed 13 tests with one lock-order skip; PostgreSQL 17 passed 14 tests.
- Initial disposable full-suite attempts were inconclusive: the source archive omitted root `DEPLOY.md`, and `artisan test` spawned a PHP subprocess at a 128 MiB memory limit despite the parent CLI's 512 MiB setting. These are setup errors, not candidate test results.
- Corrected isolated runs with `php -d memory_limit=512M vendor/bin/phpunit` exited 0: SQLite 1,391 tests / 4,126 assertions (19 skipped, 1 incomplete, 2 metadata deprecations); PostgreSQL 17 1,391 tests / 4,179 assertions (1 skipped, 1 risky zero-assertion test, 2 metadata deprecations). Logs: `/home/george/.cache/simo-local-verification/pr107-sqlite-verification/verify-20261008205641-994613/` and `verify-20261008205838-1014702/`.
- Independent before/after HEAD, index, staged diff and source patch snapshots matched. The original unrelated staged paths remain untouched; owned Docker resources were cleaned. At the verification checkpoint, no commit, push, PR mutation, merge or deployment had occurred. The existing remote `test-sqlite` check remains failed until a separately authorized publication triggers new CI.
- The user saw the verified results and authorized a local commit only. Commit identity will be recorded in the Engram mirror after the immutable task-document commit; no self-referential commit ID is claimed here.

Historical feature ledger: `odd/tasks/configurable-authority-extraction.md`; this follow-up does not claim native review of the accumulated tracker diff.
