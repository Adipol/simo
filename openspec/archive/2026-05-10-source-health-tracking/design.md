# Design: source-health-tracking

**Change**: `source-health-tracking` · **Phase**: design · **Date**: 2026-05-10

## Technical Approach

Python scraper writes one row per `procesar_fuente()` execution to `log_fuente_runs` via a try/finally wrapper. Laravel reads with a single LATERAL JOIN query, derives per-source status (config thresholds), caches the aggregate `SourceHealthSummaryDTO` for 60s, and renders a pill in `<x-dashboard.health-strip>` between "Cola Gemini" and "Latencia".

## Spec assumptions corrected

| Brief said | Reality | Action |
|---|---|---|
| `scraper/` path | `scripts/website_monitor_pro/pep_monitor.py` | Use real path |
| `registrar_inicio_script` / `registrar_fin_script` | Methods are `log_inicio` / `log_fin` | Follow `log_inicio`/`log_fin` pattern for new `registrar_fuente_run()` |
| DTO at `app/DTOs/` | Project uses `app/Services/Dashboard/DTOs/` | Place new DTOs in existing namespace |
| Cache via `DashboardCacheManager` add to `knownDashboardKeys()` | Same — confirmed | Register `dashboard:source-health` in `knownDashboardKeys()` for `forgetAll()` |

## Architecture Decisions

### D1 — Schema timestamps: `TIMESTAMP WITHOUT TIME ZONE` (UTC)
**Alternatives**: `TIMESTAMPTZ`. **Rationale**: Matches `log_scripts` convention (`inicio`, `fin`). Python writes `datetime.now(timezone.utc)`; PHP reads as UTC. REQ-3 enforces via tests.

### D2 — One query (LATERAL JOIN) for `getSummary()`
**Alternatives**: window-function CTE, N queries. **Rationale**: LATERAL JOIN with `LIMIT 10` per fuente fits within the `consecutive_failures_dead` threshold; PostgreSQL plans index scan on `idx_lfr_fuente_started`. SQLite path uses a correlated subquery (acceptable for ≤24 fuentes in tests).

### D3 — Single cache key `dashboard:source-health`, TTL 60s, no per-fuente cache
**Alternatives**: per-fuente keys. **Rationale**: REQ-10 only budgets `getSummary()`; `getPerSourceStatus()` is on-demand (admin drill-down, out of scope). Add explicit budget for per-source: ≤30ms cold, no cache.

### D4 — Duplicate inserts safe-by-design
**Rationale**: Status uses `ORDER BY started_at DESC LIMIT N`. Two rows from a retry collapse into the same tail window — consecutive_failures count remains correct. Documented in spec edge cases.

## File Changes

| File | Action | Why |
|---|---|---|
| `database/migrations/2026_05_10_110001_create_log_fuente_runs_table.php` | Create | Schema + 3 indexes + CASCADE FK |
| `config/dashboard.php` | Modify | Add `source_health` block (degraded=3, dead=10, cache_ttl=60) |
| `app/Services/Dashboard/DashboardSourceHealthService.php` | Create | `getSummary()`, `getPerSourceStatus()` |
| `app/Services/Dashboard/DTOs/SourceHealthDTO.php` | Create | Per-fuente record |
| `app/Services/Dashboard/DTOs/SourceHealthSummaryDTO.php` | Create | Aggregate + `available` flag |
| `app/Services/Dashboard/DashboardCacheManager.php` | Modify | Add `dashboard:source-health` to `knownDashboardKeys()` |
| `resources/views/components/dashboard/health-strip.blade.php` | Modify | Insert pill between Cola Gemini and Latencia |
| `app/Livewire/Dashboard*.php` (resolver) | Modify | Inject `SourceHealthSummaryDTO` into health-strip props |
| `scripts/website_monitor_pro/pep_monitor.py` | Modify | `DatabaseManager.registrar_fuente_run()` + try/finally in `procesar_fuente()` |
| `tests/Feature/Services/Dashboard/DashboardSourceHealthServiceTest.php` | Create | Dual-driver (SQLite + PostgreSQL via env flag) |
| `tests/Feature/Livewire/SourceHealthDashboardTest.php` | Create | Full render with seeded `log_fuente_runs` |
| `scripts/website_monitor_pro/tests/test_fuente_runs.py` | Create | 6 estado paths + DB-failure + finally-on-exception |

## Python instrumentation pattern

```python
def procesar_fuente(self, fuente: dict) -> None:
    fuente_id = fuente["id"]
    started_at = datetime.now(timezone.utc)
    estado = "other"                   # defensive default
    http_status: Optional[int] = None
    cambios = 0
    error_msg: Optional[str] = None
    finished_at: Optional[datetime] = None

    try:
        # ... existing body, each branch sets `estado` before its return:
        #   success / http_error / timeout / captcha / parse_error
        # http_status / cambios / error_msg set where known.
        ...
        estado = "success"
    except requests.Timeout as e:
        estado, error_msg = "timeout", str(e)[:500]
    except requests.HTTPError as e:
        estado, error_msg = "http_error", str(e)[:500]
        http_status = getattr(e.response, "status_code", None)
    except Exception as e:                          # pragma: catch-all
        estado, error_msg = "other", str(e)[:500]
    finally:
        finished_at = datetime.now(timezone.utc)
        try:
            self.db.registrar_fuente_run(
                fuente_id=fuente_id,
                started_at=started_at,
                finished_at=finished_at,
                estado=estado,
                http_status=http_status,
                cambios_detectados=cambios,
                error_mensaje=error_msg,
            )
        except psycopg2.Error as log_err:
            logger.warning(f"No se pudo registrar fuente_run: {log_err}")
```

`registrar_fuente_run` mirrors `log_fin`: `_ensure_connection()`, single INSERT, wrap in try/except psycopg2.Error, log warning on failure. Duration computed in SQL: `EXTRACT(EPOCH FROM (finished_at - started_at))`.

## SQL for `getSummary()` (PostgreSQL)

```sql
WITH per_fuente AS (
    SELECT f.id,
           latest.estado,
           latest.rn
    FROM fuentes f
    LEFT JOIN LATERAL (
        SELECT estado,
               ROW_NUMBER() OVER (ORDER BY started_at DESC) AS rn
        FROM log_fuente_runs
        WHERE fuente_id = f.id
        ORDER BY started_at DESC
        LIMIT :dead_threshold
    ) latest ON TRUE
    WHERE f.activo = TRUE
)
SELECT id,
       COUNT(*) FILTER (WHERE estado IS NULL)                 AS no_rows,
       COUNT(*) FILTER (WHERE estado <> 'success')            AS fails,
       MIN(rn)  FILTER (WHERE estado = 'success')             AS first_ok_rank
FROM per_fuente
GROUP BY id;
```

PHP groups results: `first_ok_rank=NULL` → all-tail-fail → if `fails >= dead` → muerto, else if `>= degraded` → degradado; `first_ok_rank=1` → ok; otherwise count `first_ok_rank-1` tail failures vs thresholds. `no_rows` rows → `sin_info`.

SQLite test path: same algorithm via correlated subquery (per-fuente loop in PHP is acceptable at ≤24 rows in tests; production never hits this branch).

## DTOs (final)

```php
final readonly class SourceHealthDTO {
    public function __construct(
        public int $fuente_id,
        public string $nombre,
        public string $status,                       // ok|degradado|muerto|sin_info
        public int $consecutive_failures,
        public ?\DateTimeImmutable $last_run_at,
        public ?\DateTimeImmutable $last_ok_at,
    ) {}
    public static function fromArray(array $d): self { /* … */ }
}

final readonly class SourceHealthSummaryDTO {
    public function __construct(
        public int $total_fuentes_activas,
        public int $ok,
        public int $degradadas,
        public int $muertas,
        public int $sin_info,
        public bool $available,
        public \DateTimeImmutable $last_aggregation_at,
    ) {}
    public static function fromArray(array $d): self { /* … */ }
}
```

**Invariant**: `ok + degradadas + muertas + sin_info === total_fuentes_activas`. Constructor MAY assert in non-prod.

## UI integration (health-strip snippet)

```blade
{{-- Cola Gemini ... existing ... --}}

{{-- Fuentes (source health) — between Cola and Latencia --}}
@php($sh = $sourceHealth)
@if (! $sh->available)
    <div class="flex items-center gap-2 px-3 py-1.5 bg-white border border-zinc-200 rounded-full text-xs">
        <span class="w-2 h-2 rounded-full shrink-0 bg-zinc-300"></span>
        <span class="font-medium text-zinc-700">Fuentes</span>
        <span class="text-zinc-400 italic">Sin fuentes activas</span>
    </div>
@elseif ($sh->sin_info === $sh->total_fuentes_activas)
    <x-dashboard.health-pill label="Fuentes" status="no_data" value="Recolectando datos…" />
@else
    @php($status = $sh->muertas > 0 ? 'error' : ($sh->degradadas > 0 ? 'warning' : 'ok'))
    @php($parts = array_filter([
        $sh->ok > 0          ? "{$sh->ok} ok" : null,
        $sh->degradadas > 0  ? "{$sh->degradadas} degradadas" : null,
        $sh->muertas > 0     ? "{$sh->muertas} muerta(s)" : null,
        $sh->sin_info > 0    ? "{$sh->sin_info} sin datos" : null,
    ]))
    <x-dashboard.health-pill label="Fuentes" :status="$status" :value="implode(' / ', $parts)" />
@endif
```

Pill copy variants (REQ-7, REQ-8):
- `24 ok` (verde) · `22 ok / 2 degradadas` (amber) · `22 ok / 1 degradada / 1 muerta` (red)
- `Recolectando datos…` (gris) when all sin_info · `10 ok / 14 sin datos` (verde, partial warmup) · `Sin fuentes activas` (gris) when `available=false`

## Testing Strategy

| Layer | Test | Covers |
|---|---|---|
| PHPUnit Feature | `DashboardSourceHealthServiceTest` (dual-driver) | REQ-4, REQ-5, REQ-6, REQ-10 |
| PHPUnit Feature | `SourceHealthDashboardTest` | REQ-7, REQ-8 + permission visibility |
| PHPUnit Feature | `LogFuenteRunsCascadeTest` | REQ-9 (FK CASCADE) |
| Migration | implicit in `RefreshDatabase` | REQ-1 |
| Python pytest | `test_fuente_runs.py` — 6 estado paths + DB-fail + finally-on-exception | REQ-2, REQ-3 |

Python tests patch `DatabaseManager.registrar_fuente_run` and inject fake `requests` responses to drive each branch.

## Migration / Rollout

1. `php artisan migrate` (creates `log_fuente_runs`)
2. Deploy Laravel (pill renders "Recolectando datos…")
3. `supervisorctl restart simo-runner` (Python starts writing rows)
4. After ~1 cycle (~5 min) pill shows real data

**Order is non-negotiable**: inverting (Python first) crashes the scraper because the table does not exist.

## Risk Register (post-design)

| Risk | Mitigation |
|---|---|
| `finished_at` NULL → duration crash | SQL `EXTRACT(EPOCH FROM (NULLIF(finished_at, started_at) - started_at))`; PHP DTO accepts `?float` |
| LATERAL JOIN slow on large history | Index `idx_lfr_fuente_started`; `LIMIT :dead_threshold` caps rows per fuente; EXPLAIN ANALYZE post-deploy |
| Python DB failure breaks scraping | INSERT wrapped in own try/except psycopg2.Error → log warning, continue |
| Duplicate inserts (retry) | Status uses tail ordering — duplicates collapse into same window, no false threshold trigger |
| Partial warmup confusing UX | Pill copy explicitly distinguishes "Recolectando datos…" vs "10 ok / 14 sin datos" |
| Config thresholds changed in hot reload | Cache bust on next 60s expiry; no migration needed |

## Open Questions

None. Spec covers all observable behavior; design fills implementation gaps.
