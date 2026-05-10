# Archive Report: source-health-tracking

**Archived**: 2026-05-10 (mismo día que redesign-dashboard)
**Status**: COMPLETED
**Production deploy commits**: 230f7a0 (PR-A) + 0eb311f (PR-B)

## Outcome

Se entregó visibilidad per-fuente del scraper a través de dos capas: instrumentación Python que escribe una fila por ejecución de `procesar_fuente()` a la tabla `log_fuente_runs` (vía try/finally con 9 exit paths cubiertos), y un servicio Laravel (`DashboardSourceHealthService`) que agrega esos datos con un único query LATERAL JOIN y los expone en la health-strip del dashboard como un pill con 5 variantes de copy ("24 ok", "22 ok / 2 degradadas", "22 ok / 1 degradada / 1 muerta", "Recolectando datos…", "Sin fuentes activas").

La infraestructura reutilizó el patrón dual-driver SQL (PostgreSQL/SQLite) establecido en el SDD anterior (`redesign-dashboard`), y el Guardian Angel hook atrapó 3 issues pre-existentes en Python durante el apply — beneficio colateral del hook bien calibrado. El SDD fue completado en el mismo día que `redesign-dashboard`, conformando un maratón de dos SDDs consecutivos.

## Timeline

| Phase | Date | Notes |
|---|---|---|
| Explore | 2026-05-10 | Mapeo Python scraper + 4 product decisions surfaced |
| Propose | 2026-05-10 | 3 decisions confirmed: thresholds 3/10, alertas OUT, drill-down DIFFERRED |
| Spec | 2026-05-10 | 10 REQs, 33 scenarios, schema final |
| Design | 2026-05-10 | Cancellation: 2 factual errors en brief corregidas (path Python, métodos DatabaseManager) |
| Tasks | 2026-05-10 | 44 tasks, 2-PR split (Laravel + Python) |
| Apply PR-A | 2026-05-10 | PR #14 — Laravel side (29 tasks, size:exception ~600 loc) |
| Deploy PR-A | 2026-05-10 | VPS commit 230f7a0, migration applied, log_fuente_runs vacía |
| Apply PR-B | 2026-05-10 | PR #15 — Python side (15 tasks, ~240 loc) — Bonus: Guardian arregló 3 issues pre-existentes (f-string spurious, SQL injection riesgo, timeouts hardcoded) |
| Deploy PR-B | 2026-05-10 | VPS commit 0eb311f, supervisorctl restart simo-runner |
| Verify | 2026-05-10 | APPROVED (32/33 scenarios, 97%, 0 critical, 0 warning, 3 suggestions becoming follow-ups) |
| Archive | 2026-05-10 | (este reporte) |

## Metrics

- Total PRs merged: 2 (PR-A + PR-B)
- Tests added: 66 (50 PHPUnit + 16 pytest)
- Lines of code: ~840 netas (PR-A ~600 + PR-B ~240)
- Migrations: 1 (log_fuente_runs)
- Pre-commit `--no-verify` count: 0 (cero bypass — lección PR1.4 internalizada)
- Days elapsed: 0 (mismo día que redesign-dashboard, parte 2 del marathon)

## Engram Artifact IDs

| Phase | Engram ID |
|---|---|
| Explore | #841 |
| Proposal | #842 |
| Spec | #843 |
| Design | #844 |
| Tasks | #845 |
| Apply-progress (PR-A + PR-B) | — |
| Verify-report | #850 |
| Archive-report | (este documento) |

## Bonus quality detected by Guardian Angel during apply

1. F-string spurious: `f"js_playwright"` sin interpolación → `"js_playwright"`
2. SQL injection riesgo en `exportar_cambios`: f-string concat → safe condition list + `" ".join()`
3. 6 timeouts hardcoded en `pep_monitor.py` → `Config` fields con env var override

Estos 3 fixes son ortogonales al SDD pero arreglaron deuda real del proyecto. Worth subrayar como ejemplo de hook bien afinado.

## Lessons documented in engram

- Cross-language SDDs (Laravel + Python) viable con 2-PR split estricto
- Orden de deploy crítico cuando un lado crea tabla y el otro escribe a ella
- Dual-driver SQL pattern (PostgreSQL/SQLite) replicable de SDD anterior
- Guardian Angel atrapa deuda colateral cuando se touchen archivos pre-existentes — beneficio gratis del hook

## Tech debt for follow-up

- S-1: complete REQ-9 spec coverage (add "other fuente unaffected" cascade test) — minor
- S-2: DB-level `CHECK` constraint on `estado` enum (defensa en profundidad)
- S-3: `memory_limit=512M` en `phpunit.xml` para full suite (no afecta correctness)
- 23 pre-existing test failures siguen sin tocar (Gemini SSL/curl + seeders) — heredado del estado base
- Pre-existing tech debt en `analytics-section.blade.php` del SDD anterior — sigue pendiente
- `gemini_usage_log` (del SDD anterior) y `log_fuente_runs` (de éste) deberían tener retention policy explícita — anotar

## Open follow-ups (suggested as new issues/SDDs)

1. **`source-health-drill-down`** — UI panel/modal con detalle por fuente cuando haya data suficiente
2. **`source-health-alerts`** — alertas activas (Slack/email) cuando fuente cae a degradado/muerto
3. **`log-tables-retention-policy`** — política de retention para gemini_usage_log + log_fuente_runs (extender LogScriptRetentionService)
4. **`add-clock-skew-filter-latency`** (heredado del SDD anterior) — W-2 del verify previo
5. **`fix-analytics-section-blade-debt`** (heredado) — 3 violaciones Guardian

## Production state confirmed

- VPS deploys successful: PR-A at 230f7a0, PR-B at 0eb311f
- Migration `log_fuente_runs` aplicada (9 cols, 3 indexes, FK CASCADE)
- `simo-runner` reiniciado, código Python nuevo cargado
- log_fuente_runs vacía pre-primer-ciclo (esperable)
- Dashboard pill muestra "Recolectando datos…" (esperable durante warmup)

## Production validation pending (not blocking archive)

- Verificar que log_fuente_runs se populate con el primer ciclo del scraper post-restart
- Verificar que el pill cambia de "Recolectando datos…" a estado real (ej. "Fuentes: 24 ok")
- Verificar que las fuentes activas se mapean correctamente al estado derivado de log_fuente_runs

## Sister SDD completed today

`redesign-dashboard` — el SDD del dashboard v2 con sparklines, heatmap, instrumentación Gemini. Ese SDD dejó la infraestructura UI lista para que este SDD se enchufara via 1 pill nuevo. Sin redesign-dashboard previo, este SDD habría sido el doble de trabajo (rediseño + tracking).
