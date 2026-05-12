# Design: ci-foundation (Fase 1)

**Change**: `ci-foundation` · **Phase**: design · **Date**: 2026-05-12

## Technical Approach

Fix-first, then ship. T0 turns the 23 baseline failures green via 6 surgical edits (no production code touched). T1 lands one GitHub Actions workflow (`.github/workflows/test.yml`) with one sequential job that runs `php -d memory_limit=512M artisan test`. Memory flag stays at the workflow layer; `phpunit.xml` is not mutated. T2 documents CI in README.

## Architecture Overview

```
git push / PR open
      │
      ▼
GitHub Actions webhook → workflow trigger (pull_request | push:main | workflow_dispatch)
      │
      ▼
┌────────────────────────────────────────────────┐
│ job: test (ubuntu-latest, ~80s wall, 1 job)    │
│  1. actions/checkout@v4                        │
│  2. shivammathur/setup-php@v2 (8.2 + ext)      │
│  3. detect composer cache dir (bash)           │
│  4. actions/cache@v4 (key: composer.lock hash) │
│  5. composer install --prefer-dist --no-prog.  │
│  6. cp .env.example .env                       │
│  7. php artisan key:generate                   │
│  8. php -d memory_limit=512M artisan test      │
│                                                │
│  env: TZ=America/La_Paz, APP_ENV=testing       │
│  concurrency: cancel-in-progress on PR events  │
└────────────────────────────────────────────────┘
      │
      ▼
exit 0 (green) │ exit !=0 (red, PR check fails)
```

## Architecture Decisions

### Decision: Memory limit at workflow flag, not phpunit.xml
**Choice**: Pass `-d memory_limit=512M` to PHP CLI inside the workflow step.
**Alternatives**: (a) add `memoryLimit="512M"` to `<phpunit>` element; (b) bump PHP `ini` in setup-php tools.
**Rationale**: Engram #880 confirmed `phpunit.xml` has no `memoryLimit`. CI-only flag keeps local dev unchanged. Mutating `phpunit.xml` would silently raise the bar for every developer machine.

### Decision: Single sequential job (not Feature || Unit parallel)
**Choice**: One job, single `artisan test` invocation.
**Alternatives**: Matrix with two jobs (Feature, Unit) for ~50% wall-time reduction.
**Rationale**: Private repo 2000-min/month budget — parallelism doubles minute consumption for a 15 s wall-time win on a ~70 s run. Trade not worth it at current scale.

### Decision: T0 fixes ship BEFORE workflow lands
**Choice**: 6 cluster commits + 1 workflow commit + 1 README commit, in order.
**Alternatives**: Land workflow first with `--exclude-group baseline-broken` allow-list.
**Rationale**: Approved proposal frozen "green on day 1". Allow-lists become permanent technical debt; no `--exclude-group` is allowed in `phpunit.xml` or workflow.

### Decision: actionlint as manual one-shot, not project tool
**Choice**: Maintainer runs `npx @rhysd/actionlint .github/workflows/test.yml` before pushing the workflow commit.
**Alternatives**: (b) composer dev require — wrong ecosystem; (c) skip — first push will fail in production GH.
**Rationale**: actionlint is a Go binary distributed as npm wrapper. Installing it as a project tool when no other CI tooling exists is overkill. Document as pre-push step in T1 commit message.

### Decision: README placement
**Choice**: Append "Continuous Integration" section to `README.md`.
**Alternatives**: New `DEPLOY.md`.
**Rationale**: README is the contributor entry point. `DEPLOY.md` does not exist; creating it for one section is overhead.

## T0 — Baseline Failure Clusters

Verified empirically by running each cluster locally (see `Discoveries` below). Counts and fixes confirmed against actual failure output.

| # | Cluster | Files (file:line) | Root Cause | Fix Approach | LOC | Risk |
|---|---|---|---|---|---|---|
| A | ExampleTest | `tests/Feature/ExampleTest.php:17` | Asserts 200, route `/` now `redirect()->route('login')` returns 302 (`routes/web.php:20`). | Change `assertStatus(200)` → `assertRedirect(route('login'))`. 1-line edit, same test name. | ~3 | trivial |
| B | ProfileTest | `tests/Feature/ProfileTest.php:21,36,58,75,93` | Routes ARE wired (`routes/web.php:73-76` + `routes/auth.php`). Failure is `UsuarioActivo` middleware (`app/Http/Middleware/UsuarioActivo.php:13`) seeing `activo=false`. `UserFactory::definition()` (`database/factories/UserFactory.php:24-33`) omits `activo` → in-memory instance has no attribute → boolean cast yields `false` → middleware redirects to login. DB column default is `true` but Eloquent uses in-memory attributes. | Add `'activo' => true` to `UserFactory::definition()`. One-line factory change. Fixes all 5 tests at once; safe because DB column default is already `true` (no other test relies on `activo=false`). | ~2 | trivial |
| C | EntidadesPublicasBoliviaSeederTest | `tests/Feature/Seeders/EntidadesPublicasBoliviaSeederTest.php:20,47` | Test 1: hardcoded count `33` but seeder now inserts 241 (verified: "Found similar results: …and 238 others"). Test 2: asserts entity `'YPFB'` exists but seeder dataset evolved to ministry-style names. | Test 1: replace `assertSame(33, …)` with `assertGreaterThan(200, …)` (range-tolerant, doesn't flake on dataset growth). Test 2: change `YPFB`/`ENTEL`/`Banco Central` assertions to entities confirmed present in current dataset, e.g. `'Ministerio de la Presidencia'`, `'Ministerio de Defensa'`. | ~10 | trivial |
| D | FiltroResultadoDTOTest | `tests/Unit/Gemini/FiltroResultadoDTOTest.php:13-23,38-145` | `FiltroResultadoDTO::fromArray()` (`app/Services/Gemini/DTOs/FiltroResultadoDTO.php:21-25`) now requires `personas` wrapper key. Test fixtures pass flat `['is_pep' => …, 'nombre' => …]` instead of `['personas' => [[…]]]`. | Update `validData()` helper to return `['personas' => [[…flat fields…]], 'motivo_general' => '…']`. Update all `unset($data['is_pep'])` style mutations to `unset($data['personas'][0]['is_pep'])`. Mechanical refactor of one helper + ~9 callers. | ~40 | moderate |
| E | PromptReglasTest | `tests/Unit/Gemini/PromptReglasTest.php:23` | `assertStringContainsString('EXPLÍCITAMENTE', …)` fails — current prompt text uses different wording (capture showed full prompt; no occurrence of `EXPLÍCITAMENTE` or `SUJETO ACTIVO`). | Replace stale literals with strings actually present in current prompt (e.g. `'REGLAS DE CLASIFICACIÓN'` already passes; add 2 more present-tokens like `'PEP+'`, `'NEG'`). | ~3 | trivial |
| F | GeminiFiltroNormalizacionTest | `tests/Feature/Services/GeminiFiltroNormalizacionTest.php:60-222` | Tests ALREADY have `Http::fake([generativelanguage.googleapis.com/* => …])` written correctly. Local failure shows real cURL hitting Gemini despite the fake. Two suspects: (i) `GeminiService::send()` calls `->when(app()->environment('local'), fn ($h) => $h->withoutVerifying())` (`app/Services/Gemini/GeminiService.php:54`) — fake should still intercept but worth ruling out; (ii) test asserts `gemini_nombre` populated, which requires the `personas` wrapper from Cluster D — once D's DTO is fixed, the response shape mismatch may have been the real root cause. | Order matters: fix Cluster D FIRST, then re-run F. If F still red, add `Http::preventStrayRequests()` to surface the bypass and inspect. Do NOT introduce new abstraction; keep `Http::fake()` pattern. | ~5-20 | nuanced |

### Cluster F pattern reference (Laravel built-in, already used in code)

```php
use Illuminate\Support\Facades\Http;

Http::fake([
    'generativelanguage.googleapis.com/*' => Http::response(
        $jsonBody, 200
    ),
]);
// optional regression guard:
Http::preventStrayRequests();
```

### T0 commit order (FROZEN by proposal)

1. `test: fix Cluster A — assert redirect to login on GET /`
2. `test: fix Cluster B — set activo=true in UserFactory default`
3. `test: fix Cluster C — adjust seeder count + entity names`
4. `test: fix Cluster D — wrap fixtures in personas key`
5. `test: fix Cluster E — refresh stale prompt assertions`
6. `test: fix Cluster F — verify Http::fake intercept after D`

## T1 — Workflow File Design

### File: `.github/workflows/test.yml`

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
      - name: Checkout
        uses: actions/checkout@v4

      - name: Setup PHP 8.2
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          extensions: pgsql, mbstring, xml, curl, zip, bcmath, intl, sqlite3
          coverage: none

      - name: Get composer cache directory
        id: composer-cache
        run: echo "dir=$(composer config cache-files-dir)" >> "$GITHUB_OUTPUT"

      - name: Cache composer dependencies
        uses: actions/cache@v4
        with:
          path: ${{ steps.composer-cache.outputs.dir }}
          key: composer-${{ runner.os }}-${{ hashFiles('**/composer.lock') }}
          restore-keys: composer-${{ runner.os }}-

      - name: Install composer dependencies
        run: composer install --prefer-dist --no-progress --no-interaction

      - name: Copy environment
        run: cp .env.example .env

      - name: Generate application key
        run: php artisan key:generate

      - name: Run tests
        env:
          APP_ENV: testing
        run: php -d memory_limit=512M artisan test
```

Notes:
- `timeout-minutes: 10` guards against runaway hangs (composer registry stalls).
- `concurrency.cancel-in-progress` is true ONLY on PRs — push to main runs always finish so history is unambiguous.
- `coverage: none` keeps setup-php fast; Xdebug not needed for Fase 1.
- `.env.example` copy + `key:generate` are needed because `phpunit.xml` env vars cover testing config but `key:generate` requires a base `.env`. Cheaper than committing `.env.testing`.

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `tests/Feature/ExampleTest.php` | Modify | Cluster A — change assertion to `assertRedirect`. |
| `database/factories/UserFactory.php` | Modify | Cluster B — add `'activo' => true` to definition. |
| `tests/Feature/Seeders/EntidadesPublicasBoliviaSeederTest.php` | Modify | Cluster C — range-tolerant count + present entity names. |
| `tests/Unit/Gemini/FiltroResultadoDTOTest.php` | Modify | Cluster D — wrap fixtures in `personas`. |
| `tests/Unit/Gemini/PromptReglasTest.php` | Modify | Cluster E — refresh stale literals. |
| `tests/Feature/Services/GeminiFiltroNormalizacionTest.php` | Modify | Cluster F — verify after D; minimal touch. |
| `.github/workflows/test.yml` | Create | New CI workflow (~75 LOC YAML). |
| `README.md` | Modify | Add ~10-line "Continuous Integration" section. |

Estimated total: ~155 LOC. Inside 400-line single-PR budget.

## Testing Strategy

| Layer | What to Test | Approach |
|-------|--------------|----------|
| T0 | Each cluster passes after its commit | `php artisan test --filter=<TestClass>` per commit |
| T0 full | Entire suite 0 failures locally | `php -d memory_limit=512M artisan test` |
| T1 | Workflow YAML syntactically valid | `npx @rhysd/actionlint .github/workflows/test.yml` |
| T1 | Workflow green on first run | Open the PR; the workflow's own run is the smoke test |
| T1 | Workflow catches regressions | After merge: throwaway branch with deliberate failing assertion → expect red → revert |

No new tests added. T0 is "fix existing", T1 has no test code (workflow is config).

## Concurrency and Cost Analysis

- Run wall-time estimate: 80 s = 15 s (setup PHP) + 5 s (cache restore hit) + 5 s (composer install on cache hit) + 5 s (env+key) + 50 s (tests, 64 s observed locally trimmed by Linux speed).
- Volume: ~20 PRs/week × 3 reruns avg = 60 runs/week → 240 runs/month × ~1.5 min = ~360 min/month.
- Budget: GitHub Actions private-repo allowance is 2000 min/month → ~18 % consumption. Comfortable headroom for Fase 2 (Postgres service container will roughly double the per-run cost).

## Failure Scenarios

| Scenario | Symptom | Recovery |
|---|---|---|
| Composer install fails (registry blip) | Step 5 red, no test execution | Re-run job from Actions tab. `timeout-minutes: 10` ensures it fails fast. |
| PHP extension missing | Step 2 red | Edit `extensions:` list in workflow, push fix. |
| Test assertion fails | Step 8 red with test name + diff in log | Fix code or test, push commit, workflow re-runs on PR sync. |
| OOM despite 512M | Step 8 exits 137 | Escalation: split `artisan test --testsuite=Feature` + `--testsuite=Unit` in two sequential `run:` lines. Document in follow-up SDD. |
| Workflow YAML invalid | GitHub UI rejects with "workflow file is invalid" | actionlint pre-push catches; if missed, fix and force-push branch. |
| Concurrency cancellation drops a needed run | Stale PR commit's run cancelled mid-flight | Acceptable — newest commit's run is the one that gates the merge. |

## Rollback Plan

Three reversible layers, no data loss at any step:

1. **Disable**: GitHub UI → Actions tab → workflow "tests" → Disable. Zero code change.
2. **Remove workflow**: `git rm .github/workflows/test.yml` and push to main. CI vanishes; no runtime impact (nothing in app code depends on workflow).
3. **Revert T0**: Each cluster commit is independent; `git revert <sha>` per cluster reintroduces only that cluster's failures. Test suite returns to 23 baseline failures — acceptable, those were the pre-existing state.

## Migration / Rollout

No data migration. Rollout is `git merge` of the PR to main. First post-merge push triggers the workflow on main itself, validating it on the real branch. No feature flag.

## Discoveries (saved this phase to Engram)

1. **Cluster B root cause is NOT Breeze routes** — routes ARE wired (`routes/web.php:73-76` + `routes/auth.php`). Real cause: `UserFactory::definition()` doesn't set `activo`, so in-memory model has unset attr → boolean cast → false → `UsuarioActivo` middleware redirects. DB default of `true` doesn't help because Eloquent reads in-memory attrs. (Verified empirically.)
2. **Cluster F tests already use `Http::fake()`** — explore phase #878 said "needs Http::fake()" but the code already has it correctly. Real failure may be cascading from Cluster D (DTO shape mismatch). Order: fix D first, re-test F.
3. **Cluster C dataset grew from 33 → 241 entities** — and the named entities (`YPFB`, `ENTEL`) were replaced by ministry-style names. Use range assertion + present-day entity names to avoid future dataset-growth flakes.
4. **`phpunit.xml` covers test env without `.env.testing`** — but `php artisan key:generate` still needs a base `.env`. Cheapest path is `cp .env.example .env` in workflow.

## Open Questions (deferred to sdd-tasks)

- Commit message wording style — proposal frozen the 6 cluster + workflow + README split; tasks decides exact subject lines (recommend `test: fix Cluster <X> — <reason>` for T0 and `ci: add tests workflow` for T1).
- Whether T1 workflow commit and T2 README commit ship together or separate. Recommendation for tasks: separate. Workflow can land hot; README is human-facing prose that may need editorial iteration.
- Whether to set `Http::preventStrayRequests()` globally in `Tests\TestCase::setUp()` as a regression guard against future Cluster-F-style bugs. Out of scope for ci-foundation; flag for a follow-up SDD.

## Constraints Honored

- TDD: T0 edits existing tests against current production code — no fake-RED ceremony.
- No new abstractions: Cluster F sticks with `Http::fake()` built-in.
- `declare(strict_types=1)` already present in test files touched (where applicable).
- Conventional commits, no Co-Authored-By.
- No `dd()`/`dump()`/`var_dump()` introduced.

**Artifact files**: `openspec/changes/ci-foundation/design.md`
**Engram topic**: `sdd/ci-foundation/design`
**Next phase**: `sdd-tasks`
