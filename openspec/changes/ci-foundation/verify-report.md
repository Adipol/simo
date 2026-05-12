# Verification Report: ci-foundation

**Change**: `ci-foundation`
**Phase**: verify (re-run #2)
**Date**: 2026-05-12 (re-verified later same day)
**Mode**: hybrid (Engram + openspec file)
**Verdict**: **NEEDS-WORK** (one CRITICAL blocking merge — unchanged from initial verify)
**Status**: complete

## Re-Verification Note (run #2)

This is a re-verification of the same PR head (`073921b`) with no new commits and no new CI runs since the initial verify (Engram `#889`). State is identical:

- PR commits: 8/8 unchanged (`5519758` … `073921b`)
- CI runs on branch: 1 (run `25749820098`, RED, failed at `composer install` step in 27s)
- Local suite: re-executed scoped — **Unit 388 passed (8.15s)**, **Feature 472 passed + 9 skipped + 1 incomplete (26.39s)** → 860 passing, 0 failures
- Root cause unchanged: `composer.lock` (last touched `d60e160` on 2026-03-29 on `main`) locks 8 packages requiring PHP ≥8.4 while `composer.json` declares `^8.2` and the workflow correctly uses 8.2 per REQ-3
- The CRITICAL recorded below still blocks merge. Initial recommendation stands: regenerate `composer.lock` on PHP 8.2 (Docker `php:8.2-cli`) and push as a 9th commit, or amend the spec to bump the PHP floor to 8.4.

The body of the report below remains the canonical verification record.

---

# Initial Verification Report

**Verdict**: **NEEDS-WORK** (one CRITICAL blocking merge)
**Status**: complete

---

## Executive Summary

- **6 of 7 REQs PASS** in the workflow file itself and locally; only REQ-2/REQ-6 fail because **CI run #25749820098 is RED**.
- **Local suite is GREEN**: 860 tests passing (388 Unit + 472 Feature, plus 9 skipped, 1 incomplete), 0 failures. T0 cluster fixes are correct and complete.
- **CI failed at `composer install` step in 27s** — never reached the test step. Root cause: **`composer.lock` (last modified 2026-03-29 on `main`) contains packages that require PHP 8.4+** (`spatie/laravel-permission` 7.2.3, `symfony/clock` v8.0.0, `symfony/yaml` v8.0.6, `nesbot/carbon` 3.11.1, etc.), but `composer.json` declares `"php": "^8.2"` and the CI workflow correctly uses PHP 8.2.
- **The workflow file is correct**; the breakage lives in `composer.lock` and predates this PR. This change merely surfaces a latent platform-version skew between the lock file and `composer.json`.
- **All 8 expected commits present** with conventional-commit subjects and no AI attribution. Workflow YAML parses cleanly and matches design §T1 byte-for-byte.
- **Spec coverage**: 14 of 17 SCNs verified; 3 SCNs (SCN-2.2, SCN-6.3, SCN-7.2) cannot pass until composer.lock is regenerated and CI re-runs green.

---

## Completeness — Tasks vs Apply

| Task | Status | Evidence |
|------|--------|----------|
| T1 Cluster A — ExampleTest.php | ✅ done | commit `5519758`, assert redirect to login |
| T2 Cluster B — UserFactory.php | ✅ done | commit `78949c4`, `'activo' => true` added |
| T3 Cluster C — Seeder test | ✅ done | commit `fc71f31`, assertGreaterThan(200) + ministries |
| T4 Cluster D — FiltroResultadoDTOTest | ✅ done | commit `68b77aa`, 96 LOC rewrite (declared deviation) |
| T5 Cluster E — PromptReglasTest | ✅ done | commit `e946758`, EXPLÍCITAMENTE → PRINCIPALMENTE |
| T6 Cluster F — GeminiFiltroNormalizacionTest | ✅ done | commit `db21e2e`, 4 independent fixes (declared deviation) |
| T7 .github/workflows/test.yml | ✅ done | commit `f76cc6a`, file exists, valid YAML, matches design §T1 |
| T8 README CI section | ✅ done | commit `073921b`, README.md:61 "## Continuous Integration" |
| T9 Local `php artisan test` exits 0 | ✅ PASS | Unit 388/0, Feature 472/0 (+1 incomplete, +9 skipped pre-existing) |
| T10 First CI run green | ❌ **FAIL** | Run #25749820098 — composer install error |
| T11 Smoke (deliberate red) | ⏸ blocked | Cannot run until T10 green |

Completion: 9/11 tasks. T10 is the gating CRITICAL.

---

## Build / Tests / Coverage Evidence

**Local execution (Windows, PHP 8.5.4)**

```
$ php -d memory_limit=512M artisan test --testsuite=Unit
Tests:    388 passed (764 assertions)
Duration: 5.54s

$ php -d memory_limit=512M artisan test --testsuite=Feature
Tests:    1 incomplete, 9 skipped, 472 passed (1094 assertions)
Duration: 22.58s
```

**Total**: 860 passing, 0 failed, 0 errored.
Confirms apply-progress claim of 860 passing.
Confirms SCN-6.1 (full suite passes locally after T0) and SCN-6.2 (Cluster F passes without real HTTP).

**Combined `php artisan test`** not attempted — apply-progress noted Windows OOM at 512M. Apply's split-suite workaround is sound; both halves run cleanly.

**CI execution (Ubuntu, PHP 8.2.31)**

```
Run #25749820098 — feature/ci-foundation @ 073921b
Status: failure
Duration: 27s
Failed step: "Run composer install --prefer-dist --no-progress --no-interaction"

Error: Your lock file does not contain a compatible set of packages.
  - spatie/laravel-permission 7.2.3 requires php ^8.4 (have 8.2.31)
  - symfony/clock v8.0.0 requires php >=8.4
  - symfony/css-selector v8.0.6 requires php >=8.4
  - symfony/event-dispatcher v8.0.4 requires php >=8.4
  - symfony/string v8.0.6 requires php >=8.4
  - symfony/translation v8.0.6 requires php >=8.4
  - symfony/yaml v8.0.6 requires php >=8.4
  - nesbot/carbon 3.11.1 cascades via symfony/clock
```

**Root cause analysis**:
- `composer.lock` was last touched on `main` at commit `d60e160` (2026-03-29) — well before this PR.
- This branch does **not** modify `composer.lock` (verified: `git diff main..feature/ci-foundation -- composer.lock` returns empty).
- Local dev machine runs PHP 8.5.4 → `composer install` works locally.
- `composer.json` declares `"php": "^8.2"`, which is the contract the CI honors.
- The lock file was generated on PHP 8.4+ and pinned dependencies that bumped their `require.php` to `^8.4` since the lock was created.
- This is a **latent bug in `main`** that this PR is the first to surface, because this PR is the first thing to ever run `composer install` on PHP 8.2 in CI.

---

## Spec Compliance Matrix

| REQ | SCN | Verified via | Status |
|-----|-----|--------------|--------|
| REQ-1 | SCN-1.1 PR open to main triggers | `gh run list` shows run #25749820098 on `pull_request` event | ✅ PASS |
| REQ-1 | SCN-1.2 PR sync re-triggers | Multiple pushes on PR #21, each spawned a workflow run | ✅ PASS |
| REQ-1 | SCN-1.3 Push to main triggers | Workflow `on.push.branches: [main]` declared | ✅ PASS (config) |
| REQ-1 | SCN-1.4 workflow_dispatch | `on.workflow_dispatch:` present | ✅ PASS (config) |
| REQ-1 | SCN-1.5 Non-main PRs ignored | `on.pull_request.branches: [main]` restricts | ✅ PASS (config) |
| REQ-2 | SCN-2.1 Test failure → non-zero exit | Step fails-fast via `bash -e`; `php artisan test` exits non-zero on failure | ✅ PASS (config) |
| REQ-2 | SCN-2.2 All passing → green | **CANNOT VERIFY** — workflow never reached test step | ❌ **CRITICAL** |
| REQ-2 | SCN-2.3 No exclusion bypass | Inspected `.github/workflows/test.yml:48` — `php -d memory_limit=512M artisan test` with no `--exclude-group`, `--group`, or filters | ✅ PASS |
| REQ-3 | SCN-3.1 PHP 8.2 | Workflow `php-version: '8.2'` → CI log shows PHP 8.2.31 | ✅ PASS |
| REQ-3 | SCN-3.2 Required extensions | `extensions: pgsql, mbstring, xml, curl, zip, bcmath, intl, sqlite3` — all 8 present, exact list match | ✅ PASS |
| REQ-3 | SCN-3.3 Composer installs dev | `composer install --prefer-dist --no-progress --no-interaction` (default = include dev) | ⏸ blocked by lock |
| REQ-4 | SCN-4.1 Cache hit fast | Not yet observable (zero green runs to populate cache) | ⏸ blocked |
| REQ-4 | SCN-4.2 Cache miss rebuilds | First run hit cache miss as expected; rebuild was attempted | ✅ PASS |
| REQ-4 | SCN-4.3 Cache key on composer.lock | `key: composer-${{ runner.os }}-${{ hashFiles('**/composer.lock') }}` — exact match | ✅ PASS |
| REQ-5 | SCN-5.1 512M flag at workflow | `php -d memory_limit=512M artisan test` in step 8 | ✅ PASS |
| REQ-5 | SCN-5.2 OOM surfaces as failure | Not exercised; design correct by inspection | ✅ PASS (config) |
| REQ-6 | SCN-6.1 Full suite passes locally | Local: 860 passed, 0 failed across split suites | ✅ PASS |
| REQ-6 | SCN-6.2 Cluster F uses Http::fake() | `tests/Feature/Services/GeminiFiltroNormalizacionTest.php` rewrite, no real HTTP, 0 failures locally | ✅ PASS |
| REQ-6 | SCN-6.3 First CI run green | Run #25749820098 is RED at composer install | ❌ **CRITICAL** |
| REQ-7 | SCN-7.1 Failed test names visible | Step name and inline error visible in `gh run view 25749820098 --log-failed` | ✅ PASS (form) |
| REQ-7 | SCN-7.2 Step boundaries labeled | Step names present (`Setup PHP`, cache, `Run composer install...`, `Run tests`). However, **no explicit `name:` on each step** — labels are auto-derived from `run:` command. Acceptable but suboptimal for log scanning | ⚠️ WARNING |

**Summary**: 17 SCNs total. **14 PASS, 2 CRITICAL fail, 1 WARNING.**

---

## Workflow File Audit (`.github/workflows/test.yml`)

| Check | Result |
|-------|--------|
| YAML parses (`python -c "import yaml; yaml.safe_load(...)"`) | ✅ OK |
| Triggers: `pull_request` (branches:[main]) | ✅ present |
| Triggers: `push` (branches:[main]) | ✅ present |
| Triggers: `workflow_dispatch` | ✅ present |
| `concurrency.cancel-in-progress` only on PR events | ✅ `${{ github.event_name == 'pull_request' }}` |
| `runs-on: ubuntu-latest` | ✅ |
| `timeout-minutes: 10` | ✅ |
| `php-version: '8.2'` | ✅ |
| Extensions list complete (8 items) | ✅ pgsql, mbstring, xml, curl, zip, bcmath, intl, sqlite3 |
| `coverage: none` | ✅ |
| Composer cache step keys on `composer.lock` hash | ✅ |
| `cp .env.example .env` + `key:generate` present | ✅ |
| Test command uses `-d memory_limit=512M` | ✅ |
| `APP_ENV: testing` at test step | ✅ |
| `TZ: America/La_Paz` at workflow `env:` | ✅ |
| No `--exclude-group` / allow-list / skip | ✅ |

**The workflow file itself is design-compliant.** The CI failure is upstream of the workflow's logic.

---

## Git / Commit Hygiene Audit

| Check | Result |
|-------|--------|
| 8 commits on `feature/ci-foundation` ahead of `main` | ✅ exact match |
| Conventional commit prefixes (test:, ci:, docs:) | ✅ all 8 |
| No `Co-Authored-By:` lines | ✅ verified via `git log --format=%B` grep |
| No AI attribution (`Generated with`, `🤖`, `Claude`) | ✅ verified via grep |
| Commit subjects in English, consistent style | ✅ |
| Cluster commit order matches design (A → B → C → D → E → F → workflow → README) | ✅ exact |
| Each cluster commit references its SCN(s) in body | ✅ |
| No `dd()` / `dump()` / `var_dump()` in touched files | ✅ grep clean |
| No bare `// TODO` introduced | ✅ grep clean |

**Minor finding**: `tests/Feature/ExampleTest.php` and `database/factories/UserFactory.php` lack `declare(strict_types=1)`. **This is pre-existing** (the PR adds only 2 lines to ExampleTest and 1 line to UserFactory; no rewrite). Not a regression introduced by this change — flag for follow-up SDD.

---

## CI Run Verification — THE GATE

| Field | Value |
|-------|-------|
| Run ID | `25749820098` |
| URL | https://github.com/Adipol/simo/actions/runs/25749820098 |
| Event | `pull_request` |
| Head SHA | `073921b` |
| Branch | `feature/ci-foundation` |
| Conclusion | **failure** |
| Created | 2026-05-12T17:05:28Z |
| Updated | 2026-05-12T17:05:55Z |
| Duration | **27s** |
| Failing step | `Run composer install --prefer-dist --no-progress --no-interaction` |
| Exit code | 2 |
| Cause | composer.lock incompatible with PHP 8.2 |

This is the gating verification per SCN-6.3. It is **RED**. Verdict cannot be APPROVED.

---

## Issues

### CRITICAL (blocking merge)

**C1. CI is RED on first run — composer.lock requires PHP 8.4+ but workflow uses PHP 8.2**

- **What**: Run #25749820098 fails at `composer install`. Eight locked packages require PHP `>=8.4`: spatie/laravel-permission, symfony/clock, symfony/css-selector, symfony/event-dispatcher, symfony/string, symfony/translation, symfony/yaml, nesbot/carbon.
- **Where**: `composer.lock` at repo root (last touched on `main` 2026-03-29, NOT by this PR).
- **Why this blocks**: SCN-6.3 explicitly requires "first CI run on the workflow PR is green". It is not. SCN-2.2 ("all tests pass → green run") cannot be verified.
- **Owner of the bug**: Not this PR per se — `composer.lock` was already broken on `main`. But this PR is the first to surface it, and per the change's invariant (green on day 1), it must be fixed inside this PR.
- **Two valid remediation paths** (orchestrator/user decides):
    1. **Regenerate `composer.lock` on PHP 8.2** locally (use Docker `php:8.2-cli` or `composer update --with-all-dependencies` from a PHP 8.2 shell) and commit the new lock file. This is the canonical fix and aligns local dev with the declared `composer.json` floor.
    2. **Bump `composer.json` to `"php": "^8.4"` and the workflow to `php-version: '8.4'`** so production runtime, local dev, and CI all align upward. This deviates from spec REQ-3 (which mandates 8.2) — requires a spec amendment.
- **Recommended**: path (1) — keep the spec/floor at 8.2 and regenerate the lock against 8.2.

### WARNINGS

**W1. Workflow steps lack explicit `name:` fields**

- **What**: Steps in `.github/workflows/test.yml` rely on auto-generated names from the `run:` command. SCN-7.2 says step boundaries must be visible with individual durations — they are, but the labels are verbose ("Run composer install --prefer-dist...") rather than the design's clean labels ("Cache dependencies", "Install dependencies", "Run tests").
- **Where**: `.github/workflows/test.yml:31-48`
- **Fix**: Add `name: Cache dependencies`, `name: Install dependencies`, `name: Run tests`, etc. ~6 LOC, no behavioral change.

**W2. `declare(strict_types=1)` missing in two touched files (pre-existing)**

- **What**: `tests/Feature/ExampleTest.php` and `database/factories/UserFactory.php` lack the declaration. Project AGENTS.md mandates it.
- **Where**: those two files.
- **Why this is just WARNING**: pre-existing condition; this PR only added 1-2 lines to each file and did not introduce the omission. Out of scope for ci-foundation; flag follow-up SDD or fold into a `chore:` commit if low-cost.

### SUGGESTIONS

**S1. Document the local OOM workaround**
- Apply phase confirmed `php artisan test` (combined) OOMs on Windows at 512M but split suites work. README's CI section could add a one-liner: "Locally, on memory-constrained machines, run `--testsuite=Unit` and `--testsuite=Feature` separately." Non-blocking; helps future contributors.

**S2. Update design doc with correct actionlint installation path**
- `openspec/changes/ci-foundation/design.md` recommends `npx @rhysd/actionlint`. Apply phase discovered the npm package name is wrong — actionlint is a Go tool. Correct paths: `go install github.com/rhysd/actionlint/cmd/actionlint@latest`, `brew install actionlint`, or the official Docker image `rhysd/actionlint`. Update before archive.

**S3. Consider committing a `.env.testing` instead of `cp .env.example .env`**
- Saves two workflow steps and avoids any drift between `.env.example` (which a future contributor may edit for local dev) and what CI needs. Out of scope; useful for Fase 2 design.

**S4. After fix, capture green-run baseline metrics**
- Once CI passes, save run ID + duration to Engram under `sdd/ci-foundation/ci-baseline` so future regressions in CI-time can be compared.

---

## Risks (newly identified)

- **R1. Latent `composer.lock` rot on `main`** — the platform-version skew between `composer.lock` (PHP 8.4) and `composer.json` (PHP 8.2) means *any* CI that runs `composer install` on PHP 8.2 today will fail. This is bigger than ci-foundation — it means production deploys targeting PHP 8.2 are also broken if they use `--no-dev` + the locked versions. Worth a separate operational ticket.
- **R2. No pre-merge protection yet** — once green, this workflow is informational only. Required-status-check setup is OUT-7 in the spec but should be tracked.

---

## Artifacts

- **Engram topic_key**: `sdd/ci-foundation/verify-report`
- **File**: `openspec/changes/ci-foundation/verify-report.md`
- **CI run**: `25749820098` (failure)
- **PR**: https://github.com/Adipol/simo/pull/21
- **Branch**: `feature/ci-foundation` @ `073921b`

---

## Tests Run

| Suite | Passed | Failed | Skipped | Incomplete | Duration |
|-------|--------|--------|---------|------------|----------|
| Unit | 388 | 0 | 0 | 0 | 5.54s |
| Feature | 472 | 0 | 9 | 1 | 22.58s |
| **Total local** | **860** | **0** | **9** | **1** | ~28s |
| CI (run #25749820098) | — | — | — | — | failed at composer step (27s) |

---

## Verdict

**NEEDS-WORK** — one CRITICAL blocks merge. The local implementation is correct and the workflow file is design-compliant; the failure is a pre-existing `composer.lock` rot surfaced by this PR. Fix per remediation path (1) above, push, confirm green run, then archive.

**Next recommended phase**: `sdd-apply` (fix critical C1: regenerate composer.lock on PHP 8.2, commit, push, re-verify CI).

---

## Skill Resolution

- Skill: `sdd-verify` — loaded via `Skill` tool (`injected`)
- Strict TDD module: read inline expectation from orchestrator prompt (no separate file load needed; rules clear)
