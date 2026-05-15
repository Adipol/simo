# Design: feedback-loop-from-descartados

**Phase**: design · **Date**: 2026-05-14 · **Mode**: hybrid

## Technical Approach

Standalone Livewire page `/admin/precision` + sibling `DescartadosAnalisisService` consumed by both T1 (`simo:analizar-descartados` artisan) and T2 (Livewire dashboard). Cache TTL=300s aligned with `wire:poll.300s`. Driver-conditional migration adds btree index on `sitio_id` (pgsql `CONCURRENTLY`, SQLite plain). DTOs are `final readonly` per project standards. `getNegativeExamples()` exposed as a contract seam for future T3 — implemented but unused by T1/T2 UI.

## Architecture Overview

```
Operator → Bandeja → marca descartado/relevante → resultados_scraping table
                                                       │
                                                       ▼
                  Cache::remember(TTL=300s) ◄── DescartadosAnalisisService
                       │            │              (sibling of DashboardMetricsService)
                       ▼            ▼
                CLI command   Livewire dashboard
                (T1)          (T2: 4 Chart.js charts, wire:poll.300s)
                  │
                  ▼
          Console table output
          (Symfony Helper\Table via $this->table())
```

## Architecture Decisions

| Decision | Choice | Alternatives | Rationale |
|---|---|---|---|
| Service shape | Sibling `DescartadosAnalisisService` | Extend `DashboardMetricsService` | Different signal source: implicit (descartados) vs explicit (`clasificaciones_feedback`). Conflating violates SRP and makes T3 seam awkward. |
| Page placement | Standalone `/admin/precision` Livewire full-page | Tab inside `Dashboard.php` | Linkable URL, decoupled polling cycle, smaller file, easier auth gate. |
| Cache | `Cache::remember()` per-method key (no tags) | `Cache::tags(['descartados'])` | Default cache driver (database/file) doesn't support tagging — `DashboardMetricsService` already uses key-level pattern. Mirror it. |
| Permission for CLI | Skip auth check in command | `--user=email` impersonation flag | CLI access already requires SSH on the VPS — elevated trust. Mirrors `LimpiarLogs.php` pattern (no Gate check). Documented in command description. |
| Index migration | `CONCURRENTLY` (pgsql) + plain (SQLite) via `DB::getDriverName()` | Plain CREATE INDEX | Pattern proven in `2026_05_09_100003_add_titulo_trgm_index_to_resultados_scraping.php`. Zero-downtime prod deploy. |
| Chart.js loading | `@push('scripts')` in the new Blade view | Add to `layouts/app.blade.php` global | `layouts/app.blade.php` does NOT load Chart.js (only `dashboard.blade.php:43` does, via push). Adding globally would load it on every page. View-scoped push is correct. |
| DTO mutability | `final readonly class` with constructor promotion | Plain DTOs with setters | PHP 8.2+ project convention; immutability prevents accidental mutation across layers. |

## Data Model Changes

**Migration**: `database/migrations/2026_05_20_120000_add_sitio_id_idx_to_resultados_scraping.php`

Latest existing migration is `2026_05_10_110002_*`. Pick `2026_05_20_120000_*` as a sensible timestamp aligned with the post-pause restart date.

```php
<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false; // pgsql CONCURRENTLY needs no tx

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_resultados_scraping_sitio_id
                 ON resultados_scraping (sitio_id)'
            );
        } else {
            DB::statement(
                'CREATE INDEX IF NOT EXISTS idx_resultados_scraping_sitio_id
                 ON resultados_scraping (sitio_id)'
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_resultados_scraping_sitio_id');
        } else {
            DB::statement('DROP INDEX IF EXISTS idx_resultados_scraping_sitio_id');
        }
    }
};
```

## Components

### `app/Services/DescartadosAnalisisService.php` (NEW)

Responsibility: aggregate descartados/relevantes signal from `resultados_scraping`, expose precision metrics, per-keyword/per-sitio breakdowns, drift, confianza buckets, and the T3 seam.

```php
<?php
declare(strict_types=1);

namespace App\Services;

use App\Services\Dashboard\DTOs\DescartadosMetricsDTO;
use App\Services\Dashboard\DTOs\DriftDTO;
use App\Services\Dashboard\DTOs\KeywordAnalisisDTO;
use App\Services\Dashboard\DTOs\SitioAnalisisDTO;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Collection;

final class DescartadosAnalisisService
{
    private const CACHE_TTL = 300;

    public function __construct(private readonly CacheRepository $cache) {}

    /** REQ-1 — global precision %, null when total labeled < $minGlobal */
    public function precisionGeneral(int $dias = 30, int $minGlobal = 10, bool $skipCache = false): DescartadosMetricsDTO;

    /** REQ-2 — keywords sorted desc by pct_descartado, filtered by min sample */
    public function topLemasProblematicos(int $dias = 30, int $minSample = 5, int $limit = 10, bool $skipCache = false): Collection; // Collection<KeywordAnalisisDTO>

    /** REQ-3 — sitios sorted desc by pct_descartado, filtered by min sample */
    public function topSitiosProblematicos(int $dias = 30, int $minSample = 5, int $limit = 10, bool $skipCache = false): Collection; // Collection<SitioAnalisisDTO>

    /** REQ-4 — drift per keyword: current window vs previous (default 30d vs 30-60d) */
    public function driftPorKeyword(int $ventanaRecent = 30, int $ventanaPrevious = 30, int $minSample = 5, bool $skipCache = false): Collection; // Collection<DriftDTO>

    /** REQ-5 — gemini_confianza buckets vs descartado rate */
    public function confianzaGeminiVsDescartado(int $dias = 30, bool $skipCache = false): Collection; // Collection<array{bucket,total,descartados,pct}>

    /** REQ-8 — T3 seam (high-confidence wrong guesses for prompt few-shot) */
    public function getNegativeExamples(int $limit = 10): Collection; // Collection<ResultadoScraping>

    /** Invalidates ALL keys this service writes (used by Refrescar button + tests) */
    public function flushCache(): void;

    private function cacheKey(string $method, array $params): string;
}
```

**Per-method specs** (cache key + sample guard + return shape):

| Method | Cache key | Guard | Return |
|---|---|---|---|
| `precisionGeneral` | `descartados:precision:{dias}` | `total < $minGlobal` → `precisionPct=null`, `insufficientReason="< {n} etiquetados"` | `DescartadosMetricsDTO` |
| `topLemasProblematicos` | `descartados:lemas:{dias}:{minSample}:{limit}` | SQL `HAVING COUNT(*) >= $minSample` | `Collection<KeywordAnalisisDTO>` |
| `topSitiosProblematicos` | `descartados:sitios:{dias}:{minSample}:{limit}` | SQL `HAVING COUNT(*) >= $minSample` | `Collection<SitioAnalisisDTO>` |
| `driftPorKeyword` | `descartados:drift:{recent}:{prev}:{minSample}` | LEFT JOIN; null prev → `pctAnterior=null`, `driftPpt=null` | `Collection<DriftDTO>` |
| `confianzaGeminiVsDescartado` | `descartados:confianza:{dias}` | none (always 4 buckets, may have N=0) | `Collection<array>` |
| `getNegativeExamples` | NOT cached (T3-only seam) | `gemini_confianza >= 70` filter | `Collection<ResultadoScraping>` |

**Queries**: use Eloquent `selectRaw + groupBy + havingRaw` — exact SQL is in `explore.md` Q3 (lines 91-194). For drift, use a single `DB::select(<<<SQL ... SQL)` with the CTE — Eloquent CTE support is too verbose; raw is justified per AGENTS.md ("Eloquent over raw queries except optimization justified"). Comment WHY raw is used.

**`flushCache()`**: enumerate the 5 cached keys (precision, lemas, sitios, drift, confianza) for default param sets and `Cache::forget()` each. Tasks decides: keep the enumeration explicit or drive from a `protected array $cacheKeys` registry.

### DTOs (NEW, `final readonly class`)

`app/Services/Dashboard/DTOs/DescartadosMetricsDTO.php`:
```php
final readonly class DescartadosMetricsDTO
{
    public function __construct(
        public int $totalProcesados,
        public int $totalDescartados,
        public int $totalRelevantes,
        public int $totalArchivados,
        public ?float $precisionPct,         // null when insufficient sample
        public string $insufficientReason = '',
    ) {}
    public static function fromArray(array $row): self;  // per AGENTS.md
}
```

`app/Services/Dashboard/DTOs/KeywordAnalisisDTO.php`:
```php
final readonly class KeywordAnalisisDTO
{
    public function __construct(
        public string $keyword,
        public int $total,
        public int $descartados,
        public int $relevantes,
        public float $pctDescartado,
    ) {}
    public static function fromArray(array $row): self;
}
```

`app/Services/Dashboard/DTOs/SitioAnalisisDTO.php`:
```php
final readonly class SitioAnalisisDTO
{
    public function __construct(
        public int $sitioId,
        public string $sitioNombre,
        public int $total,
        public int $descartados,
        public float $pctDescartado,
    ) {}
    public static function fromArray(array $row): self;
}
```

`app/Services/Dashboard/DTOs/DriftDTO.php`:
```php
final readonly class DriftDTO
{
    public function __construct(
        public string $keyword,
        public ?float $pctActual,    // null if N<min in current window
        public ?float $pctAnterior,  // null if no prev period data
        public ?float $driftPpt,     // null if either side is null
    ) {}
    public static function fromArray(array $row): self;
}
```

### `app/Console/Commands/AnalizarDescartados.php` (NEW)

```php
final class AnalizarDescartados extends Command
{
    protected $signature = 'simo:analizar-descartados
        {--dias=30 : Ventana en dias para precision/keywords/sitios}
        {--categoria= : Filtra por gemini_categoria (opcional)}
        {--keyword= : Filtra por keyword (opcional)}
        {--min-sample=5 : Minimo N por keyword/sitio para aparecer}
        {--no-cache : Bypassa cache (read-through, no write-back)}';

    protected $description = 'Reporte de precision/descartados sobre resultados_scraping. Solo CLI (sin gate de permisos — requiere acceso SSH).';

    public function handle(DescartadosAnalisisService $service): int
    {
        $dias      = (int) $this->option('dias');
        $minSample = (int) $this->option('min-sample');
        $skipCache = (bool) $this->option('no-cache');

        // 1) Resumen
        $precision = $service->precisionGeneral($dias, skipCache: $skipCache);
        $this->renderResumen($precision);
        if ($precision->precisionPct === null) {
            $this->warn($precision->insufficientReason);
            return self::SUCCESS;
        }

        // 2) Top lemas / sitios
        $this->renderLemas($service->topLemasProblematicos($dias, $minSample, skipCache: $skipCache));
        $this->renderSitios($service->topSitiosProblematicos($dias, $minSample, skipCache: $skipCache));

        // 3) Drift 30d vs 60d
        $this->renderDrift($service->driftPorKeyword(skipCache: $skipCache));

        // 4) Confianza buckets
        $this->renderConfianza($service->confianzaGeminiVsDescartado($dias, skipCache: $skipCache));

        // 5) Recomendaciones automaticas (lemas con pct >= 80% y N >= 10)
        $this->renderRecomendaciones(/* ... */);

        return self::SUCCESS;
    }

    private function renderResumen(DescartadosMetricsDTO $m): void { /* $this->table([...]) */ }
    // ... one private renderer per section (tasks decides if extracted to a Formatter class)
}
```

**Permission decision**: NO `Gate::check()` in `handle()`. CLI runs without an authenticated user; SSH access is the trust boundary. Mirrors `LimpiarLogs.php` and `BackfillZombieResultados.php`. Documented in `$description`.

### `app/Livewire/Admin/PrecisionDashboard.php` (NEW)

```php
final class PrecisionDashboard extends Component
{
    public int $dias = 30;
    public int $minSample = 5;

    public function mount(): void
    {
        abort_unless(auth()->user()?->can('gestionar resultados'), 403);
    }

    #[Computed]
    public function precision(): DescartadosMetricsDTO
    {
        return app(DescartadosAnalisisService::class)->precisionGeneral($this->dias);
    }

    #[Computed]
    public function lemas(): Collection { /* topLemasProblematicos */ }

    #[Computed]
    public function sitios(): Collection { /* topSitiosProblematicos */ }

    #[Computed]
    public function drift(): Collection { /* driftPorKeyword */ }

    #[Computed]
    public function confianza(): Collection { /* confianzaGeminiVsDescartado */ }

    public function refrescarAhora(): void
    {
        app(DescartadosAnalisisService::class)->flushCache();
        unset($this->precision, $this->lemas, $this->sitios, $this->drift, $this->confianza);
        $this->dispatch('chart-data-updated');
    }

    public function render(): View
    {
        return view('livewire.admin.precision-dashboard')
            ->layout('layouts.app', ['title' => 'Precision — Descartados']);
    }
}
```

Root element in the Blade carries `wire:poll.300s` (NOT `wire:poll.keep-alive` — we want a re-render to recompute `#[Computed]` props).

### `resources/views/livewire/admin/precision-dashboard.blade.php` (NEW)

Layout: extends `layouts.app` (NOT `layouts.dashboard` — there is no separate dashboard layout). Chart.js MUST be pushed from this view because `layouts/app.blade.php` does NOT include it (verified — it only emits `@stack('scripts')` at line 203).

Structure:
```blade
<div wire:poll.300s class="space-y-6">

    {{-- Header + boton Refrescar --}}
    <div class="flex justify-between items-center">
        <h2>Precision — Descartados (ultimos {{ $dias }} dias)</h2>
        <button wire:click="refrescarAhora" class="simo-btn">Refrescar ahora</button>
    </div>

    {{-- Resumen card --}}
    @if ($this->precision->precisionPct === null)
        <div class="simo-alert">{{ $this->precision->insufficientReason }}</div>
    @else
        <x-precision-resumen :metrics="$this->precision" />
    @endif

    {{-- 2x2 grid de 4 charts --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div x-data="lemasChart(@js($this->lemas->toArray()))" wire:ignore>
            <canvas x-ref="canvas"></canvas>
        </div>
        <div x-data="sitiosChart(@js($this->sitios->toArray()))" wire:ignore>
            <canvas x-ref="canvas"></canvas>
        </div>
        <div x-data="driftChart(@js($this->drift->toArray()))" wire:ignore>
            <canvas x-ref="canvas"></canvas>
        </div>
        <div x-data="confianzaChart(@js($this->confianza->toArray()))" wire:ignore>
            <canvas x-ref="canvas"></canvas>
        </div>
    </div>
</div>

@push('scripts')
    {{-- Chart.js NO esta en layouts/app — debe cargarse aqui --}}
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script>
        function lemasChart(data) { return { chart: null, init() { /* render */ } } }
        function sitiosChart(data) { return { chart: null, init() { /* render */ } } }
        function driftChart(data)  { return { chart: null, init() { /* render */ } } }
        function confianzaChart(data) { return { chart: null, init() { /* render */ } } }

        // Listener para refrescar cuando Livewire dispatchea
        document.addEventListener('livewire:init', () => {
            Livewire.on('chart-data-updated', () => {
                // Alpine roots reciben el evento via $watch o reactive props (tasks decide)
            });
        });
    </script>
@endpush
```

Key rule: `wire:ignore` on each chart wrapper so Livewire poll re-renders DON'T destroy the canvas. Alpine reads the `@js(...)` data on init; on `chart-data-updated` event Alpine calls `chart.update()` with fresh data fetched via `$wire`. Tasks may simplify by re-keying `wire:ignore.self` and reading data from `data-payload="..."` attributes instead.

### `routes/web.php` (MODIFIED)

Add inside the existing `Route::middleware(['auth', 'usuario.activo'])->group(...)` block (line 26-77):

```php
use App\Livewire\Admin\PrecisionDashboard;

Route::get('/admin/precision', PrecisionDashboard::class)
    ->middleware('permission:gestionar resultados')
    ->name('admin.precision');
```

Verified: `permission` middleware alias exists in `bootstrap/app.php:17` (Spatie `PermissionMiddleware`). Route `/admin/precision` does NOT collide with anything in `routes/web.php`.

Sidebar link in `layouts/app.blade.php` is OPTIONAL for this SDD — tasks decides. If added, gate with `@can('gestionar resultados')`.

## Cache Strategy

| Aspect | Spec |
|---|---|
| Backend | Default Laravel cache (database/file driver — no tags) |
| Key shape | `descartados:{method}:{param1}:{param2}:...` (no JSON hash; explicit params keep keys debuggable) |
| TTL | 300s constant (`DescartadosAnalisisService::CACHE_TTL`) |
| Alignment | `wire:poll.300s` matches TTL → max 5 min disagreement window between two open browsers |
| Bypass | `--no-cache` flag in CLI passes `skipCache=true` to each method (read-through, NO write-back) |
| Invalidation | `flushCache()` enumerates known keys + `Cache::forget()` each. Called by Livewire `refrescarAhora()` and by tests. |
| `getNegativeExamples` | NOT cached — only used by future T3 batch jobs that want fresh data |

## Testing Strategy

| Layer | File | Tests |
|---|---|---|
| Migration | `tests/Feature/Database/SitioIdIndexMigrationTest.php` | `it_creates_btree_index_on_sitio_id` (skip on SQLite via `$this->markTestSkipped` if not pgsql; or assert via `Schema::hasIndex` polyfill) |
| Service | `tests/Feature/Services/DescartadosAnalisisServiceTest.php` | `it_calculates_precision_correctly`, `it_returns_null_precision_when_below_min_global`, `it_excludes_keywords_below_min_sample`, `it_ranks_lemas_by_descartado_percentage`, `it_handles_empty_drift_window_gracefully`, `it_buckets_confianza_correctly`, `it_exposes_negative_examples_seam`, `it_caches_results_with_correct_ttl`, `it_skips_cache_with_skip_cache_flag`, `it_flushes_cache` |
| Command | `tests/Feature/Commands/AnalizarDescartadosTest.php` | `it_reports_precision_when_sufficient_sample`, `it_reports_insufficient_data_when_below_threshold`, `it_filters_by_categoria`, `it_filters_by_keyword`, `it_respects_min_sample_flag`, `it_bypasses_cache_with_no_cache_flag` |
| Livewire | `tests/Feature/Livewire/PrecisionDashboardTest.php` | `it_redirects_unauthenticated_users`, `it_aborts_403_for_users_without_gestionar_resultados`, `it_renders_with_initial_data_for_admin`, `it_refreshes_cache_when_button_clicked`, `it_emits_chart_data_updated_after_refresh`, `it_shows_insufficient_data_message_when_empty` |

All tests use `RefreshDatabase` + `ResultadoScraping::flushEventListeners()` per `BackfillZombieResultadosTest` precedent. Service tests fix `Carbon::setTestNow()` for deterministic windows.

## Migration / Rollout

| Phase | Action |
|---|---|
| 1 | Deploy migration → btree index appears (CONCURRENTLY, zero lock) |
| 2 | Deploy service + DTOs + tests → no user-facing change |
| 3 | Deploy command → `php artisan simo:analizar-descartados` available on VPS |
| 4 | Deploy Livewire + view + route → `/admin/precision` live for admin/supervisor |
| Rollback | Revert commits in reverse order. Migration `down()` drops index safely on both drivers. No data loss anywhere. |

No feature flag needed — additive change behind a permission gate. Removing the route line is sufficient to hide the page.

## Risks Deep-Dive

| Risk | Mitigation in design |
|---|---|
| **Cache drift** between two open dashboards | TTL=300s + `wire:poll.300s` exactly aligned → max 5 min disagreement. `Refrescar ahora` button forces immediate sync. |
| **Chart.js dual-load** | `layouts/app.blade.php` does NOT load Chart.js. Pushed only from this view via `@push('scripts')`. No collision possible. |
| **`CONCURRENTLY` on SQLite** | Driver-conditional via `DB::getDriverName()` — pattern proven in `2026_05_09_100003_*.php`. SQLite gets plain `CREATE INDEX IF NOT EXISTS`. |
| **Permission check in CLI** | Skipped intentionally (mirrors `LimpiarLogs.php`). Documented in `$description`. SSH access = elevated trust. |
| **Misleading initial precision (10 relevantes)** | DTO carries `insufficientReason`; UI shows "datos insuficientes" instead of misleading %. CLI `warn()`s and exits clean. |
| **Drift window empty** | `pctAnterior=null` + `driftPpt=null` rendered as "N/D" in the table; chart skips null points. |
| **Cache tag unsupported** | Confirmed — `DashboardMetricsService` uses key-level invalidation, NOT tags. Mirroring is safe. |

## Open Questions Deferred to Tasks

- [ ] Migration timestamp (cosmetic — `2026_05_20_120000` is the proposed default; tasks may bump if multiple migrations land together)
- [ ] Whether to extract section renderers from `AnalizarDescartados::handle()` into a separate `DescartadosReportFormatter` class (judgment call on file size; current proposal keeps them as private methods)
- [ ] Whether the 4 charts in the Blade view should split into 4 child Livewire sub-components (only if the parent file exceeds ~200 lines)
- [ ] Whether to add a sidebar link for `/admin/precision` in `layouts/app.blade.php` under "Sistema" section (UX call — orchestrator may want to keep it CLI-only first)
- [ ] Exact list of keys enumerated in `flushCache()` — tasks must keep this in sync with `cacheKey()` shape

**Artifact files**: `openspec/changes/feedback-loop-from-descartados/design.md`
**Next phase**: `sdd-tasks`
