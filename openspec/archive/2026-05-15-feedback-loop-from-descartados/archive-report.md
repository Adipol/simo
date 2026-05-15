# Archive Report: feedback-loop-from-descartados

**Archived**: 2026-05-15
**Status**: COMPLETED (software) / Ops apply PENDING (git pull + migrate on VPS)
**PRs**: 3 merged to main + 1 collateral hotfix
  - PR #22 (PR-A foundation): merge commit e6d920b
  - PR #24 (PR-B CLI): merged
  - PR #25 (PR-C dashboard): merge commit e4df0ee
  - PR #23 (hotfix quota timezone): merge commit bcad592
**Verify**: APPROVED (engram #951)

## Outcome

This SDD shipped the missing signal feedback loop from discarded rows in the scraping pipeline. Prior to this change, 153 descartados (89% of the 171 production rows) sat unused—each carrying full Gemini metadata, confidence scores, and implicit negative labels set by the operator. This represents a pure, high-quality negative-label dataset that should inform the precision analysis.

The delivery unlocked two new capabilities: (1) **CLI `simo:analizar-descartados`** provides operators and automation systems with keyword-level and sitio-level discard breakdowns, temporal drift analysis (comparing 0–30d vs 30–60d windows), and Gemini confidence correlation. (2) **Dashboard `/admin/precision`** visualizes the same metrics with 4 Chart.js charts and automatic 5-minute refresh, allowing operators to track precision trends and identify problematic sources in real time.

By session end, the operator had adopted the full flow: instead of the initial 124/148 = 84% descartados / 0.6% relevantes / 0% archivados pattern, the baseline shifted to 153 descartados / 10 relevantes / 7 archivados—demonstrating that even good product instincts need usage discipline to generate signal. The ~6.1% baseline precision (10/(10+153)) is low, but the descartados sampling is now production-validated and ready for T3 auto-feedback to Gemini in a future SDD.

**Value delivered**: ≈1500 LOC across 3 chained PRs (service + DTOs + command + Livewire + Blade + 28 tests + migration), all merged to main and verified GREEN against 500-test feature suite. Ready for VPS deploy (git pull + migrate + manual Chart.js smoke test).

## Decisions log

All 12 frozen decisions from design phase (engram #943) locked in and implemented:

| # | Decision | Rationale |
|---|---|---|
| 1 | T1+T2 scope; T3 OUT | Negative-label analysis is foundation; auto-feedback is future SDD (~1-2h once sampling quality validated) |
| 2 | Standalone `/admin/precision` page | Decoupled polling, smaller files (~200 LOC Livewire + ~200 Blade), independently linkable vs dashboard widget |
| 3 | `DescartadosAnalisisService` is sibling, NOT extension | Different signal source: implicit (descartados) vs explicit (clasificaciones_feedback); mirrors DashboardMetricsService design |
| 4 | Min sample N≥5 keyword / N≥10 global | Production volume ~150 descartados with high variability by keyword; prevents noise from small-N outliers |
| 5 | Drift 30d vs 60d window | Captures seasonal/news-cycle trends; threshold +10ppt triggers review (configurable per spec) |
| 6 | Chart.js 4.4.1 via `@push('scripts')` | NOT in layouts/app (critical discovery engram #944); scoped push prevents dual-load and keeps layout clean |
| 7 | Permission `gestionar resultados` enforced in Livewire `mount()` | Mirrors `permission` middleware pattern; abort_unless + policy gate combination proves TDD-safe |
| 8 | CLI command bypasses permission gate | SSH access = elevated trust pattern; mirrors LimpiarLogs.php and BackfillZombieResultados.php conventions |
| 9 | Btree index on `sitio_id` with driver-conditional SQL | CONCURRENTLY (pgsql) / plain (SQLite); pattern from 2026_05_09_100003_*; zero-downtime on prod |
| 10 | `getNegativeExamples()` seam exposed, NOT consumed by T1/T2 | T3 contract locked; high-confidence descartados (confianza ≥70) future-proof for Gemini negative examples |
| 11 | Cache TTL=300s aligned with `wire:poll.300s` for CLI=UI consistency | Guarantees same metrics on both surfaces within cache window; flushCache() registry mirrors cacheKey() shape |
| 12 | Confianza buckets: 0-49 / 50-69 / 70-84 / 85-100 | Product decision frozen during explore pause (engram #922); aligns with Gemini confidence distribution |

## Metrics

| Metric | Value |
|---|---|
| REQs | 14/14 PASS (8 descartados-analisis + 6 precision-dashboard) |
| SCN coverage | 41/41 |
| New tests | 28 (14 PR-A + 7 PR-B + 7 PR-C) |
| Tests passing locally | 28 + 500 full Feature suite = 528 (1336 assertions) |
| Tests failing | 0 |
| Tests skipped | 9 (pgsql-only, by design) |
| Tests incomplete | 1 (pgsql-only, by design) |
| Lines added (production code only) | ~620 (service + DTOs + command + Livewire + Blade + migration) |
| Lines added (tests) | ~880 |
| Lines added (SDD docs) | ~3000 |
| Total commits across 3 PRs | 11 commits |
| PR size category | Chained (3 PRs avoid 960 LOC mega-PR) |
| Baseline failures pre-SDD | 2 (DashboardHealthServiceQuotaTest — fixed by hotfix #23) |
| Baseline failures post-SDD | 0 |

## Files delivered

### Production code
- `database/migrations/2026_05_20_120000_add_sitio_id_idx_to_resultados_scraping.php` — NEW
- `app/Services/DescartadosAnalisisService.php` — NEW (~280 LOC)
- `app/Services/Dashboard/DTOs/{DescartadosMetricsDTO, KeywordAnalisisDTO, SitioAnalisisDTO, DriftDTO, ConfianzaBucketDTO}.php` — NEW (5 DTOs)
- `app/Console/Commands/AnalizarDescartados.php` — NEW (~286 LOC)
- `app/Livewire/Admin/PrecisionDashboard.php` — NEW (~143 LOC)
- `resources/views/livewire/admin/precision-dashboard.blade.php` — NEW (~205 LOC)
- `routes/web.php` — MOD (+6 LOC for `/admin/precision` route)
- `app/Services/Dashboard/DashboardHealthService.php` — MOD (hotfix #23, PHP-side timezone for quota query)

### Test code
- `tests/Feature/Migrations/SitioIdIndexMigrationTest.php` — NEW
- `tests/Feature/Services/DescartadosAnalisisServiceTest.php` — NEW (12 tests)
- `tests/Feature/Commands/AnalizarDescartadosCommandTest.php` — NEW (7 tests)
- `tests/Feature/Livewire/PrecisionDashboardTest.php` — NEW (7 tests)

### SDD documentation
- `openspec/specs/descartados-analisis/spec.md` — NEW canonical capability spec
- `openspec/specs/precision-dashboard/spec.md` — NEW canonical capability spec
- `openspec/archive/2026-05-15-feedback-loop-from-descartados/{proposal, explore, spec, design, tasks, verify-report, archive-report}.md`

## Ops actions still required (post-merge)

1. `sudo -u www-data git -C /var/www/simo pull origin main`
2. `sudo -u www-data php /var/www/simo/artisan migrate --force` (applies the new sitio_id btree index)
3. Test the CLI: `sudo -u www-data HOME=/tmp php /var/www/simo/artisan simo:analizar-descartados`
4. Browser smoke test at `https://simo.amlc-listas.site/admin/precision` — verify Chart.js renders all 4 charts correctly (this is what verify-report flagged as browser-only)
5. Optional: add a sidebar nav link to `/admin/precision` if you want operators to discover it easily (deferred per spec OUT-* item)

## Known limitations / deuda técnica

1. **`--categoria` CLI flag is no-op** — accepted by command but service doesn't filter by it. Future SDD `categoria-filter-in-descartados-analysis` (~30 min).
2. **Chart.js rendering not in CI** — visual regression possible undetected. Future option: add Dusk browser tests.
3. **`#[Computed]` uses `app()` service locator** — minor style issue, could refactor to constructor injection.
4. **`renderRecomendaciones` thresholds in command differ slightly from spec text** — heuristic-only, the underlying data is correct.
5. **`flushCache()` only invalidates default-param cache keys** — non-default param queries self-heal within TTL window. Acceptable but documented limitation.

## Lessons learned

1. **Operator usage discipline matters as much as software** — at SDD start, user had 124/148 = 84% descartados / 1 relevante. The SDD was paused 2 days to let user adopt the full flow (Relevante + Archivar). By end: 153 descartados / 10 relevantes / 7 archivados. Without that pause, the SDD would have analyzed misleading data. **Future SDDs that depend on operator-generated data should validate usage patterns BEFORE building analysis tools.**

2. **3 chained PRs > 1 mega-PR for ~960 LOC features** — split clean by layer (foundation / CLI / UI). PR-A merges independently and gives the analytical foundation. PR-B and PR-C are parallelizable. Each PR ~200-500 LOC, well within review budget.

3. **`@push('scripts')` is the correct Chart.js loading pattern** — design phase corrected the explore-phase assumption that Chart.js was in the layout. Engram #944 documents this for future analytics SDDs.

4. **Branch protection means baseline failures block ALL PRs** — hotfix #23 was needed to unblock PR-A even though PR-A's own tests were green. Lesson: **the baseline tests are the project's "main" CI gate — never leave them red intentionally.**

5. **`final` service + behavioral tests > `final` service + mocks** — apply phase confirmed: cache flush testable via primed-cache + observable side effects, no mock required. Better design than introducing interface just for mocking.

## Related work / future hooks

- **T3 — Auto-feedback to Gemini** (deferred per OUT-1): wire `getNegativeExamples()` into `GeminiPromptBuilder` so Gemini sees recent operator-descartados as negative examples in its prompt. Should be a small SDD (~1-2h) once the team validates the descartados sample quality.
- **`--categoria` flag wiring** (~30 min): add `$categoria` param to service methods, plumb through CLI.
- **Sidebar nav link**: trivial PR adding the link under `@can('gestionar resultados')` gate in the main nav.
- **Dusk browser smoke tests**: validate Chart.js renders correctly. Out of scope for this SDD but valuable.
- **Drift sensitivity tuning**: spec uses 30d-vs-60d; might be too coarse for fast-moving news cycles. Could add weekly drift in future.

## References

- Original user insight: engram #896 (`discovery/descartados-feedback-loop-opportunity`)
- Pause decision: engram #922 (`sdd/feedback-loop-from-descartados/paused`)
- Explore: engram #921 / archive/explore.md
- Proposal: engram #941 / archive/proposal.md
- Spec: engram #942 / archive/spec.md + openspec/specs/{descartados-analisis,precision-dashboard}/spec.md
- Design: engram #943 / archive/design.md
- Tasks: engram #945 / archive/tasks.md
- Chart.js discovery: engram #944
- Apply-progress: engram `sdd/feedback-loop-from-descartados/apply-progress`
- Verify-report: engram #951 / archive/verify-report.md
