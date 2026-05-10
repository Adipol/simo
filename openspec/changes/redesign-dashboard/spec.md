# Specs: redesign-dashboard

**Date**: 2026-05-10
**Change**: `redesign-dashboard` — Dashboard v2
**Phase**: Spec
**PR split**: PR1 (Capabilities 1–3, zero schema changes) | PR2 (Capabilities 4–5, migrations)

---

## Capability: dashboard-summary-service

### Purpose

Provide structured data for Layer 1 (action triage) and Layer 3 (recent discoveries) of the new dashboard without direct queries inside Livewire's `render()`.

### Stakeholders

- **Reads**: `Dashboard` Livewire component via `#[Computed]`
- **Permissions**: Service is auth-gated at the component level; no internal permission check needed

### Data contracts

**Inputs**: none (global summary — no filters)

**Outputs**:

| DTO | Key fields |
|-----|-----------|
| `DashboardSummaryDTO` | `heroCard: ?HeroCardDTO`, `triage: TriageStripDTO`, `backlogAging: BacklogAgeDTO`, `ultimaActividad: ?Carbon`, `recentDiscoveries: RecentDiscoveriesDTO`, `sparklines: array<int>` (7 ints) |
| `HeroCardDTO` | `cambioId: int`, `titulo: string`, `pais: ?string`, `riesgo: string`, `esMae: bool`, `diasPendiente: int`, `score: float` |
| `TriageStripDTO` | `alto: int`, `medio: int`, `bajo: int`, `unreadResultados: int` |
| `BacklogAgeDTO` | `olderThanThreshold: int`, `thresholdDays: int` |
| `RecentDiscoveriesDTO` | `topPeps: array<CambioSummary>` (max 5), `topRiesgoCambios: array<CambioSummary>` (max 5) |

**Config keys**:
- `dashboard.hero_formula` (string, default `'riesgo_alto*3 + es_mae*2 + dias_pendiente/3'`)
- `dashboard.backlog_aging_days` (int, default `3`)
- `dashboard.summary_cache_ttl` (int seconds, default `60`)

---

### Requirements

#### REQ-1: Hero Card — Compute Most Urgent Unreviewed Cambio

The service MUST compute the single unreviewed `Cambio` with the highest urgency score using the formula `score = (riesgo_alto ? 3 : 0) + (es_mae ? 2 : 0) + días_pendiente / 3`, where `días_pendiente = floor((today - fecha) / 1 day)`. The formula coefficients MUST be sourced from `config('dashboard.hero_formula')`.

##### Scenario: Single pending cambio exists

- GIVEN one unreviewed `Cambio` with `riesgo = 'alto'`, `es_mae = true`, and `fecha = 10 days ago`
- WHEN `DashboardSummaryService::getSummary()` is called
- THEN `heroCard` is a `HeroCardDTO` with `score = 3 + 2 + 10/3 ≈ 8.33`
- AND `heroCard->cambioId` matches that cambio's `id`

##### Scenario: Multiple pending cambios — highest score wins

- GIVEN three unreviewed cambios with scores 2.0, 8.33, 5.0
- WHEN `getSummary()` is called
- THEN `heroCard->score` equals `8.33` and references the cambio with that score

##### Scenario: No pending cambios

- GIVEN `Cambio::where('revisado', false)->count() === 0`
- WHEN `getSummary()` is called
- THEN `heroCard` is `null`

---

#### REQ-2: Triage Strip — Counts by Risk Level

The service MUST return counts of unreviewed `Cambio` records grouped by risk level (`alto`, `medio`, `bajo`) and count of unread `ResultadoScraping`.

##### Scenario: Mixed risk cambios

- GIVEN 4 unreviewed cambios (2 `alto`, 1 `medio`, 1 `bajo`) and 7 unread resultados
- WHEN `getSummary()` is called
- THEN `triage.alto = 2`, `triage.medio = 1`, `triage.bajo = 1`, `triage.unreadResultados = 7`

##### Scenario: No unreviewed cambios

- GIVEN no unreviewed cambios
- WHEN `getSummary()` is called
- THEN `triage.alto = 0`, `triage.medio = 0`, `triage.bajo = 0`

---

#### REQ-3: Backlog Aging

The service MUST count unreviewed cambios whose `fecha` is older than `config('dashboard.backlog_aging_days')` days (default 3).

##### Scenario: Some cambios exceed threshold

- GIVEN 5 unreviewed cambios: 2 older than 3 days, 3 within 3 days
- WHEN `getSummary()` is called
- THEN `backlogAging.olderThanThreshold = 2` and `backlogAging.thresholdDays = 3`

##### Scenario: Custom threshold via config

- GIVEN `config('dashboard.backlog_aging_days')` set to `7`
- WHEN `getSummary()` is called
- THEN backlog count uses 7-day threshold (not 3)

---

#### REQ-4: Última Actividad Humana (Approximation)

In PR1, the service MUST return the `MAX(fecha)` of cambios where `revisado = true` as an approximation of the last human review timestamp. The response MUST be documented as approximate in the DTO docblock.

##### Scenario: Some reviewed cambios exist

- GIVEN cambios with `revisado = true` and the most recent has `fecha = 2026-05-09`
- WHEN `getSummary()` is called
- THEN `ultimaActividad` equals `2026-05-09` (as `Carbon`)

##### Scenario: No reviewed cambios

- GIVEN no cambio has `revisado = true`
- WHEN `getSummary()` is called
- THEN `ultimaActividad` is `null`

---

#### REQ-5: Recent Discoveries

The service MUST return up to 5 high-confidence newly detected entities (PEP/OPI candidates) from the last 24 hours, and up to 5 recent unreviewed cambios with risk `alto`.

##### Scenario: Enough high-confidence recent results

- GIVEN 8 `ResultadoScraping` records with `confianza >= 0.8` created in the last 24h
- WHEN `getSummary()` is called
- THEN `recentDiscoveries.topPeps` contains exactly 5 items, ordered by `confianza DESC`

##### Scenario: No high-confidence recent results

- GIVEN no `ResultadoScraping` with `confianza >= 0.8` in last 24h
- WHEN `getSummary()` is called
- THEN `recentDiscoveries.topPeps` is an empty array

---

#### REQ-6: Sparkline Data (7-day buckets)

The service MUST return an array of 7 integers representing the count of new unreviewed cambios per day for the last 7 calendar days (index 0 = oldest, index 6 = today). No schema changes required (`DATE_TRUNC` on existing `fecha` column).

##### Scenario: Data for 7 consecutive days

- GIVEN cambios created on each of the last 7 days (3, 1, 0, 2, 0, 4, 1)
- WHEN `getSummary()` is called
- THEN `sparklines = [3, 1, 0, 2, 0, 4, 1]`

##### Scenario: No cambios in a day slot

- GIVEN no cambios were created 5 days ago
- WHEN `getSummary()` is called
- THEN the index for that day is `0`, not `null`

---

#### REQ-7: Cache TTL

The service MUST cache its full result using `cache()->remember()` with a TTL of `config('dashboard.summary_cache_ttl', 60)` seconds. The cache key MUST be deterministic and busted on demand.

##### Scenario: Second call within TTL hits cache

- GIVEN `getSummary()` was called once (DB queries executed)
- WHEN `getSummary()` is called again within TTL
- THEN no additional DB queries are executed

##### Scenario: Cache TTL configurable

- GIVEN `config('dashboard.summary_cache_ttl')` set to `120`
- WHEN a fresh call is made
- THEN result is cached for 120 seconds

---

### Out of scope

- Per-source health data → `source-health-tracking` SDD
- `revisado_at` column (PR1 uses `MAX(fecha)` approximation; exact column arrives in PR2 if confirmed)
- User-level or role-based filtering of the summary

### Edge cases

- No cambios in DB → `heroCard = null`, all triage counts = 0, sparklines = 7 zeros
- `fecha` column is `date` type (not datetime) → `días_pendiente` calculation uses date difference in days
- Tie in hero score → service MAY return any one; ordering by `id DESC` as tiebreaker is acceptable

---

---

## Capability: dashboard-health-service

### Purpose

Provide data for Layer 2 (system health strip) including scraper status, queue depth, pipeline latency, and Gemini quota — with stub fields in PR1 that activate in PR2.

### Stakeholders

- **Reads**: `Dashboard` Livewire component via `#[Computed]`
- **Permissions**: Basic health indicators (colored dot + status text) visible to ALL authenticated users. Detailed numbers (queue depth count, latency ms, quota tokens/cost) visible only to users with `admin` or `supervisor` role.

### Data contracts

**Inputs**: none

**Outputs**:

| DTO | Key fields |
|-----|-----------|
| `PipelineHealthDTO` | `scraper: ScraperStatusDTO`, `pepMonitor: ScraperStatusDTO`, `queueDepth: QueueDepthDTO`, `latency: LatencyDTO`, `geminiQuota: GeminiQuotaDTO` |
| `ScraperStatusDTO` | `status: string ('ok'\|'warning'\|'error'\|'sin_registros')`, `lastRun: ?Carbon`, `durationSeconds: ?int` |
| `QueueDepthDTO` | `geminiPro: int`, `geminiFlash: int`, `otherTotal: int`, `available: true` |
| `LatencyDTO` | `p50Seconds: ?float`, `p95Seconds: ?float`, `sampleSize: int`, `available: bool` |
| `GeminiQuotaDTO` | `dailyTokens: ?int`, `estimatedCostUsd: ?float`, `available: bool` |

**Config**: `dashboard.health_cache_ttl` (int seconds, default `15`)

---

### Requirements

#### REQ-1: Scraper and PEP Monitor Status

The service MUST read scraper and pep_monitor last-run info via `LogScript::ultimaEjecucion('scraper')` and `LogScript::ultimaEjecucion('pep_monitor')` and map results to `ScraperStatusDTO`.

##### Scenario: Scraper ran successfully in last hour

- GIVEN `LogScript::ultimaEjecucion('scraper')` returns a record with `created_at = 45 minutes ago`
- WHEN `DashboardHealthService::getHealth()` is called
- THEN `health.scraper.status = 'ok'` and `lastRun` equals that timestamp

##### Scenario: No LogScript entries for scraper

- GIVEN no `LogScript` row exists for `'scraper'`
- WHEN `getHealth()` is called
- THEN `health.scraper.status = 'sin_registros'` and `lastRun = null`

##### Scenario: Last scraper run over 6 hours ago

- GIVEN `LogScript::ultimaEjecucion('scraper')` returns `created_at = 8 hours ago`
- WHEN `getHealth()` is called
- THEN `health.scraper.status = 'warning'`

---

#### REQ-2: Queue Depth

The service MUST count rows in the `jobs` table grouped by queue name, exposing `gemini_pro`, `gemini_flash`, and total non-Gemini job counts.

##### Scenario: Jobs in all queues

- GIVEN 3 jobs on `gemini_pro`, 5 on `gemini_flash`, 2 on `default`
- WHEN `getHealth()` is called
- THEN `queueDepth.geminiPro = 3`, `queueDepth.geminiFlash = 5`, `queueDepth.otherTotal = 2`

##### Scenario: Empty jobs table

- GIVEN the `jobs` table has no rows
- WHEN `getHealth()` is called
- THEN `queueDepth.geminiPro = 0`, `queueDepth.geminiFlash = 0`, `queueDepth.otherTotal = 0`
- AND `queueDepth.available = true` (not null — zero is valid)

---

#### REQ-3: Latency Stub (PR1)

In PR1, the service MUST return a `LatencyDTO` with `available = false` because `gemini_analyzed_at` columns do not yet exist.

##### Scenario: PR1 latency stub

- GIVEN the service is running in PR1 (no `gemini_analyzed_at` columns)
- WHEN `getHealth()` is called
- THEN `latency.available = false` and `latency.p50Seconds = null`

---

#### REQ-4: Gemini Quota Stub (PR1)

In PR1, the service MUST return a `GeminiQuotaDTO` with `available = false` because `log_gemini_usage` table does not yet exist.

##### Scenario: PR1 quota stub

- GIVEN the service is running in PR1 (no `log_gemini_usage` table)
- WHEN `getHealth()` is called
- THEN `geminiQuota.available = false` and `geminiQuota.dailyTokens = null`

---

#### REQ-5: Permission Gating — Detail Visibility

The service MUST expose a flag per sensitive sub-DTO to indicate whether full detail is available to the current user. The component layer MUST use `$health->canSeeDetails` (boolean injected by the service based on `Auth::user()->hasRole(['admin','supervisor'])`) to decide what to render.

##### Scenario: Regular user requests health

- GIVEN a user without `admin` or `supervisor` role
- WHEN the Dashboard renders with health data
- THEN `health.canSeeDetails = false`
- AND queue depth counts, latency ms, and quota cost are NOT rendered in the view

##### Scenario: Admin requests health

- GIVEN a user with `admin` role
- WHEN the Dashboard renders with health data
- THEN `health.canSeeDetails = true`
- AND all numeric detail fields are rendered

---

#### REQ-6: Cache TTL

The service MUST cache results with `config('dashboard.health_cache_ttl', 15)` seconds TTL.

##### Scenario: Fast refresh within TTL

- GIVEN `getHealth()` was called 5 seconds ago
- WHEN it is called again (triggered by `wire:poll.15s`)
- THEN no DB queries are executed (result from cache)

---

### Out of scope

- Per-source health (scraper per-source breakdown) → `source-health-tracking` SDD
- Gemini real quota/latency in PR1 (stubs only; real data arrives in PR2)
- WebSocket / real-time push (stays `wire:poll.15s`)

### Edge cases

- `jobs` table does not exist → service MUST catch `QueryException` and return `QueueDepthDTO` with all zeros and `available = false`
- `LogScript::ultimaEjecucion()` returns null → `status = 'sin_registros'`, not exception
- `canSeeDetails` flag MUST default to `false` if `Auth::check()` returns false (unauthenticated guard already blocks, but defensive)

---

---

## Capability: dashboard-v2-ui

### Purpose

Replace the monolithic 547-line `dashboard.blade.php` with a slim orchestrator Livewire component + 4 Blade sub-components that reflect the four narrative layers.

### Stakeholders

- **Reads**: All authenticated users
- **Permissions**: Layer 4 (Analytics) gated by `ver dashboard estadisticas` permission; health detail gated by `admin|supervisor` role

### Data contracts

**Inputs** (component-level public properties):
- `filtroDateRange: string` — `#[Url]`
- `filtroPais: string` — `#[Url]`
- `filtroCategoria: string` — `#[Url]`

**Outputs** (rendered HTML layers):
1. `<x-dashboard.action-layer :summary="$summary" />`
2. `<x-dashboard.health-strip :health="$health" :canSeeDetails="$health->canSeeDetails" />`
3. `<x-dashboard.discovery-layer :summary="$summary" />`
4. `<x-dashboard.analytics-section :metrics="$metrics" />` (collapsible, admin/supervisor only)

---

### Requirements

#### REQ-1: Livewire Component Refactor — Remove Inline Queries

The `Dashboard` Livewire component MUST NOT contain any direct Eloquent queries. All data MUST be obtained via `#[Computed]` properties backed by injected services.

##### Scenario: render() has zero direct queries

- GIVEN the refactored `Dashboard` component is loaded
- WHEN `DB::listen()` is active during a full render cycle
- THEN zero query events originate from inside `render()` itself (only from service cache misses)

##### Scenario: Filter properties persist in URL

- GIVEN a user sets `filtroPais = 'AR'` in the dashboard
- WHEN the user copies and revisits the URL
- THEN `filtroPais` is pre-populated as `'AR'` (via `#[Url]`)
- AND the same applies for `filtroDateRange` and `filtroCategoria`

---

#### REQ-2: Hero Card Visual Treatment

The action layer MUST render the hero card as a visually prominent element with the cambio title, risk badge, MAE indicator, days pending, score, and an action button linking to `/pep/cambios?cambio={id}`.

##### Scenario: Hero card with urgent cambio

- GIVEN `$summary->heroCard` is a populated `HeroCardDTO`
- WHEN the action layer renders
- THEN a hero card is visible with risk badge, days pending, and a "Revisar ahora" link pointing to `/pep/cambios?cambio={heroCard->cambioId}`

##### Scenario: No pending cambios — all-clear state

- GIVEN `$summary->heroCard === null`
- WHEN the action layer renders
- THEN the hero card slot displays "Todo al día ✓" message
- AND no "Revisar ahora" button is shown

---

#### REQ-3: Triage Strip with Sparklines

The action layer MUST render four mini-cards (alto, medio, bajo, unread resultados) each including a 7-day inline sparkline chart.

##### Scenario: Sparkline all zeros

- GIVEN `$summary->sparklines = [0, 0, 0, 0, 0, 0, 0]`
- WHEN the action layer renders
- THEN a flat-line sparkline chart is rendered (not an error or blank)

##### Scenario: Sparkline with data

- GIVEN `$summary->sparklines = [3, 1, 0, 2, 0, 4, 1]`
- WHEN the action layer renders
- THEN the sparkline chart reflects the 7-day pattern (rendered via Alpine.js + Chart.js)

---

#### REQ-4: Health Strip — Colored Status Dots

The health strip MUST render a compact horizontal row with colored status indicators (green/amber/red dot) per metric visible to all users. Numeric detail is conditionally shown only when `$canSeeDetails = true`.

##### Scenario: Regular user sees health strip

- GIVEN `$health->canSeeDetails = false`
- WHEN the health strip renders
- THEN colored dots and status labels are visible
- AND queue depth numbers, latency ms, and quota tokens are NOT present in the DOM

##### Scenario: Admin sees health strip with detail

- GIVEN `$health->canSeeDetails = true`
- WHEN the health strip renders
- THEN all numeric details (queue counts, latency, quota) are visible alongside the dots

---

#### REQ-5: Discovery Layer — PEP Cards with Contextual Snippets

The discovery layer MUST render two columns: top 5 recent high-confidence PEP/OPI detections, and top 5 recent high-risk cambios.

##### Scenario: Items displayed with avatars

- GIVEN `recentDiscoveries.topPeps` has 5 items
- WHEN the discovery layer renders
- THEN each item shows: initials avatar (colored by confidence level), name/title snippet, risk badge, source label

##### Scenario: Empty discovery list

- GIVEN both `topPeps` and `topRiesgoCambios` are empty arrays
- WHEN the discovery layer renders
- THEN a teaching empty-state message is shown (not a blank area)

---

#### REQ-6: Analytics Section — Collapsible, Permission-gated

The analytics section (Layer 4) MUST only render for users with `ver dashboard estadisticas` permission. It MUST be collapsible via Alpine.js (`x-data`, `x-show`).

##### Scenario: Regular user sees no analytics section

- GIVEN a user without `ver dashboard estadisticas` permission
- WHEN `Dashboard` renders
- THEN no analytics section HTML is present in the response

##### Scenario: Admin can collapse/expand analytics

- GIVEN a user with `ver dashboard estadisticas` permission
- WHEN the analytics section is rendered and user clicks the collapse toggle
- THEN the section hides/shows via Alpine.js without a Livewire server round-trip

---

#### REQ-7: SVG Choropleth LATAM Heatmap

The analytics section MUST include an inline SVG choropleth map of LATAM where each country path is filled with a color intensity proportional to detection count. Countries with zero detections MUST render in grey.

##### Scenario: Countries with detections

- GIVEN `GeographicMetricsDTO` has `AR: 12, PE: 5, CO: 0`
- WHEN the heatmap renders
- THEN `AR` path fill reflects high intensity, `PE` low intensity, `CO` renders grey

##### Scenario: No geographic data at all

- GIVEN `GeographicMetricsDTO` has all countries at 0
- WHEN the heatmap renders
- THEN the SVG renders with all countries grey
- AND an overlay message "Aún sin detecciones por país" is shown

---

#### REQ-8: CSS Brand Variables — Dark Mode Foundation

The `app.css` file MUST define CSS custom properties (`--simo-primary`, `--simo-accent`, `--simo-danger`, `--simo-warning`, `--simo-surface`) at `:root`. No `dark:` Tailwind variants MUST be added in PR1.

##### Scenario: Brand variables defined

- GIVEN the compiled CSS is loaded
- WHEN `getComputedStyle(document.documentElement).getPropertyValue('--simo-primary')` is called
- THEN a non-empty color value is returned

---

### Out of scope

- Dark mode activation (`dark:` Tailwind variants) → deferred to design system SDD
- WebSocket/Reverb real-time push
- `wire:poll` interval changes
- Sub-components making their own DB calls (all data passed via props)

### Edge cases

- `filtroDateRange` empty string → treat as "no filter" (existing behavior)
- Component renders during unauthenticated access → middleware blocks before Livewire fires
- `$metrics` for analytics section could be null on fresh installs → render with "Calculando…" placeholder

---

---

## Capability: gemini-usage-logging

### Purpose

Persist token usage and timestamps from Gemini API responses into `log_gemini_usage` table to enable daily quota visibility and cost tracking.

### Stakeholders

- **Writes**: `GeminiService` (via `send()` and `sendMultimodal()`)
- **Reads**: `DashboardHealthService::geminiQuota()` (PR2), admin users via dashboard
- **Permissions**: Token counts visible to admin/supervisor only

### Data contracts

**Inputs**: Gemini API response `usageMetadata` object + calling context

**New table** `log_gemini_usage`:

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | auto-increment |
| `model` | varchar(100) | e.g. `gemini-2.5-flash` |
| `prompt_tokens` | int nullable | from `usageMetadata.promptTokenCount` |
| `completion_tokens` | int nullable | from `usageMetadata.candidatesTokenCount` |
| `total_tokens` | int nullable | stored (not computed) from `usageMetadata.totalTokenCount` |
| `request_type` | varchar(50) | `'filtro'`, `'analisis_cambio'`, `'analisis_multimodal'` |
| `cambio_id` | bigint nullable FK | references `cambios.id` |
| `resultado_scraping_id` | bigint nullable FK | references `resultados_scraping.id` |
| `created_at` | timestamp | set at insert |

**New columns** (separate migrations):
- `cambios.gemini_analyzed_at` — `timestamp nullable`
- `resultados_scraping.gemini_analyzed_at` — `timestamp nullable`

---

### Requirements

#### REQ-1: Log Row Per Successful API Call

The `GeminiService` MUST insert one row into `log_gemini_usage` for every API call that receives a successful response (HTTP 200 with valid structure).

##### Scenario: Successful filtro call

- GIVEN `GeminiFiltroService::filtrar()` completes successfully with `usageMetadata` present
- WHEN the method returns
- THEN exactly one `log_gemini_usage` row exists with `request_type = 'filtro'` and correct token counts

##### Scenario: Successful analysis call

- GIVEN `GeminiAnalisisService::analizar()` completes for a `Cambio` with `id = 42`
- WHEN the method returns
- THEN one `log_gemini_usage` row exists with `cambio_id = 42`, `request_type = 'analisis_cambio'`

---

#### REQ-2: Populate gemini_analyzed_at on Success

`GeminiFiltroService` MUST set `resultados_scraping.gemini_analyzed_at = NOW()` after a successful analysis. `GeminiAnalisisService` MUST set `cambios.gemini_analyzed_at = NOW()` after successful analysis.

##### Scenario: Filtro service stamps timestamp

- GIVEN a `ResultadoScraping` with `gemini_analyzed_at = null`
- WHEN `GeminiFiltroService::filtrar()` completes successfully
- THEN `resultados_scraping.gemini_analyzed_at` is set to the current timestamp

##### Scenario: Analisis service stamps timestamp

- GIVEN a `Cambio` with `gemini_analyzed_at = null`
- WHEN `GeminiAnalisisService::analizar()` completes successfully
- THEN `cambios.gemini_analyzed_at` is set to the current timestamp

---

#### REQ-3: No Log Row on API Failure

If the Gemini API call throws an exception or returns a non-200 response, the service MUST NOT insert a `log_gemini_usage` row and MUST NOT set `gemini_analyzed_at`.

##### Scenario: API call throws exception

- GIVEN `GeminiService::send()` throws `GeminiApiException`
- WHEN `GeminiFiltroService::filtrar()` propagates the exception
- THEN no `log_gemini_usage` row is inserted
- AND `resultados_scraping.gemini_analyzed_at` remains `null`

---

#### REQ-4: Missing usageMetadata — Graceful Degradation

If the API response lacks `usageMetadata`, the service MUST log a warning via Laravel's `Log::warning()` and MUST still insert a `log_gemini_usage` row with `prompt_tokens = null`, `completion_tokens = null`, `total_tokens = null`.

##### Scenario: Response without usageMetadata

- GIVEN `GeminiService::send()` returns a response with no `usageMetadata` key
- WHEN the service processes the response
- THEN a warning is logged
- AND one `log_gemini_usage` row is inserted with all token fields `null`
- AND `gemini_analyzed_at` IS set (analysis result was valid)

---

#### REQ-5: Idempotency — No Double Logging

If the same Gemini response is processed twice (e.g., job retry after successful DB write but failed acknowledgment), the system SHOULD NOT insert a duplicate `log_gemini_usage` row. SHOULD use `gemini_analyzed_at IS NOT NULL` as guard before calling Gemini.

##### Scenario: Job retry after successful first run

- GIVEN `cambios.gemini_analyzed_at` is already set (first run succeeded)
- WHEN the job is retried
- THEN the service SHOULD skip the Gemini call entirely
- AND no additional `log_gemini_usage` row is inserted

---

#### REQ-6: Existing Rows — Null Timestamps Acceptable

After migration, all existing `cambios` and `resultados_scraping` rows MUST have `gemini_analyzed_at = null`. This is the expected starting state and MUST NOT trigger errors in the UI.

##### Scenario: Dashboard during first 2 weeks post-deploy

- GIVEN all `gemini_analyzed_at` are `null` (no PR2 processing yet)
- WHEN the dashboard health strip renders
- THEN latency widget shows "Recolectando datos… (N/A)" — not an error

---

### Out of scope

- Cost calculation in USD (available when `DashboardHealthService` implements PR2 logic)
- User-level usage tracking (no `user_id` FK in table initially)
- `log_gemini_usage` table in PR1 (migration is PR2)

### Edge cases

- Multimodal call (`sendMultimodal`) → `request_type = 'analisis_multimodal'`, `cambio_id` nullable if context is not cambio-specific
- `cambio_id` and `resultado_scraping_id` both null → valid for future generic Gemini calls
- Migration on large DB → `gemini_analyzed_at` backfill is NOT required; `null` is correct starting state

---

---

## Capability: pipeline-latency-tracking

### Purpose

Compute P50/P95 latency from scraper detection (`fecha`) to Gemini analysis completion (`gemini_analyzed_at`) to surface pipeline bottlenecks.

### Stakeholders

- **Reads**: `DashboardHealthService` (PR2 switch from stub)
- **Permissions**: Latency metrics visible to ALL authenticated users (no sensitive cost data)

### Data contracts

**Inputs**: none (reads from `cambios` table using existing + new columns)

**Outputs**:

| DTO | Key fields |
|-----|-----------|
| `LatencyDTO` | `p50Seconds: ?float`, `p95Seconds: ?float`, `sampleSize: int`, `available: bool`, `message: ?string` |

**SQL**: Uses `percentile_cont(0.5)` and `percentile_cont(0.95)` WITHIN GROUP over `EXTRACT(EPOCH FROM (gemini_analyzed_at - fecha))` for rows where `gemini_analyzed_at IS NOT NULL` and `fecha >= NOW() - INTERVAL '24 hours'`

**Config**: Cache TTL 60s (shared with `dashboard.summary_cache_ttl`)

---

### Requirements

#### REQ-1: Percentile Computation via PostgreSQL

The service MUST compute P50 and P95 latency using PostgreSQL ordered-set aggregate functions. No PHP-side sorting MUST be used.

##### Scenario: Sufficient sample size (≥ 10)

- GIVEN 15 cambios have both `gemini_analyzed_at` set and `fecha` within last 24h
- WHEN `DashboardHealthService::pipelineLatency()` is called
- THEN `latency.p50Seconds` and `latency.p95Seconds` are non-null floats
- AND `latency.sampleSize = 15`
- AND `latency.available = true`

##### Scenario: P95 is always ≥ P50

- GIVEN a valid sample of 20 rows with varying latencies
- WHEN `pipelineLatency()` is called
- THEN `p95Seconds >= p50Seconds`

---

#### REQ-2: Insufficient Sample — available: false

If `sampleSize < 10`, the service MUST return `LatencyDTO` with `available = false` and `message = 'Recolectando datos…'`.

##### Scenario: Only 5 processed cambios in last 24h

- GIVEN 5 cambios with `gemini_analyzed_at` set in last 24h
- WHEN `pipelineLatency()` is called
- THEN `latency.available = false`
- AND `latency.message = 'Recolectando datos…'`
- AND `latency.p50Seconds = null`

---

#### REQ-3: All Timestamps Null — available: false

If all `gemini_analyzed_at` are null (first 2 weeks post-deploy), the service MUST return `LatencyDTO` with `available = false`.

##### Scenario: Pre-PR2 state — no timestamps

- GIVEN `gemini_analyzed_at` is null for all cambios
- WHEN `pipelineLatency()` is called
- THEN `latency.sampleSize = 0` and `latency.available = false`

---

#### REQ-4: 24-hour Rolling Window

The service MUST restrict the latency computation to cambios where `fecha >= NOW() - INTERVAL '24 hours'` to reflect recent pipeline performance.

##### Scenario: Old cambios excluded

- GIVEN 20 cambios with `gemini_analyzed_at` set, but only 3 have `fecha` within last 24h
- WHEN `pipelineLatency()` is called
- THEN `latency.sampleSize = 3` (not 20)
- AND `latency.available = false` (sample too small)

---

#### REQ-5: Cache

The method MUST cache results using `cache()->remember()` with 60-second TTL.

##### Scenario: Repeated calls within TTL

- GIVEN `pipelineLatency()` was called 30 seconds ago
- WHEN it is called again
- THEN no new DB query is executed

---

### Out of scope

- Latency tracking for `resultados_scraping` in PR2 (only `cambios` table for initial implementation)
- P99 computation (P50 + P95 is sufficient)
- Historical trend (daily P50/P95 chart) → future enhancement

### Edge cases

- `fecha` is a `date` column (no time component) → `EXTRACT(EPOCH FROM (gemini_analyzed_at - fecha::timestamp))` — service MUST cast appropriately to avoid negative or zero latencies at midnight
- Single outlier with extremely high latency (e.g. 72h) → P95 may be unexpectedly high; no filtering applied at spec level (business decision needed)
- `gemini_analyzed_at < fecha` (clock skew or data error) → raw negative latency; service MUST filter `WHERE gemini_analyzed_at > fecha` before computing percentiles

---
