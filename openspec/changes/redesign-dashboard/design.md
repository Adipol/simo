# Design: redesign-dashboard

**Date**: 2026-05-10 · **Phase**: Design · **PR1 + PR2**
**Reads**: `proposal.md`, `spec.md` · **Stack**: Laravel 12 + Livewire 4.x + Tailwind 3 + PG17

> **Discovery**: `cambios.fecha` is **already `timestamp` in DB** (migration `0001_01_01_000009`, `useCurrent()`). The PR2 "promote date→timestamp" migration is **CANCELLED** — no cast needed for latency.

---

## 1. Service architecture

```
Livewire\Dashboard
  ├─ render() — zero queries, only orchestration
  ├─ #[Computed(cache:false)] summary  → DashboardSummaryService::getSnapshot(): DashboardSummaryDTO
  ├─ #[Computed(cache:false)] health   → DashboardHealthService::getHealth(?User): PipelineHealthDTO
  └─ #[Computed(cache:false)] *Metrics → DashboardMetricsService (UNCHANGED)

DashboardSummaryService
  __construct() — no deps (uses Eloquent + Cache facade)
  + getSnapshot(): DashboardSummaryDTO          ← orchestrator (composes the 5 below)
  - heroCard(): ?HeroCardDTO                    [cache: dashboard:summary:hero, 60s]
  - triage(): TriageStripDTO                    [cache: dashboard:summary:triage, 30s]
  - backlogAging(): BacklogAgeDTO               [cache: dashboard:summary:backlog, 60s]
  - ultimaActividad(): ?Carbon                  [cache: dashboard:summary:ultima, 60s]
  - recentDiscoveries(): RecentDiscoveriesDTO   [cache: dashboard:summary:recent, 60s]
  - sparklines(): array                         [cache: dashboard:summary:spark, 300s]

DashboardHealthService
  __construct() — no deps
  + getHealth(?User $user = null): PipelineHealthDTO
      — single cache key dashboard:health (15s); canSeeDetails computed AFTER cache hit (Auth check is cheap, not cached per user)
  - scraperStatus(string $script): ScraperStatusDTO
  - queueDepth(): QueueDepthDTO
  - latency(): LatencyDTO       [PR1: stub; PR2: real, own cache 60s]
  - quota(): GeminiQuotaDTO     [PR1: stub; PR2: real, own cache 60s]
```

**Why per-metric cache (not per-service)**: triage refreshes fast (revisado bursts), sparklines barely change in 5 min. Composite cache would over-invalidate. Each method = own `Cache::remember()`; `getSnapshot()` composes results.

**`canSeeDetails` flow**: cache the FULL admin payload once (key has NO user id). Service appends `canSeeDetails = $user?->hasAnyRole(['admin','supervisor']) ?? false` AFTER cache fetch. Blade hides fields when false. Single cache. Decided per orchestrator recommendation.

---

## 2. Caching strategy (per metric)

| Metric | TTL | Key | Rationale |
|---|---|---|---|
| Hero card | 60s | `dashboard:summary:hero` | Reacts to "marcar revisado" within ~1 min via cache bust event |
| Triage strip | 30s | `dashboard:summary:triage` | Counts shift fast as users review |
| Backlog aging | 60s | `dashboard:summary:backlog` | Day-granularity, slow churn |
| Última actividad | 60s | `dashboard:summary:ultima` | MAX query, slow churn |
| Recent discoveries | 60s | `dashboard:summary:recent` | New rows arrive in batches |
| Sparklines | 300s | `dashboard:summary:spark` | 7-day buckets, very slow churn |
| Scraper status | 15s | `dashboard:health` | Bundled in health |
| Queue depth | 15s | `dashboard:health` | Bundled in health |
| Latency (PR2) | 60s | `dashboard:health:latency` | Expensive percentile query |
| Quota (PR2) | 60s | `dashboard:health:quota` | Daily aggregate, slow churn |

**Cache bust**: when user marks `Cambio` revisado, dispatch event; observer `forget`s `dashboard:summary:hero`, `triage`, `backlog`, `ultima`. (Sparklines/recent unchanged.)

---

## 3. wire:poll strategy — Livewire 4 Islands

Single `Dashboard` component, NO sub-Livewire components. Use **`@island`** (Livewire 4.x) per layer with own `wire:poll` interval. Islands re-render server-side independently — no full component re-render, no full payload re-serialization.

```blade
@island('action')   <div wire:poll.30s>...</div>   @endisland
@island('health')   <div wire:poll.15s>...</div>   @endisland
@island('discovery')<div wire:poll.60s>...</div>   @endisland
{{-- analytics: NO poll, refresh on filter change only --}}
```

**Expected DB load per cycle (cached)**:
- 15s health island: 0 queries (cache hit) or 2 queries (cache miss every 15s)
- 30s action island: 0 or 4 queries (hero+triage+backlog+ultima)
- 60s discovery island: 0 or 1 query (recent)

**Per-minute worst case** (all caches missed): 4×2 + 2×4 + 1×1 = 17 queries/min — well under spec's "≤15 queries/cycle" budget per individual cycle.

Source: Livewire 4.x docs `/4.x/islands` + `/4.x/wire-poll`.

---

## 4. Component breakdown (Blade — anonymous components)

```
resources/views/components/dashboard/
├── action-layer.blade.php       (anon, prop: $summary: DashboardSummaryDTO)
│   ├── hero-card.blade.php      (anon, prop: $hero: ?HeroCardDTO)
│   └── triage-strip.blade.php   (anon, prop: $triage, $sparklines, $backlog)
│       └── triage-card.blade.php (anon, prop: $label, $count, $spark, $tone)
├── health-strip.blade.php       (anon, prop: $health: PipelineHealthDTO, $canSeeDetails: bool)
│   └── health-pill.blade.php    (anon, prop: $label, $status, $detail, $showDetail)
├── discovery-layer.blade.php    (anon, prop: $recent: RecentDiscoveriesDTO)
│   ├── recent-pep-card.blade.php
│   └── recent-cambio-card.blade.php
└── analytics-section.blade.php  (anon — wraps existing analytics partials)
    └── latam-heatmap.blade.php  (anon, prop: $geo: GeographicMetricsDTO)
```

**Decision**: anonymous components everywhere. Pass DTOs directly (not flat primitives) — DTOs are typed, compact, and Blade components access fields with `$summary->triage->alto`. Single source of truth.

**Sparkline**: shared `<x-simo-sparkline :data="$arr7" tone="rose|emerald|amber|zinc" />`. **Pure inline SVG, ZERO JS** (no Chart.js, no Alpine for sparkline) — `wire:poll` re-renders the SVG server-side. Polyline points computed in Blade with `@php` (min/max scaling). Decided AGAINST Chart.js: spec REQ-3 says "Alpine + Chart.js" but pure SVG is simpler, lighter, polls-friendly. **Spec amendment proposed**: change REQ-3 wording from "Alpine.js + Chart.js" to "inline SVG (no JS dependency)".

**LATAM heatmap**: `<x-dashboard.latam-heatmap />` with hardcoded SVG paths for 10 countries (BO, AR, CL, PE, PY, BR, UY, EC, CO, VE). Path data lives in the Blade file (no public asset). Color via `fill="{{ $colorFor($iso, $count) }}"`. Hover tooltip via SVG native `<title>` element (zero JS, accessible).

---

## 5. Sparkline & heatmap details

**Sparkline `<x-simo-sparkline :data :tone />`**:
```blade
@props(['data' => [0,0,0,0,0,0,0], 'tone' => 'zinc'])
@php
    $max = max(max($data), 1);
    $points = collect($data)->map(fn($v,$i) => sprintf('%.0f,%.1f', $i*12, 24 - ($v/$max)*22))->join(' ');
    $stroke = ['rose'=>'#f43f5e','emerald'=>'#10b981','amber'=>'#f59e0b','zinc'=>'#71717a'][$tone];
@endphp
<svg viewBox="0 0 84 26" class="w-20 h-6"><polyline fill="none" stroke="{{ $stroke }}" stroke-width="1.5" points="{{ $points }}"/></svg>
```

**LATAM heatmap color scale (5 buckets via quintiles of non-zero values)**:
- 0 → `#e4e4e7` (zinc-200, no data)
- bucket 1 → `#fee2e2` (rose-100)
- bucket 2 → `#fecaca` (rose-200)
- bucket 3 → `#fca5a5` (rose-300)
- bucket 4 → `#f87171` (rose-400)
- bucket 5 → `#ef4444` (rose-500)

Color helper: static method `Dashboard\HeatmapPalette::colorFor(int $count, int $max): string`.

---

## 6. CSS variables (`resources/css/app.css`)

Add at top of `@layer base`:
```css
:root {
    --simo-bg: #f4f4f5;        /* zinc-100 — current */
    --simo-surface: #ffffff;
    --simo-surface-muted: #fafafa;
    --simo-border: #e4e4e7;    /* zinc-200 */
    --simo-text-primary: #18181b;
    --simo-text-secondary: #52525b;
    --simo-text-muted: #a1a1aa;
    --simo-accent: #6366f1;    /* indigo-500 */
    --simo-danger: #f43f5e;    /* rose-500 */
    --simo-warning: #f59e0b;   /* amber-500 */
    --simo-success: #10b981;   /* emerald-500 */
}
```

**Decision**: define vars NOW, **do NOT migrate existing simo-* classes** in PR1. Migration is gradual (later PR). New components (sparkline tones, heatmap palette) use vars directly. Avoids touching 100+ existing usages.

**Dark mode prep**: NOT in PR1. Don't add `[data-theme="dark"]` block — keeping PR1 surface area minimal.

---

## 7. Hero card scoring

**Config shape** (`config/dashboard.php`):
```php
return [
    'hero_formula' => [
        'riesgo_alto_weight' => 3,
        'es_mae_weight' => 2,
        'aging_divisor' => 3,
    ],
    'backlog_aging_days' => 3,
    'summary_cache_ttl' => 60,
    'health_cache_ttl' => 15,
    'scraper_warning_threshold_hours' => env('DASHBOARD_SCRAPER_WARN_HOURS', 6),
    'discovery_min_confidence' => env('DASHBOARD_DISCOVERY_MIN_CONF', 0.8),
];
```

**Computation**: SQL with computed `score` column for ORDER BY, fetch top 1 with `with('fuente')`.
```sql
SELECT *, (
  CASE WHEN gemini_analisis_json->>'riesgo' = 'alto' THEN :w_alto ELSE 0 END
  + CASE WHEN gemini_analisis_json->>'es_mae' = 'true' THEN :w_mae ELSE 0 END
  + EXTRACT(DAY FROM (NOW() - fecha))::int / :div
) AS score
FROM cambios WHERE revisado = false
ORDER BY score DESC, fecha DESC, id DESC
LIMIT 1
```

`fecha DESC` is the primary tiebreaker (most recent first when scores tie), `id DESC` is the deterministic fallback. Bind params from config.

---

## 8. Última actividad humana — PR1 strategy

**Decision**: stick with `MAX(fecha) WHERE revisado=true` approximation. UI text MUST say **"Última detección revisada"** (factual) NOT "Última revisión humana" (misleading). PR2 adds `revisado_at` column for accuracy.

`HeroCardDTO`/`DashboardSummaryDTO` docblock notes: `// PR1: approx via MAX(fecha) WHERE revisado=true. PR2: real revisado_at column.`

---

## 9. Indexes required

| Index | Table | Reason | Status |
|---|---|---|---|
| `(revisado, fecha)` | cambios | Hero scoring + backlog | **NEW PR1** |
| `fecha` | cambios | Already exists (migration 0001) | OK |
| `(leido)` | resultados_scraping | Triage unread count — verify in pending idx migration | Verify |
| `(revisado, gemini_analyzed, fecha)` partial WHERE revisado=false | cambios | Hero hot path | **NEW PR1** (partial, small) |
| `(gemini_analyzed_at)` | cambios | Latency window scan | **NEW PR2** |
| `(created_at)` | log_gemini_usage | Daily quota aggregate | **NEW PR2** (table created with idx) |
| `(queue)` | jobs | Likely already by Laravel default | Verify, add if missing |

**PR1 migration**: `2026_05_10_*_add_dashboard_indexes_to_cambios.php` — adds the two cambios indexes. Reversible. No data change.

---

## 10. Permissions architecture

**Decision (per orchestrator recommendation)**: cache full admin payload once. Service signature:
```php
public function getHealth(?User $user = null): PipelineHealthDTO
{
    $payload = Cache::remember('dashboard:health', 15, fn() => $this->computeHealth());
    $payload->canSeeDetails = ($user ?? Auth::user())?->hasAnyRole(['admin','supervisor']) ?? false;
    return $payload;
}
```

`canSeeDetails` is a **mutable public property** on `PipelineHealthDTO` (not a constructor arg) — set after cache fetch. Single cache, simple, no per-user explosion.

Blade conditionally renders detail:
```blade
@if($health->canSeeDetails)
    <span class="text-xs text-zinc-500">{{ $health->queueDepth->geminiPro }} jobs</span>
@endif
```

---

## 11. Test strategy (Strict TDD)

| Layer | What | How |
|---|---|---|
| Unit | `DashboardSummaryService` — each method | Feature test w/ `RefreshDatabase`, factory seeds, assert DTO fields |
| Unit | `DashboardHealthService` — stubs (PR1), real (PR2) | Same; PR1 asserts `available=false`, PR2 asserts numeric output |
| Unit | DTO `fromArray`/`empty` | Pure unit test (no DB) |
| Unit | `HeatmapPalette::colorFor` | Pure unit, table-driven |
| Feature | Livewire `Dashboard` renders | `Livewire::test()->assertSeeHtml()` per layer |
| Feature | `#[Url]` filters persist | `withQueryParams(['filtroPais'=>'AR'])->test()->assertSet('filtroPais','AR')` |
| Feature | Permission gating | `actingAs($regularUser)` → `assertDontSee('jobs')`; `actingAs($admin)` → `assertSee('jobs')` |
| Performance | Query count per poll cycle | `DB::enableQueryLog()` in test, assert `count(DB::getQueryLog()) <= 15` for full cycle (cache cold) |
| Edge | Empty data, null states | Factory creates 0 rows, assert DTO empty + UI empty-state |
| Cache bust | Mark revisado → cache forgotten | Dispatch event, `assertNoCache('dashboard:summary:hero')` |

**Coverage target**: ≥ 85% on both new services (per success criteria).

**No snapshot tests** for sparkline/heatmap — assert structure (correct number of `<polyline>` points, correct fill colors per bucket) instead. Cheaper and stable.

---

## 12. PR2 migration plan (preview — actual files in apply phase)

| # | Migration | Notes |
|---|---|---|
| 1 | `add_gemini_analyzed_at_to_cambios_table` | nullable timestamp, index on `(gemini_analyzed_at)`, no backfill |
| 2 | `add_gemini_analyzed_at_to_resultados_scraping_table` | same |
| 3 | ~~`change_fecha_to_timestamp`~~ | **CANCELLED** — already timestamp |
| 4 | `add_revisado_at_to_cambios_table` | nullable timestamp; backfill `UPDATE cambios SET revisado_at=fecha WHERE revisado=true` (one-time, in same migration) |
| 5 | `create_log_gemini_usage_table` | per spec; index on `created_at`, FKs nullable to cambios + resultados_scraping |

**Rollback**: each migration `down()` drops the column/table; safe (no destructive change to existing data).

---

## 13. Risks (post-design, with mitigations)

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Islands API not behaving as docs suggest in our 4.x version | Low | Medium | Verify Livewire version in `composer.json` matches 4.x docs path; fallback = single `wire:poll.30s` on root, accept slower health refresh |
| Per-metric cache fragmentation (many keys) | Low | Low | Documented prefix `dashboard:summary:*`; flush all via `Cache::tags()` if available, else loop |
| Cache bust event misses some keys | Medium | Low | Centralize bust list in `DashboardCacheManager::bustOnRevisadoChange()` |
| `canSeeDetails` leaks via JSON payload to non-admins | Low | High | Blade `@if`, NOT `class="hidden"` — non-admin DOM never contains numbers (asserted in test) |
| SVG heatmap path data drift vs ISO codes | Medium | Low | `HeatmapPalette` knows ISO list; missing ISO logged once + grey rendered |
| PR1 query count blows past 15 on cold cache | Low | Medium | Performance test fails CI; tune by collapsing summary methods into 1 query if needed |
| Score computed in PHP if SQL JSON access slow on PG17 | Low | Low | PG17 JSONB access is fast; benchmark with EXPLAIN ANALYZE on staging |

---

## Open questions

None blocking. Spec REQ-3 should be amended (Alpine+Chart.js → inline SVG) during apply phase docstring/PR description.
