# Proposal: Dashboard Estadísticas

## Intent

El sistema SIMO genera detecciones Gemini y recibe feedback de clasificaciones, pero **no existe ninguna vista que cierre ese ciclo** con métricas agregadas. Admin y supervisor no pueden responder: "¿cuán preciso es el sistema?", "¿qué países generan más PEPs?", "¿qué cargos fallan más?". Esta change agrega una sección de analítica directamente en el dashboard existente, gateada por permiso, sin introducir nuevas rutas.

## Scope

### In Scope
- Permiso `ver dashboard estadisticas` (admin + supervisor, NOT operador)
- `DashboardMetricsService` — agregaciones SQL con cache 5 min
- DTOs para transferir datos de cada grupo de widgets al componente
- Toggle "Ver estadísticas" en `Dashboard.php` / `dashboard.blade.php` (collapsible via Alpine.js `x-show`)
- 4 KPI cards: Total PEPs, Total OPIs, Accuracy %, Unread ratio
- Filter bar: date range (preset periods), country multi-select, category
- System Precision Widget: accuracy por confidence bucket (0-50, 51-80, 81-100)
- Volume Trend Chart: line chart PEPs/OPIs últimos 12 meses (Chart.js CDN versioned)
- Top Failing Positions table: cargos con mayor error rate (mínimo 3 muestras)
- Geographic Distribution table: país, PEPs, OPIs, avg confianza, error rate
- Recent High-Confidence PEPs list: últimos 10 con confianza ≥ 90
- Latest Corrections list: últimos 10 feedbacks con nombre de usuario
- Trend Indicators: este mes vs mes anterior (PEPs, OPIs, Feedback)
- Tests unitarios de `DashboardMetricsService` y feature tests del componente

### Out of Scope
- Exportación CSV/PDF de métricas
- Alertas automáticas por umbral
- Métricas por usuario individual
- Custom date range picker (más allá de presets)
- Actualizaciones en tiempo real / websockets
- Dashboard propio para operador

## Capabilities

### New Capabilities
- `dashboard-metrics`: Servicio backend que computa y cachea agregaciones del sistema (precision, volume, geographic, trends, recent activity)

### Modified Capabilities
- `dashboard-page`: El dashboard existente adquiere una sección colapsable de estadísticas gateada por permiso, con chart via CDN y Alpine.js

## Approach

**Approach 2 + Variant C + Chart.js CDN.**

`DashboardMetricsService` encapsula todas las raw SQL queries con `Cache::remember()` (TTL 5 min, key parametrizada por filtros). Los DTOs — co-localizados en `app/Services/Dashboard/DTOs/` siguiendo el patrón Gemini — transportan los datos al componente Livewire. `Dashboard.php` expone una propiedad `$mostrarEstadisticas` (bool) y llama al servicio solo cuando la sección está visible. La vista usa `wire:ignore` en los contenedores de Chart.js para sobrevivir re-renders de Livewire. Alpine.js maneja el toggle y la inicialización de charts leyendo datos JSON embebidos en el HTML via `@json`.

Chart.js se carga desde CDN con versión fija (`chart.js@4.4.1`) en el stack de `layouts.app` — solo se inyecta cuando la vista lo necesita via `@push('scripts')`.

## Affected Areas

| Archivo | Acción | Descripción |
|---------|--------|-------------|
| `app/Livewire/Dashboard.php` | Modified | Agrega `$mostrarEstadisticas`, inyecta `DashboardMetricsService`, pasa DTOs a la vista |
| `resources/views/livewire/dashboard.blade.php` | Modified | Agrega sección colapsable con los 11 widgets, `wire:ignore` en chart containers |
| `app/Services/Dashboard/DashboardMetricsService.php` | New | Agregaciones SQL con cache 5 min, acepta filtros (dateRange, pais, categoria) |
| `app/Services/Dashboard/DTOs/PrecisionMetricsDTO.php` | New | Accuracy global, por bucket de confianza |
| `app/Services/Dashboard/DTOs/VolumeMetricsDTO.php` | New | PEPs/OPIs por mes (array 12 meses), totales, trends |
| `app/Services/Dashboard/DTOs/GeographicMetricsDTO.php` | New | Colección por país: peps, opis, avg_confianza, error_rate |
| `app/Services/Dashboard/DTOs/RecentActivityDTO.php` | New | Últimos high-conf PEPs + últimas correcciones |
| `app/Services/Dashboard/DTOs/TrendIndicatorsDTO.php` | New | Deltas % este mes vs mes anterior para PEPs, OPIs, feedback |
| `database/seeders/RolesPermisosSeeder.php` | Modified | Agrega `ver dashboard estadisticas` al array de permisos; admin y supervisor lo reciben, operador no |
| `tests/Unit/Services/DashboardMetricsServiceTest.php` | New | Tests unitarios de cada método de agregación con factory data |
| `tests/Feature/Livewire/DashboardEstadisticasTest.php` | New | Visibilidad de sección por permiso, toggle, datos cargados correctamente |

## Risks

| Riesgo | Probabilidad | Mitigación |
|--------|-------------|------------|
| Chart.js CDN no disponible | Baja | Versión fija en jsdelivr; fallback: mover a npm en 15 min |
| `wire:ignore` + Livewire poll borra charts | Media | Alpine `x-init` re-inicializa chart con datos JSON inline; no depende de fetch posterior |
| Queries lentas si `resultados_scraping` > 50k rows | Baja (tabla pequeña hoy) | Partial index en `WHERE gemini_analyzed = true`; cache 5 min amortigua |
| Re-run del seeder borra el permiso nuevo | Baja | `syncPermissions` es idempotente; `Permission::firstOrCreate` garantiza no duplicados |
| `clasificaciones_feedback` vacío en dev | Media | Widgets de precisión muestran estado vacío explícito ("Sin datos suficientes") |

## Rollback Plan

1. `git revert` del commit de esta change — restaura todos los archivos modificados
2. Si el permiso `ver dashboard estadisticas` quedó en la DB de producción: `php artisan tinker` → `Permission::where('name', 'ver dashboard estadisticas')->delete()`
3. No hay migraciones de esquema en esta change — no hay rollback de DB necesario
4. Chart.js CDN es solo un `<script>` en la vista — se elimina con el revert

## Dependencies

- `lematizacion-pep-opi` ✅ COMPLETE — provee campos `gemini_*` en `resultados_scraping`
- `sistema-feedback-clasificaciones` ✅ COMPLETE — provee tabla `clasificaciones_feedback` con `tipo` y `clasificacion_snapshot`

## Success Criteria

- [ ] Usuario con rol `admin` o `supervisor` ve el toggle "Ver estadísticas" en `/dashboard`
- [ ] Usuario con rol `operador` NO ve el toggle (sección oculta vía `@can`)
- [ ] Al hacer click en "Ver estadísticas", la sección se expande sin recargar la página
- [ ] Los 4 KPI cards muestran valores calculados (no hardcodeados)
- [ ] El Volume Trend Chart renderiza un line chart con datos de los últimos 12 meses
- [ ] La tabla Top Failing Positions solo muestra cargos con ≥ 3 muestras
- [ ] El filter bar por país filtra todos los widgets simultáneamente
- [ ] Las métricas se sirven desde cache en la segunda carga (TTL 5 min)
- [ ] `php artisan test` pasa con >85% de cobertura en el service
- [ ] Re-run del seeder es idempotente (no duplica el permiso)
