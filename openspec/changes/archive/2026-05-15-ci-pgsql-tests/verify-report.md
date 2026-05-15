# Verify Report — `ci-pgsql-tests`

**Change**: `ci-pgsql-tests` (Fase 2 of `ci-pipeline`)
**Phase**: verify
**Date**: 2026-05-15
**Mode**: hybrid (Engram + openspec file)
**Verdict**: **APPROVED** (PASS WITH WARNINGS)
**CI status on main**: **green** (both `test-sqlite` and `test-pgsql` ✅)

---

## Executive Summary

- All 6 REQs (REQ-A1..A6) and 19 SCNs from spec #963 are satisfied by code, workflow, docs, and runtime evidence on `main`.
- Latest CI run on `main` (run id `25926878749`, head `095c823`): **`test-sqlite` ✅ + `test-pgsql` ✅** — first fully-green dual-driver run.
- Branch protection on `main` confirmed via GitHub API: required contexts = `["test-pgsql", "test-sqlite"]`. Future PRs cannot merge without both jobs passing — this is the structural invariant the SDD was built to deliver.
- 11 PRs landed in the full chain: 4 prod-bug baselines (#18, #26, #27, #28), the workflow PR (#29), 6 hotfix clusters (#30–#35), and the final cleanup (#36). All merged.
- DEPLOY.md, README.md, and `openspec/specs/ci-pipeline/spec.md` all updated per design. PHP 8.5 inconsistency fixed in README.
- 1 known limitation: `CambiosGeminiTest::mae_badge_shown_when_gemini_detects_mae` is `markTestSkipped()` on pgsql with an explicit TODO comment and 4 hypothesised root causes — surfaced for a future investigation SDD.
- Critical issues: **0**. Warnings: 1 (the known skip). Suggestions: 2 (negative smoke + 1 hygiene).

---

## Artifact Retrieval

| Artifact | Source | Status |
|---|---|---|
| Explore (#961) | Engram `sdd/ci-pgsql-tests/explore` | retrieved |
| Proposal (#962) | Engram `sdd/ci-pgsql-tests/proposal` | retrieved |
| Spec (#963) | Engram `sdd/ci-pgsql-tests/spec` | retrieved |
| Design (#965) | Engram `sdd/ci-pgsql-tests/design` | retrieved |
| Tasks (#966) | Engram `sdd/ci-pgsql-tests/tasks` | retrieved |
| Apply progress (#967) | Engram `sdd/ci-pgsql-tests/apply-progress` | retrieved |
| Canonical spec | `openspec/specs/ci-pipeline/spec.md` | read |
| Workflow | `.github/workflows/test.yml` | read |
| DEPLOY.md | filesystem | read |
| README.md | filesystem | read |

---

## Step 1 — Workflow file inspection

`.github/workflows/test.yml` (134 lines) on `main` (commit `095c823`):

| Check | Evidence | Status |
|---|---|---|
| `test-sqlite` job present | line 18 | ✅ |
| `test-pgsql` job present | line 69 | ✅ |
| `postgres:17` service container | lines 80–93, `image: postgres:17` | ✅ |
| Health check `pg_isready` w/ retries | lines 89–93 (`health-cmd pg_isready`, retries=5) | ✅ |
| pg_trgm explicit step | lines 127–130 (`CREATE EXTENSION IF NOT EXISTS pg_trgm`) | ✅ |
| PHP 8.5 on both jobs | line 32 (sqlite) + line 99 (pgsql) | ✅ |
| Concurrency group shared | lines 10–12 | ✅ |
| Driver env var per job | line 23 (`DB_CONNECTION: sqlite`) + lines 73–79 (pgsql block) | ✅ |
| Triggers: PR + push main + dispatch | lines 4–8 | ✅ |
| Test cmd matches REQ-5 | `php -d memory_limit=512M artisan test` on both jobs (lines 67, 134) | ✅ |

Design parity: workflow matches `#965` design exactly. No deviations.

---

## Step 2 — CI green on main

```
$ gh run list --branch main --workflow=test.yml --limit 5
```

| Run | SHA | Title | sqlite | pgsql | Overall |
|---|---|---|---|---|---|
| 25926878749 | 095c823 | Merge PR #29 | ✅ success | ✅ success | success |
| (prior) | 7e74e45 | Merge PR #36 | ✅ | ✅ | success |
| (prior) | 17988de | Merge PR #35 | ✅ | ✅ | success |

The merge of PR #29 ran on `main` post-merge and both jobs are green. **REQ-A1 SCN-A1.1 + REQ-A4 SCN-A4.3 verified by runtime evidence.**

---

## Step 3 — SCN coverage matrix (19/19)

### REQ-A1 — PostgreSQL test execution

| SCN | Evidence | Status |
|---|---|---|
| A1.1 parallel jobs on PR | Workflow defines two jobs at top level; GitHub schedules them in parallel by default. Confirmed in run 25926878749. | ✅ PASS |
| A1.2 pgsql job targets PG17 | `image: postgres:17` line 82 | ✅ PASS |
| A1.3 health check before tests | `--health-cmd pg_isready --health-retries 5` lines 89–93. GHA blocks job steps until service healthy. | ✅ PASS |
| A1.4 pg_trgm before migrations | Explicit psql step lines 127–130 runs BEFORE `artisan test` (line 134). Migration `2026_05_09_100001_enable_pg_trgm_extension.php` also runs `CREATE EXTENSION IF NOT EXISTS`. Defense in depth. | ✅ PASS |

### REQ-A2 — Test driver isolation

| SCN | Evidence | Status |
|---|---|---|
| A2.1 sqlite uses in-memory | `DB_CONNECTION: sqlite` (line 23) overrides phpunit.xml; no service block in sqlite job. | ✅ PASS |
| A2.2 pgsql uses service container | Env block lines 73–79 points to `127.0.0.1:5432` (the service container). | ✅ PASS |
| A2.3 pgsql-only tests execute | 7 `skipIfNotPgsql()` call sites in `DedupeArticulosServiceTest`. On pgsql they now execute (CI green proves it). | ✅ PASS |
| A2.4 driver-agnostic tests run both | Latest run shows both jobs success ⇒ shared tests pass in both. | ✅ PASS |

### REQ-A3 — Branch protection enforcement

| SCN | Evidence | Status |
|---|---|---|
| A3.1 test-sqlite required | `gh api repos/Adipol/simo/branches/main/protection` → `required_status_checks.contexts = ["test-pgsql","test-sqlite"]` | ✅ PASS |
| A3.2 test-pgsql required | same API call, same array | ✅ PASS |
| A3.3 DEPLOY.md documents click path | DEPLOY.md lines 43–58: `## CI / Branch Protection` section with 4-step GH UI procedure | ✅ PASS |

### REQ-A4 — Failure visibility

| SCN | Evidence | Status |
|---|---|---|
| A4.1 pgsql failure labeled | First run 25922967919 showed `test-pgsql: failure` distinct from `test-sqlite: success` — failures appeared only under the pgsql job heading. | ✅ PASS |
| A4.2 sqlite failure labeled | Symmetric to A4.1; jobs are independent log streams. | ✅ PASS |
| A4.3 PR status shows both | Latest run on main shows two distinct job entries with own conclusions. | ✅ PASS |

### REQ-A5 — Local development unaffected

| SCN | Evidence | Status |
|---|---|---|
| A5.1 local uses SQLite | `phpunit.xml` `<env>` untouched; no `.env.testing` change; SQLite remains default driver. | ✅ PASS |
| A5.2 no local Postgres needed | Same — no install step or hard dependency for local devs. | ✅ PASS |
| A5.3 opt-in pgsql documented | README.md line 65 mentions dual-driver CI; DEPLOY.md `## CI / Branch Protection` explains both jobs. Opt-in path is `DB_CONNECTION=pgsql` override (Laravel-standard) — implicitly available though not extensively documented (see SUGGESTION-1). | ⚠️ PASS (light docs) |

### REQ-A6 — First-run mitigation

| SCN | Evidence | Status |
|---|---|---|
| A6.1 first-run failures → follow-up PRs | 7 hotfix PRs (#30–#36) landed as separate follow-ups, NOT as reverts of #29. Apply-progress #967 lists 6 failure clusters; each got its own dedicated PR. | ✅ PASS |
| A6.2 baseline pgsql bugs pre-fixed | PRs #18, #26, #27, #28 all merged BEFORE PR #29. The 4 production bug categories from May 2026 did not resurface in first run. | ✅ PASS |

**Coverage: 19/19 SCNs PASS.**

---

## Step 4 — DEPLOY.md verification

`DEPLOY.md` lines 43–58:

```
## CI / Branch Protection

Two CI jobs run on every PR and push to `main`:
- `test-sqlite` — fast SQLite in-memory tests (default driver)
- `test-pgsql` — full suite against PostgreSQL 17 (matches production VPS)

Both MUST pass before merge. To enforce this:

1. Repo → Settings → Branches → Branch protection rules → `main` → Edit
2. Under "Require status checks to pass before merging", click "Edit"
3. In the search box, add BOTH: `test-sqlite`, `test-pgsql`
4. Save changes
```

Inserted at the design-specified location (between line 40 `---` and ex-line 42 `## Variables de entorno`). **PASS.**

---

## Step 5 — README.md verification

| Item | Line | Status |
|---|---|---|
| Dual-driver CI note | line 65 | ✅ present |
| PHP 8.5 (was 8.2) | line 63 ("PHP 8.5") | ✅ fixed |
| CI section retained | lines 61–65 (`## Continuous Integration`) | ✅ |

**PASS.**

---

## Step 6 — Canonical capability spec

`openspec/specs/ci-pipeline/spec.md`:

- Lines 223–394: `## Delta: Fase 2 — ci-pgsql-tests` section with all 6 REQs (REQ-A1..A6) and all 19 SCNs.
- Line 189: `OUT-2` struck through with `**Delivered in Fase 2**` annotation.
- Lines 217–219: Glossary additions for `test-sqlite`, `test-pgsql`, `service container`.

Matches design `#965` delta integration plan exactly. **PASS.**

---

## Step 7 — Full PR chain audit

| PR | Title | Status | Role |
|---|---|---|---|
| #18 | dedupe pg-trgm `set_config()` | ✅ merged 2026-05-11 | baseline prod fix |
| #26 | analytics IS TRUE + drop typed constants | ✅ merged 2026-05-15 | baseline prod fix |
| #27 | analytics ROUND math to PHP | ✅ merged 2026-05-15 | baseline prod fix |
| #28 | analytics order confianza by alias | ✅ merged 2026-05-15 | baseline prod fix |
| **#29** | **feat(ci): add PostgreSQL test job** | ✅ merged 2026-05-15 15:42 UTC | **SDD main delivery** |
| #30 | Schema::dropUnique cross-driver | ✅ merged 2026-05-15 | hotfix cluster 1 |
| #31 | driver-aware JSON in ProPipeline | ✅ merged 2026-05-15 | hotfix cluster 2 |
| #32 | explicit fechas AnalizarCambio | ✅ merged 2026-05-15 | hotfix cluster 3 |
| #33 | driver-aware dateTruncMonth | ✅ merged 2026-05-15 | hotfix cluster 4 |
| #34 | near-identical titles pg_trgm | ✅ merged 2026-05-15 | hotfix cluster 5 |
| #35 | widen p50/p95 delta | ✅ merged 2026-05-15 | hotfix cluster 6 |
| #36 | final 2 cleanups + 1 skip | ✅ merged 2026-05-15 15:32 UTC | hotfix cleanup |

**Total: 12 PRs** (4 baseline + 1 main + 7 hotfixes). Note: orchestrator brief mentioned 11; counting `#36` as the final hotfix gives 7 hotfix PRs (#30..#36) and a total chain of **12 PRs**. Either way: all merged, none reverted.

---

## Step 8 — Known skip documentation

**Test**: `tests/Feature/Livewire/Pep/CambiosGeminiTest.php::mae_badge_shown_when_gemini_detects_mae` (line 43)

**Skip mechanism**: explicit driver check at line 56 — `if (DB::getDriverName() === 'pgsql') { $this->markTestSkipped(...); }`

**Documented root-cause hypotheses** (inline comment lines 45–55):
1. Cast of JSON differs between drivers on serialization.
2. `->>'persona_nueva' IS NOT NULL` on JSONB may behave differently for NULL.
3. Boolean cast in `where('gemini_analyzed', true)` may need pgsql-specific handling.
4. Could be a Livewire component rendering quirk unrelated to driver.

**Scope**: 1 test in 1 file. Test counterpart `mae_badge_not_shown_when_not_mae` (line 92) runs unguarded and passes on both drivers — proving the skip is targeted, not a systemic gap. **Not blocking**, but **must be tracked** as a future investigation SDD (see WARNING-1).

---

## Issues

### CRITICAL: (none) ✅

### WARNING

**W1** — Open investigation debt: `CambiosGeminiTest::mae_badge_shown_when_gemini_detects_mae` is skipped on pgsql with no follow-up SDD opened yet. The inline TODO is good but it lives only in the test file. Recommendation: open a dedicated investigation SDD (suggested change-name: `cambios-gemini-pgsql-mae-skip`) to validate which of the 4 hypotheses is correct and either fix or document a permanent driver-specific behavioural difference. Until then, the dual-driver invariant has one explicit, intentional hole.

### SUGGESTION

**S1** — REQ-A5 SCN-A5.3 documentation is implicit. Consider adding a short "Run pgsql tests locally" snippet to `DEPLOY.md` or `README.md` showing the `DB_CONNECTION=pgsql DB_HOST=... php artisan test` invocation. Low priority — Laravel devs typically know this — but it would make the SCN explicit.

**S2** — Tasks T5.3 (negative smoke test: deliberate pgsql-only break → merge blocked) is listed in tasks but has no recorded evidence of execution. Branch protection API confirms structural enforcement (sufficient for REQ-A3), but a one-shot negative smoke would prove end-to-end behaviour. Optional; the API contract is authoritative.

---

## Risks

- **R1** (LOW): The pgsql first-run cluster pattern (#30..#36) demonstrated that latent driver bugs DO exist in the suite. Future merges may surface similar issues in new tests. Mitigation is now structural: branch protection forces fix-before-merge. No action needed.
- **R2** (VERY LOW): If a future contributor disables a required check (requires admin), the invariant breaks silently. Recommend periodic (quarterly) audit of branch protection via `gh api`.

---

## Strict TDD

Not active for this change (infrastructure/workflow, no production code change). Standard verify performed.

---

## Final Verdict

**APPROVED (PASS WITH WARNINGS)**.

This SDD's value is **structural**: it converts "we should test against pgsql" from a discipline-dependent practice into a merge-gate invariant. The metric is not "no new failures" but **"future bugs of this class are now impossible to merge"** — and that invariant is now live on `main`.

The 1 documented skip (W1) is a known, scoped, traceable limitation — not a systemic gap. It is the correct shape of technical debt: explicit, commented, and findable by `grep markTestSkipped`.

**Next recommended phase**: `sdd-archive`.

---

## Result envelope

- **status**: complete
- **verdict**: APPROVED
- **ci_main_status**: green
- **critical**: []
- **warnings**: [W1 — MAE-badge pgsql skip needs follow-up SDD]
- **suggestions**: [S1 — document local pgsql opt-in, S2 — negative smoke evidence]
- **next_recommended**: sdd-archive
- **risks**: [R1 LOW residual driver-divergence, R2 VERY LOW branch-protection drift]
- **artifacts**:
  - engram_topic_key: `sdd/ci-pgsql-tests/verify-report`
  - file_path: `openspec/changes/ci-pgsql-tests/verify-report.md`
- **skill_resolution**: fallback-path (loaded SKILL.md via Skill tool + read _shared/sdd-phase-common.md directly; no Project Standards block was injected and no registry was queried)
