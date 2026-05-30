# Tasks: ci-foundation

**Change**: ci-foundation
**Phase**: tasks
**Date**: 2026-05-12
**Mode**: hybrid

---

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~155 (breakdown below) |
| 400-line budget risk | Low |
| Chained PRs recommended | No |
| Suggested split | Single PR |
| Delivery strategy | ask-on-risk |
| Chain strategy | stacked-to-main |

Decision needed before apply: No
Chained PRs recommended: No
Chain strategy: stacked-to-main
400-line budget risk: Low

### Line estimate by file

| File | Estimated LOC |
|------|--------------|
| `tests/Feature/ExampleTest.php` (Cluster A) | ~3 |
| `database/factories/UserFactory.php` (Cluster B) | ~1 |
| `tests/Feature/Seeders/EntidadesPublicasBoliviaSeederTest.php` (Cluster C) | ~10 |
| `tests/Unit/Gemini/FiltroResultadoDTOTest.php` (Cluster D) | ~40 |
| `tests/Unit/Gemini/PromptReglasTest.php` (Cluster E) | ~3 |
| `tests/Feature/Services/GeminiFiltroNormalizacionTest.php` (Cluster F, conditional) | ~5–10 |
| `.github/workflows/test.yml` (T7) | ~50 |
| `README.md` (T8) | ~10 |
| **Total** | **~122–132 + F overhead = ~155** |

**Note**: Well inside the 400-line budget. Single PR is safe and recommended. If Cluster D fix grows beyond 60 LOC, flag for re-scope before continuing.

---

## Dependency Graph

```
T1 (Cluster A) ──┐
T2 (Cluster B) ──┤
T3 (Cluster C) ──┤──► T7 (workflow .yml) ──► T8 (README)
T4 (Cluster D) ──┤         ▲
T5 (Cluster E) ──┤         │ all 6 clusters green locally first
                 │
T4 (Cluster D) ──►──► T6 (Cluster F) — F may be cascade from D
                             │
                             └── if still red after D: add Http::preventStrayRequests() for diagnosis

Commit order: A → B → C → D → E → F → workflow → README (8 commits total)
```

---

## Phase 0: T0 — Baseline cluster fixes (6 tasks, 6 commits)

> **TDD note for T0**: These are FIX-EXISTING-TESTS tasks, not new-feature TDD.
> Workflow: run failing filter → confirm RED for the specific reason → apply minimal fix → confirm GREEN.
> No fake-RED ceremony needed. Document actual error before fixing.

---

- [x] **T1: Fix Cluster A — assert redirect to login on GET /**
  - **Files**: `tests/Feature/ExampleTest.php`
  - **SCN satisfied**: REQ-6 / SCN-6.1
  - **Approach**: `GET /` now redirects to login (routes/web.php:20). Change `assertStatus(200)` to `assertRedirect(route('login'))`.
  - **Estimated LOC**: ~3
  - **Pre-flight**: `php artisan test --filter=ExampleTest` — expect `Expected status code 200 but received 302`
  - **Verification**: re-run filter, confirm GREEN
  - **Commit message**: `test: fix Cluster A — assert redirect to login on GET /`

---

- [x] **T2: Fix Cluster B — set activo=true in UserFactory default**
  - **Files**: `database/factories/UserFactory.php`
  - **SCN satisfied**: REQ-6 / SCN-6.1
  - **Approach**: Add `'activo' => true` to `definition()` array (line 26). Factory omits the field; Eloquent reads in-memory attrs (not DB default), so boolean cast yields `false`, triggering `UsuarioActivo` middleware redirect. One line fixes all 5 ProfileTest failures at once.
  - **Estimated LOC**: ~1
  - **Pre-flight**: `php artisan test --filter=ProfileTest` — expect 5 failures, all `302` instead of `200`/redirect to `/profile`
  - **Verification**: re-run filter, confirm 5 tests GREEN
  - **Commit message**: `test: fix Cluster B — set activo=true in UserFactory default`

---

- [x] **T3: Fix Cluster C — adjust seeder count and entity names**
  - **Files**: `tests/Feature/Seeders/EntidadesPublicasBoliviaSeederTest.php`
  - **SCN satisfied**: REQ-6 / SCN-6.1
  - **Approach**: Seeder grew from 33 → 241+ entities (ministries replaced YPFB/ENTEL). In `test_seeder_inserts_records()`: replace `assertSame(33, ...)` with `assertGreaterThan(200, EntidadPublica::count())`. In `test_known_entities_exist_after_seeding()`: replace YPFB/ENTEL assertions with present-day entity names (e.g. `'Ministerio de Economía y Finanzas Públicas'` and `'Banco Central de Bolivia'` — the latter already exists in test line 49).
  - **Estimated LOC**: ~10
  - **Pre-flight**: `php artisan test --filter=EntidadesPublicasBoliviaSeederTest` — expect failures on count mismatch and YPFB/ENTEL not found
  - **Verification**: re-run filter, confirm GREEN
  - **Commit message**: `test: fix Cluster C — adjust seeder count and entity names`

---

- [x] **T4: Fix Cluster D — wrap fixtures in personas key**
  - **Files**: `tests/Unit/Gemini/FiltroResultadoDTOTest.php`
  - **SCN satisfied**: REQ-6 / SCN-6.1
  - **Approach**: `FiltroResultadoDTO::fromArray()` now requires `personas` wrapper (`{'personas': [...], 'motivo_general': '...'}`) — test fixtures still pass flat arrays. In `validData()`, wrap the returned array: `return ['personas' => [[ 'is_pep' => true, ...existing fields... ]], 'motivo_general' => 'Test motivo']`. Update every `unset($data['is_pep'])` etc. caller to target `$data['personas'][0]['campo']` instead of `$data['campo']`. Inspect `FiltroResultadoDTO::fromArray()` and `PersonaDetectadaDTO::fromArray()` signatures to match the exact expected shape.
  - **Estimated LOC**: ~40
  - **Pre-flight**: `php artisan test --filter=FiltroResultadoDTOTest` — expect `GeminiInvalidResponseException: Missing required field 'personas'` on every test
  - **Verification**: re-run filter — all 13 tests GREEN
  - **If fix exceeds 60 LOC**: STOP and flag for re-scope before committing
  - **Commit message**: `test: fix Cluster D — wrap fixtures in personas key`

---

- [x] **T5: Fix Cluster E — refresh stale prompt assertions**
  - **Files**: `tests/Unit/Gemini/PromptReglasTest.php`
  - **SCN satisfied**: REQ-6 / SCN-6.1
  - **Approach**: `test_prompt_contiene_reglas_de_clasificacion()` asserts `EXPLÍCITAMENTE` (not present in current prompt) and `SUJETO ACTIVO` (IS present in `buildReglasClasificacion()` line 194). Replace the `EXPLÍCITAMENTE` assertion with a string that actually exists in the current prompt (e.g. `'REGLAS DE CLASIFICACIÓN'` — already asserted — or another literal from the rules block). Verify with grep before choosing the replacement.
  - **Estimated LOC**: ~3
  - **Pre-flight**: `php artisan test --filter=PromptReglasTest` — expect `test_prompt_contiene_reglas_de_clasificacion` to fail on `EXPLÍCITAMENTE` assertion
  - **Verification**: re-run filter, confirm GREEN
  - **Commit message**: `test: fix Cluster E — refresh stale prompt assertions`

---

- [x] **T6: Fix Cluster F — verify Http::fake intercept after D (cascade check)**
  - **Files**: `tests/Feature/Services/GeminiFiltroNormalizacionTest.php`
  - **SCN satisfied**: REQ-6 / SCN-6.1, SCN-6.2
  - **Approach**: **MUST run AFTER T4 (Cluster D) is committed and green.** F tests already have `Http::fake()` correctly configured — suspected cascade from D's DTO shape mismatch. Re-run F after D is fixed. If green → no code change, commit message notes "cascade resolved by D". If still red → inspect actual error, add `Http::preventStrayRequests()` in `setUp()` for diagnosis, then fix the actual issue. Do NOT change `Http::fake()` payloads unless error confirms a shape mismatch specific to F.
  - **Estimated LOC**: ~5–10 (0 if cascade)
  - **Pre-flight**: AFTER T4 green — `php artisan test --filter=GeminiFiltroNormalizacionTest` — confirm whether still RED or already GREEN
  - **Verification**: all 5 tests GREEN; no real HTTP calls
  - **Commit message**: `test: fix Cluster F — verify Http::fake intercept after D`

---

## Phase 1: T1 — GitHub Actions workflow

- [x] **T7: Create `.github/workflows/test.yml`**
  - **Files**: `.github/workflows/test.yml` (create new)
  - **SCN satisfied**: REQ-1 (SCN-1.1–1.5), REQ-2 (SCN-2.1–2.3), REQ-3 (SCN-3.1–3.3), REQ-4 (SCN-4.1–4.3), REQ-5 (SCN-5.1–5.2)
  - **Approach**: Use the exact YAML from design §T1 (reproduced below). Deferred decisions resolved: `timeout-minutes: 10` for the job; `APP_ENV: testing` on the test step; `TZ: America/La_Paz` at workflow level; `concurrency.cancel-in-progress: ${{ github.event_name == 'pull_request' }}`.
  - **Estimated LOC**: ~50
  - **Pre-flight**: Verify T0 full suite passes locally — `php -d memory_limit=512M artisan test` exits 0
  - **Linting**: Run `npx @rhysd/actionlint .github/workflows/test.yml` (one-shot, no install). Fix any errors before committing.
  - **Verification**: Push to a scratch branch, confirm first CI run is green on GitHub Actions. Only then continue to T8.
  - **Commit message**: `ci: add tests workflow`

  ```yaml
  name: tests
  on:
    pull_request:
      branches: [main]
    push:
      branches: [main]
    workflow_dispatch:
  concurrency:
    group: tests-${{ github.workflow }}-${{ github.ref }}
    cancel-in-progress: ${{ github.event_name == 'pull_request' }}
  env:
    TZ: America/La_Paz
  jobs:
    test:
      name: test
      runs-on: ubuntu-latest
      timeout-minutes: 10
      steps:
        - uses: actions/checkout@v4
        - uses: shivammathur/setup-php@v2
          with:
            php-version: '8.2'
            extensions: pgsql, mbstring, xml, curl, zip, bcmath, intl, sqlite3
            coverage: none
        - id: composer-cache
          run: echo "dir=$(composer config cache-files-dir)" >> "$GITHUB_OUTPUT"
        - uses: actions/cache@v4
          with:
            path: ${{ steps.composer-cache.outputs.dir }}
            key: composer-${{ runner.os }}-${{ hashFiles('**/composer.lock') }}
            restore-keys: composer-${{ runner.os }}-
        - run: composer install --prefer-dist --no-progress --no-interaction
        - run: cp .env.example .env
        - run: php artisan key:generate
        - env:
            APP_ENV: testing
          run: php -d memory_limit=512M artisan test
  ```

---

## Phase 2: T2 — Documentation

- [x] **T8: Add "Continuous Integration" section to README.md**
  - **Files**: `README.md`
  - **SCN satisfied**: REQ-7 (SCN-7.1, SCN-7.2)
  - **Approach**: Append a `## Continuous Integration` section (3–5 sentences). Cover: what triggers the workflow (PR to main, push to main, manual dispatch), how to read a failing run (Actions tab → failed step → assertion message visible inline), and how to re-run manually. No secrets needed. No Postgres in CI (Fase 2 note optional).
  - **Estimated LOC**: ~10
  - **Verification**: Read the README, confirm section is present and accurate
  - **Commit message**: `docs: document CI workflow in README`

---

## Phase 3: Verification (post-implementation, pre-archive)

- [x] **T9: Local full-suite run** — Unit: 388 passed, Feature: 472 passed (1 incomplete, 9 skipped) — 0 failures
- [ ] **T10: PR opened, first CI run green** — workflow run on GitHub Actions completes successfully, no exclusions
- [ ] **T11 (optional smoke)**: Push a deliberate failing assertion on a scratch branch → confirm CI marks run as failed → revert scratch branch

---

## Open questions resolved here

| Question | Resolution |
|----------|-----------|
| `timeout-minutes` for composer install | N/A — timeout is at the job level, set to 10 min |
| `timeout-minutes` for entire job | 10 min (covers slow network; normal run is ~70 s) |
| actionlint as installed vs one-shot | One-shot via `npx @rhysd/actionlint` (not project-installed) |
| Workflow + README in same commit | Separate commits (T7 vs T8) — workflow can land hot; README needs editorial review |
| Cluster F code change needed? | Unknown until T4 lands — check cascade before writing any code |
| `EXPLÍCITAMENTE` replacement for Cluster E | Verify with grep at apply time; pick a literal present in current `buildReglasClasificacion()` |

---

## Out of scope (NOT tasks — explicit list)

- Postgres service container in CI (Fase 2 — separate SDD `ci-pgsql-tests`)
- Branch protection rules (manual GitHub UI)
- Code coverage reporting (Codecov/Coveralls)
- Static analysis (PHPStan/Psalm)
- Security scanning (CodeQL/Snyk)
- Deployment automation
- Frontend asset build (npm run build)
- Lint job (no pint/php-cs-fixer config)
- `Http::preventStrayRequests()` as global TestCase guard (future SDD)
- Nightly/scheduled runs
- Slack/Discord notifications
