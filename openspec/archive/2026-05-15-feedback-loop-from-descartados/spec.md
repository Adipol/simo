# Delta Spec: feedback-loop-from-descartados

**Phase**: spec · **Date**: 2026-05-14 · **Mode**: hybrid

---

## New Capabilities

### descartados-analisis (NEW — full spec)

Full spec at: `openspec/specs/descartados-analisis/spec.md`

| # | Requirement | Scenarios |
|---|---|---|
| REQ-1 | Precision General (≥10 global guard, labeled-only, archived-relevant counted) | 4 |
| REQ-2 | Ranking Keywords por % Descartado (N≥5 guard, configurable, ordered DESC) | 3 |
| REQ-3 | Ranking Sitios por % Descartado (JOIN sitios_web, N≥5 guard) | 2 |
| REQ-4 | Drift Temporal 30d-vs-60d (delta_ppt, +10ppt alert, N/D when no prev data) | 3 |
| REQ-5 | Correlación Confianza Gemini (4 buckets, <20% discard → filter recommendation) | 3 |
| REQ-6 | Caché TTL=300s (CLI default cached, --no-cache bypass, CLI=UI consistency) | 3 |
| REQ-7 | Seam getNegativeExamples (high-conf descartados, T3 contract, not called by T1/T2) | 2 |
| REQ-8 | Migration índice sitio_id (CONCURRENTLY pgsql, standard sqlite, reversible) | 3 |

**Total**: 8 requirements · 23 scenarios

---

### precision-dashboard (NEW — full spec)

Full spec at: `openspec/specs/precision-dashboard/spec.md`

| # | Requirement | Scenarios |
|---|---|---|
| REQ-1 | Ruta protegida /admin/precision (admin ✓, operador 403, unauth redirect) | 3 |
| REQ-2 | Cuatro gráficos Chart.js (canvas elements, data matches CLI output) | 2 |
| REQ-3 | Auto-refresh wire:poll.300s (directive present, data refreshes on trigger) | 2 |
| REQ-4 | Botón "Refrescar ahora" (cache invalidation, fresh re-render) | 1 |
| REQ-5 | Mensaje datos insuficientes (<10 labeled: message shown, no charts) | 2 |
| REQ-6 | CLI simo:analizar-descartados (--dias, --categoria, --keyword, --min-sample, --no-cache, recommendations) | 8 |

**Total**: 6 requirements · 18 scenarios

---

## Modified Capabilities

None. Existing `ci-pipeline` and `dedupe-safety-net` capabilities are untouched.

---

## Out of Scope

| ID | Excluded |
|---|---|
| OUT-1 | T3 — auto-feedback to Gemini prompt with negative examples |
| OUT-2 | New columns on `resultados_scraping` (only one new index) |
| OUT-3 | Modifying scrapers (Python or Laravel) |
| OUT-4 | Changing the descartar/archivar UX |
| OUT-5 | ML pipeline or auto-threshold tuning |
| OUT-6 | Notifications / alerts |
| OUT-7 | Purge of old descartados |
| OUT-8 | Export of analysis output (CSV/PDF) |
| OUT-9 | Reports beyond 30d–60d drift window |
| OUT-10 | Comparison across operators (single-operator system) |

---

## Coverage Assessment

| Dimension | Status |
|---|---|
| Happy paths | ✅ All 10 REQs have ≥1 happy-path scenario |
| Edge cases | ✅ N<5 guard, N<10 global, empty prev period, null relevante, SQLite vs pgsql |
| Error/forbidden states | ✅ 403 operador, unauth redirect, datos insuficientes message |
| Seam / future extension | ✅ getNegativeExamples contract locked for T3 |
| Cache coherence | ✅ CLI=UI consistency scenario explicit in REQ-6 |

---

## Open Questions (deferred to design/tasks)

1. **Confianza bucket boundaries**: explore used `0-49 / 50-69 / 70-84 / 85-100`; prompt context uses `90-100 / 70-89 / 50-69 / <50`. Design phase resolves which boundaries to implement (spec uses abstract "four buckets" language intentionally).
2. **Per-sitio minimum sample**: explore used N≥3 for sitios; frozen decision uses N≥5 for all. Design confirms which threshold applies to sitios.
3. **Drift alert threshold**: spec uses +10 ppt. Design may tune this based on prod data at apply time.
4. **CLI exit code on `--no-cache` mid-failure**: design specifies error handling contract.
5. **Insufficient-data message copy**: exact Spanish string deferred to design/blade.

**File**: `openspec/changes/feedback-loop-from-descartados/spec.md`
**Canonical specs**: `openspec/specs/descartados-analisis/spec.md`, `openspec/specs/precision-dashboard/spec.md`
**Next phase**: `sdd-design` (parallel) → `sdd-tasks`
