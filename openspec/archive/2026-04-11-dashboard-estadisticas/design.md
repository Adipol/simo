# Design: dashboard-estadisticas

## Technical Approach

`DashboardMetricsService` exposes 6 aggregation methods returning `readonly` DTOs, cached 5 min per `(method, filters)`. `Dashboard.php` uses Livewire v3 `#[Computed]` so queries run only when `$mostrarEstadisticas === true` (REQ-15). Chart.js 4.4.1 loads from jsdelivr via new `@stack('scripts')` in `layouts.app`; charts init via Alpine `@js($dto->data)` inside `wire:ignore` (REQ-7).

## Architecture Decisions

| # | Decision | Choice | Rationale |
|---|----------|--------|-----------|
| 1 | DB portability | **Portable SQL + driver helpers** | Tests use SQLite `:memory:`. Replace `FILTER (WHERE)` with `SUM(CASE WHEN)`; `DATE_TRUNC` via helper returning PG or `strftime` per `DB::getDriverName()`. |
| 2 | DTO location | **`app/Services/Dashboard/DTOs/`** | Mirrors `app/Services/Gemini/DTOs/`. |
| 3 | DTO shape | **`final readonly` + `::empty()`** | Immutable, typed, `hasData` for empty (REQ-18). |
| 4 | Metric loading | **`#[Computed]`** | LL-01: no query while collapsed. Memoizes per request. |
| 5 | Caching | **`Cache::remember` in service** | CA-01: survives across requests. Computed adds per-request dedup. |
| 6 | Cache key | **`dashboard:metrics:{method}:{sha1(json filters)}`** | CK-01/02/03 determinism. |
| 7 | Chart rendering | **Alpine inline via `@js($dto)`** | `wire:ignore` survives polling; no fetch roundtrip. |
| 8 | Filter shape | **Array normalized by `resolveFilters()`** | `['date_range','pais','categoria']`, preset → Carbon range. |
| 9 | Permission | **Blade `@can` + server `authorize()`** | GA-02 HTML gate; NFS-01 server gate. |
| 10 | Script injection | **`@stack('scripts')` in `layouts.app`** | Chart.js ships only when dashboard pushes. |

## Data Flow

```
Dashboard (#[Computed] lazy) → Service::getXxx(filters)
  → Cache::remember("dashboard:metrics:xxx:{hash}", 300, fn → DB::select)
  → XxxMetricsDTO | ::empty()
Blade (@can) → wire:ignore → Alpine @js(data) → Chart.js
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `app/Services/Dashboard/DashboardMetricsService.php` | Create | 6 methods + `resolveFilters`, `cacheKey`, `dateTruncMonth` |
| `app/Services/Dashboard/DTOs/PrecisionMetricsDTO.php` | Create | `overallAccuracy`, `byBucket[]`, `totalFeedbacks`, `hasData` |
| `app/Services/Dashboard/DTOs/VolumeMetricsDTO.php` | Create | `totalPeps/Opis/Analyzed`, `unreadCount`, `monthlyTrend[]` |
| `app/Services/Dashboard/DTOs/GeographicMetricsDTO.php` | Create | `byCountry[]`, `hasData` |
| `app/Services/Dashboard/DTOs/RecentActivityDTO.php` | Create | `highConfidencePeps[]`, `latestCorrections[]` |
| `app/Services/Dashboard/DTOs/TrendIndicatorsDTO.php` | Create | `pepsTrend`, `opisTrend`, `feedbackTrend` (current/previous/delta_pct/direction) |
| `app/Livewire/Dashboard.php` | Modify | `$mostrarEstadisticas`, filtro props, 6 `#[Computed]`, `toggleEstadisticas()` with `authorize()` |
| `resources/views/livewire/dashboard.blade.php` | Modify | `@can` collapsible block: filter bar + 11 widgets + `@push('scripts')` |
| `resources/views/layouts/app.blade.php` | Modify | Add `@stack('scripts')` before `</body>` |
| `database/seeders/RolesPermisosSeeder.php` | Modify | Add `'ver dashboard estadisticas'` to admin + supervisor |
| `tests/Unit/Services/DashboardMetricsServiceTest.php` | Create | Per method + cache + empty + filters |
| `tests/Feature/Livewire/DashboardEstadisticasTest.php` | Create | Role visibility, lazy, reactivity, chart injection |

## Interfaces / Contracts

```php
final readonly class PrecisionMetricsDTO {
    public function __construct(
        public float $overallAccuracy,
        public array $byBucket,  // [['bucket'=>'0-50','total'=>45,'correctos'=>20,'accuracy'=>44.4], ...]
        public int $totalFeedbacks,
        public bool $hasData,
    ) {}
    public static function empty(): self { return new self(0.0, [], 0, false); }
}

class DashboardMetricsService {
    public function __construct(private readonly int $cacheTtlSeconds = 300) {}
    public function getPrecisionMetrics(array $filters = []): PrecisionMetricsDTO;
    public function getVolumeMetrics(array $filters = []): VolumeMetricsDTO;
    public function getGeographicMetrics(array $filters = []): GeographicMetricsDTO;
    public function getRecentActivity(array $filters = []): RecentActivityDTO;
    public function getTrendIndicators(array $filters = []): TrendIndicatorsDTO;
    public function getTopFailingPositions(array $filters = [], int $minSamples = 3): array;
    private function resolveFilters(array $filters): array;     // preset→Carbon, pais→array
    private function cacheKey(string $method, array $filters): string;
    private function dateTruncMonth(string $col): string;       // driver-aware
}
```

**Portable SQL pattern (precision by bucket):**
```sql
SELECT CASE WHEN rs.gemini_confianza BETWEEN 0 AND 50 THEN '0-50'
            WHEN rs.gemini_confianza BETWEEN 51 AND 80 THEN '51-80'
            ELSE '81-100' END AS bucket,
       COUNT(*) AS total,
       SUM(CASE WHEN fb.tipo = 'correcto' THEN 1 ELSE 0 END) AS correctos
FROM resultados_scraping rs
INNER JOIN clasificaciones_feedback fb ON rs.id = fb.resultado_scraping_id
WHERE rs.fecha_encontrado BETWEEN :start AND :end
GROUP BY bucket
```
Accuracy computed in PHP to avoid `NULLIF/::numeric` divergence. `pais` filter via `whereIn` when array. All binds parameterized (NFS-02).

## Testing Strategy

| Layer | What | How |
|-------|------|-----|
| Unit | 6 service methods | `RefreshDatabase` + factories; assert DTO fields + SQLite portability |
| Unit | Cache hit | `DB::listen`: 2 calls → 1 query |
| Unit | Empty states | Zero rows → `hasData=false`, no exceptions |
| Unit | Filter normalization | Presets → expected Carbon ranges |
| Feature | Role visibility | `actingAs(admin/supervisor)` sees toggle; `operador` does NOT |
| Feature | Lazy load | `mostrarEstadisticas=false` → 0 metric queries |
| Feature | Reactivity | `set('filtroDateRange','week')` → computed re-runs |
| Feature | Chart injection | `assertSeeHtml('chart.umd.min.js')` + `wire:ignore` |

## Migration / Rollout

No schema migrations. Post-deploy: `php artisan db:seed --class=RolesPermisosSeeder` (idempotent). `php artisan cache:clear` flushes stale `dashboard:metrics:*` keys.

## Open Questions

- [ ] Index on `resultados_scraping(fecha_encontrado, gemini_analyzed, pais)` — if missing, follow-up change adds partial index `WHERE gemini_analyzed = true` (NFP-04).
- [ ] Does production CSP allow `cdn.jsdelivr.net`? Fallback: `npm install chart.js@4.4.1` + Vite bundle.
