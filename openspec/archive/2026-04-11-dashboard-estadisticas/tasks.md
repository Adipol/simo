# Tasks: dashboard-estadisticas

## Phase 1: DTOs Foundation

- [x] 1.1 Create `app/Services/Dashboard/DTOs/PrecisionMetricsDTO.php`
  - `final readonly class`, constructor: `float $overallAccuracy`, `array $byBucket`, `int $totalFeedbacks`, `bool $hasData`
  - Static factory: `::empty()` returning all zeros + `hasData=false`
  - `declare(strict_types=1);`
  - Acceptance: class exists, constructor works, `empty()` returns valid DTO with `hasData=false`

- [x] 1.2 Create `app/Services/Dashboard/DTOs/VolumeMetricsDTO.php`
  - Fields: `int $totalPeps`, `int $totalOpis`, `int $analyzedCount`, `int $unreadCount`, `array $monthlyTrend` (12 elements), `bool $hasData`
  - `::empty()` factory returning all zeros + empty monthlyTrend + `hasData=false`
  - Acceptance: DTO instantiates with all fields, `empty()` is valid

- [x] 1.3 Create `app/Services/Dashboard/DTOs/GeographicMetricsDTO.php`
  - Fields: `array $byCountry` (collection of: pais, peps_count, opis_count, avg_confianza, error_rate), `bool $hasData`
  - `::empty()` factory
  - Acceptance: DTO structure matches geographic distribution table columns

- [x] 1.4 Create `app/Services/Dashboard/DTOs/RecentActivityDTO.php`
  - Fields: `array $highConfidencePeps` (last 10 with confianza >= 90), `array $latestCorrections` (last 10 with usuario)
  - Each peps item: titulo, nombre, cargo, pais, confianza, fecha
  - Each correction: usuario_nombre, tipo, cargo, fecha
  - `::empty()` factory
  - Acceptance: structures support 6-field peps list and 4-field corrections list

- [x] 1.5 Create `app/Services/Dashboard/DTOs/TrendIndicatorsDTO.php`
  - Fields: `array $pepsTrend`, `array $opisTrend`, `array $feedbackTrend` (each: current, previous, delta_pct, direction)
  - direction: `'up'|'down'|'neutral'`
  - `::empty()` factory
  - Acceptance: DTO supports delta computation with division-by-zero handling

- [x] 1.6 TDD: DTO constructor and empty factory tests
  - RED: Write `tests/Unit/Services/DashboardMetricsServiceTest.php` skeleton with DTO tests
  - Test: `new PrecisionMetricsDTO(80.5, [], 10, true)` → fields match
  - Test: `PrecisionMetricsDTO::empty()->hasData === false`
  - GREEN: Implement DTOs
  - REFACTOR: pint

## Phase 2: DashboardMetricsService Skeleton

- [x] 2.1 Create `app/Services/Dashboard/DashboardMetricsService.php`
  - Class with `private readonly int $cacheTtlSeconds = 300`
  - Stub 6 public methods (return `::empty()` DTOs)
  - 3 private helpers: `resolveFilters`, `cacheKey`, `dateTruncMonth`
  - `declare(strict_types=1);`
  - Acceptance: class instantiates, methods return correct empty DTO types

- [x] 2.2 TDD: `resolveFilters()` — filter normalization
  - RED: Test `resolveFilters(['date_range' => 'week'])` → returns `['start' => Carbon, 'end' => Carbon, 'pais' => null, 'categoria' => null]`
  - RED: Test `resolveFilters(['pais' => 'AR'])` → `pais` becomes `['AR']`
  - RED: Test `resolveFilters(['pais' => ['AR','CL']])` → passthrough
  - RED: Test `resolveFilters(['date_range' => 'month'])` → current month range
  - GREEN: Implement `resolveFilters()` with preset mapping
  - REFACTOR: pint
  - **Portable SQL note**: no `FILTER (WHERE)` — use `SUM(CASE WHEN)` instead

- [x] 2.3 TDD: `cacheKey()` — deterministic key generation
  - RED: Test same filters → same key (call twice, assert equal)
  - RED: Test different filters → different keys
  - RED: Test key format: `dashboard:metrics:getVolumeMetrics:{sha1}`
  - GREEN: Implement using `sha1(json_encode(ksort($filters)))`
  - REFACTOR: pint

- [x] 2.4 TDD: `dateTruncMonth()` — driver-aware month truncation
  - RED: Test `DB::getDriverName() === 'pgsql'` → returns `DATE_TRUNC('month', col)`
  - RED: Test SQLite → returns `strftime('%Y-%m', col)` — **SQLite compatible**
  - GREEN: Implement driver switch
  - REFACTOR: pint

## Phase 3: Service Methods (TDD per method)

- [x] 3.1 TDD: `getVolumeMetrics()`
  - RED: Write test with seeded `resultados_scraping` rows (12 months of PEPs/OPIs)
  - Assert DTO: `totalPeps`, `totalOpis`, `monthlyTrend` has 12 elements
  - GREEN: Implement raw SQL — **NO `FILTER(WHERE)`, use `SUM(CASE WHEN)`**
  - Accuracy note: totals in PHP, not SQL
  - REFACTOR: pint

- [x] 3.2 TDD: `getPrecisionMetrics()`
  - RED: Write test with seeded `clasificaciones_feedback` rows (correct/incorrect mix)
  - Assert DTO: `overallAccuracy` = (correct/total)*100, `byBucket` has 3 entries (0-50, 51-80, 81-100)
  - GREEN: Implement JOIN SQL + PHP bucket grouping
  - REFACTOR: pint

- [x] 3.3 TDD: `getGeographicMetrics()`
  - RED: Write test with PEPs from multiple countries
  - Assert DTO: `byCountry` has correct 5 fields per country
  - GREEN: Implement aggregation by pais
  - REFACTOR: pint

- [x] 3.4 TDD: `getTopFailingPositions()`
  - RED: Write test with cargos having varying sample sizes
  - Assert: cargo with 2 samples is EXCLUDED, cargo with 3+ samples appears
  - Assert: ordered by error_rate DESC
  - GREEN: Implement with `HAVING COUNT(*) >= 3`
  - REFACTOR: pint

- [x] 3.5 TDD: `getRecentActivity()`
  - RED: Write test with high-confidence PEPs (≥90) and recent corrections
  - Assert: peps list only includes confianza >= 90, max 10 items
  - Assert: corrections include usuario_nombre, max 10 items
  - GREEN: Implement two sub-queries
  - REFACTOR: pint

- [x] 3.6 TDD: `getTrendIndicators()`
  - RED: Write test: this month 100 PEPs, last month 80 PEPs → delta +25%
  - RED: Test division by zero: this month 50, last month 0 → direction 'neutral' or 0%
  - GREEN: Implement period comparison logic in PHP
  - REFACTOR: pint

## Phase 4: Caching

- [x] 4.1 TDD: Cache hit detection
  - RED: Call `getVolumeMetrics([])` twice with same filters
  - Use `DB::listen` to count queries — assert 2 calls → 1 query
  - GREEN: Wrap service methods with `Cache::remember(key, 300, fn)`
  - REFACTOR: pint

- [x] 4.2 TDD: Different filters → different cache keys
  - RED: Call `getVolumeMetrics(['date_range' => 'week'])` then `['date_range' => 'month']`
  - Assert both return valid data (cache miss on second)
  - GREEN: Verified working (cache key includes sha1 of filters)
  - REFACTOR: none needed

- [x] 4.3 TDD: Cache key determinism
  - RED: Call with `['pais' => ['AR','CL']]` then `['pais' => ['CL','AR']]` (different order)
  - Assert same cache key used (ksort in cacheKey)
  - GREEN: Verified working
  - REFACTOR: none needed

## Phase 5: Permission Seeder

- [x] 5.1 TDD: Permission does NOT exist
  - RED: `Permission::where('name', 'ver dashboard estadisticas')->exists()` → false
  - GREEN: Add `'ver dashboard estadisticas'` to RolesPermisosSeeder
  - REFACTOR: none needed

- [x] 5.2 TDD: Admin and supervisor HAVE permission after seeding
  - RED: Seed RolesPermisosSeeder, check admin role → has permission
  - RED: Check supervisor role → has permission
  - GREEN: Verified working (syncPermissions is idempotent)
  - REFACTOR: none needed

- [x] 5.3 TDD: Operador does NOT have permission
  - RED: Check operador role → does NOT have 'ver dashboard estadisticas'
  - GREEN: Verified (only admin + supervisor receive it)
  - REFACTOR: none needed

## Phase 6: Dashboard Livewire Component

- [x] 6.1 TDD: Dashboard component default state
  - RED: `Livewire::test(Dashboard::class)` → assert `$mostrarEstadisticas === false`
  - GREEN: Add `public bool $mostrarEstadisticas = false` to Dashboard.php
  - REFACTOR: none needed

- [x] 6.2 TDD: Toggle method
  - RED: Call `toggleEstadisticas()` → assert toggles true
  - RED: Call again → assert toggles false
  - GREEN: Implement toggle method
  - REFACTOR: none needed

- [x] 6.3 TDD: Operador cannot toggle (authorize fails)
  - RED: `actingAs(operador)` calling `toggleEstadisticas()` → throws AuthorizationException
  - GREEN: Add `authorize('ver dashboard estadisticas')` check in toggle
  - REFACTOR: none needed

- [x] 6.4 TDD: Computed returns empty when collapsed
  - RED: `mostrarEstadisticas = false` → `getVolumeMetrics()` not called
  - RED: Computed property returns `VolumeMetricsDTO::empty()`
  - GREEN: Implement `#[Computed]` properties that return `::empty()` when `$mostrarEstadisticas === false`
  - REFACTOR: none needed

- [x] 6.5 TDD: Computed returns real data when expanded
  - RED: `mostrarEstadisticas = true` → computed returns real DTO with data
  - GREEN: Implement `if (!$this->mostrarEstadisticas) return ::empty()` guard
  - REFACTOR: none needed

- [x] 6.6 TDD: Filter changes trigger re-computation
  - RED: Set filters → computed re-runs with new filters
  - GREEN: Add filter properties + `updatedFiltro*()` lifecycle hooks
  - REFACTOR: none needed

- [x] 6.7 GREEN: Inject `DashboardMetricsService` into Dashboard component
  - Add constructor injection
  - Store as `private readonly`
  - Acceptance: service accessible in computed methods

## Phase 7: Blade Template

- [x] 7.1 Add `@stack('scripts')` to `layouts/app.blade.php`
  - Insert `@stack('scripts')` before `</body>`
  - Acceptance: layouts/app.blade.php has stack declared

- [x] 7.2 Add collapsible statistics section
  - Add `@can('ver dashboard estadisticas')` wrapper
  - Add toggle button with Alpine `x-show`
  - Add loading skeleton (spinner)
  - Acceptance: section only visible to admin/supervisor, toggle works

- [x] 7.3 Add filter bar
  - date_range selector (today/week/month/quarter/year)
  - pais multi-select
  - categoria dropdown
  - Livewire binding: `wire:model="filtroDateRange"`, etc.
  - Acceptance: changing filter updates all widgets

- [x] 7.4 Add 4 KPI cards
  - Total PEPs, Total OPIs, Accuracy %, Unread ratio
  - Use `$volumeMetrics->totalPeps` etc.
  - Show "Sin datos suficientes" when `hasData === false`
  - Acceptance: cards show real values or empty state

- [x] 7.5 Add System Precision widget
  - Display accuracy per bucket (0-50, 51-80, 81-100)
  - Use `$precisionMetrics->byBucket`
  - Acceptance: bucket table renders with accuracy per range

- [x] 7.6 Add Volume Trend Chart
  - `<canvas wire:ignore id="volumeChart">` container
  - Push Chart.js CDN: `@push('scripts')` with `chart.js@4.4.1` from jsdelivr
  - Alpine initialization: `x-init` reading `@js($volumeMetrics->monthlyTrend)`
  - **wire:ignore mandatory** to prevent Livewire from destroying canvas
  - Acceptance: line chart renders with 12 months data

- [x] 7.7 Add Top Failing Positions table
  - Columns: Cargo, Muestras, Errores, % Error
  - Show "Sin datos suficientes" when empty
  - Acceptance: table excludes cargos with < 3 samples

- [x] 7.8 Add Geographic Distribution table
  - Columns: País, PEPs, OPIs, Avg Confianza, % Error
  - Acceptance: 5 columns, ordered by PEPs DESC

- [x] 7.9 Add Recent High-Confidence PEPs list
  - 6 fields per item: título, nombre, cargo, país, confianza, fecha
  - Show "Sin datos suficientes" when empty
  - Acceptance: list max 10 items, only confianza >= 90

- [x] 7.10 Add Latest Corrections list
  - 4 fields: usuario, tipo, cargo corregido, fecha
  - Show "Sin datos suficientes" when empty
  - Acceptance: list max 10 items

- [x] 7.11 Add Trend Indicators widget
  - Show PEPs, OPIs, Feedback deltas with ↑/↓/→ icons
  - Color coding: green (up), red (down), gray (neutral)
  - Include % text, not just color
  - Acceptance: accessible indicators with icons + text

- [x] 7.12 Add Chart.js Alpine init script
  - Inline Alpine: `x-data`, `x-init` initializing Chart from `@json($dto)`
  - No fetch — data embedded via `@js()` directive
  - Acceptance: chart persists through Livewire re-renders

## Phase 8: Empty States

- [x] 8.1 TDD: Precision widget empty state
  - RED: No feedbacks → widget shows "Sin datos suficientes"
  - GREEN: Blade `x-show` or `@if($precisionMetrics->hasData)` conditional
  - REFACTOR: none needed

- [x] 8.2 TDD: Volume chart empty state
  - RED: No data → chart container shows message, no JS error
  - GREEN: Add `hasData` check before Alpine chart init
  - REFACTOR: none needed

- [x] 8.3 TDD: Tables empty state
  - RED: No rows → show "Sin datos suficientes" in table body
  - GREEN: `@forelse` or `@if($dto->hasData)` in blade
  - REFACTOR: none needed

- [x] 8.4 TDD: All KPI cards handle zero gracefully
  - RED: All zeros → cards show "0" not blank or error
  - GREEN: Verified (DTO returns 0, blade displays it)
  - REFACTOR: none needed

## Phase 9: Integration Tests

- [x] 9.1 TDD: Full flow — admin sees toggle
  - RED: `actingAs(admin)` visits `/dashboard`
  - Assert: toggle button visible, section collapsed by default
  - GREEN: Verified working
  - REFACTOR: none needed

- [x] 9.2 TDD: Full flow — operador hidden toggle
  - RED: `actingAs(operador)` visits `/dashboard`
  - Assert: toggle NOT rendered (via `@can`)
  - GREEN: Verified working
  - REFACTOR: none needed

- [x] 9.3 TDD: Toggle expands section
  - RED: Admin clicks toggle → section expands, metrics load
  - Assert: widgets visible after toggle
  - GREEN: Verified working
  - REFACTOR: none needed

- [x] 9.4 TDD: Chart.js CDN in rendered HTML
  - RED: `assertSeeHtml('chart.js@4.4.1')` or `assertSeeHtml('cdn.jsdelivr.net')`
  - GREEN: Verified working
  - REFACTOR: none needed

- [x] 9.5 TDD: Filter reactivity
  - RED: Change date_range filter → widgets update with new data
  - GREEN: Verified working (Livewire reactivity)
  - REFACTOR: none needed

- [x] 9.6 TDD: Permission check on toggle
  - RED: Direct `toggleEstadisticas()` call as operador → 403
  - GREEN: Verified working
  - REFACTOR: none needed

## Phase 10: Verification

- [x] 10.1 Run targeted unit tests
  - `php artisan test --filter=DashboardMetricsServiceTest`
  - Acceptance: all green

- [x] 10.2 Run targeted feature tests
  - `php artisan test --filter=DashboardEstadisticasTest`
  - Acceptance: all green

- [x] 10.3 Run Pint on new/modified files
  - `.\vendor\bin\pint app/Services/Dashboard/ tests/Unit/Services/DashboardMetricsServiceTest.php tests/Feature/Livewire/DashboardEstadisticasTest.php`
  - Acceptance: no style violations

- [x] 10.4 Run full test suite
  - `php artisan test`
  - Acceptance: no regressions (6 pre-existing failures in ExampleTest+ProfileTest remain unchanged)

- [x] 10.5 Verify SQL portability
  - Tests run on SQLite `:memory:` — assert no PostgreSQL-specific syntax
  - **NO** `FILTER (WHERE)`, **NO** `::numeric`, **NO** direct `DATE_TRUNC`
  - Acceptance: all tests pass on SQLite

- [ ] 10.6 Manual smoke test (seed + visit dashboard + toggle)
  - `php artisan db:seed --class=RolesPermisosSeeder`
  - Visit `/dashboard` as admin
  - Click "Ver estadísticas"
  - Assert: all 11 widgets render with data or empty state
  - Acceptance: human-verified working dashboard
