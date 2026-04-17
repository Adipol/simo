# Proposal: Refactor Búsqueda SIMO

## Intent

El sistema de búsqueda usa `palabras_clave` como fuente de verdad para el scraper Python. Con la incorporación de `familias_lemas` (lematización con spaCy), `palabras_clave` quedó como deuda técnica duplicada. Este cambio completa la migración: el scraper lee directamente de `familias_lemas`, se eliminan las tablas legacy, Gemini retorna múltiples personas por artículo (evitando pérdida de datos), y el prompt de Cambios PEP se afina para ignorar cambios de documentos.

## Scope

### In Scope
- Reemplazar `get_keywords()` del scraper Python para leer desde `familias_lemas`
- Nuevas categorías semánticas: `PEP-designacion`, `PEP-renuncia`, `OPI-crimen` en `familias_lemas.categoria` y `resultados_scraping.categoria`
- Nueva tabla `resultado_personas` para soporte multi-persona por artículo
- Actualizar `GeminiFiltroService` + `GeminiPromptBuilder.filtroPEP()` + `FiltroResultadoDTO` para retornar array de personas
- Actualizar `GeminiPromptBuilder.analisisCambio()` para ignorar cambios de documentos/resoluciones
- Actualizar `CategoriaFamilia` enum con los nuevos valores (actualmente: `designacion`, `renuncia`, `crimen`)
- Eliminar tablas `palabras_clave` y `keyword_paises` con sus migraciones de drop
- Eliminar `App\Livewire\Scraper\Keywords`, ruta, nav link y permiso asociado
- Truncar `resultados_scraping` (clean start confirmado por el usuario)

### Out of Scope
- Cambios en la UI de `FamiliasLemas` más allá de actualizar el enum de categorías
- Migración de datos históricos de `palabras_clave` → `familias_lemas`
- Cambios en el scraper de sitios web (`Sitios`, `CargosPep`, `EntidadesPublicas`)
- Modificación del prompt de análisis de Gemini Pro (solo el filtro Flash)

## Capabilities

### New Capabilities
- `multi-persona-gemini`: Gemini Flash retorna array de personas por artículo; persistido en `resultado_personas`

### Modified Capabilities
- `gemini-filtro-pep`: `filtroPEP()` ahora retorna array; `GeminiFiltroService` persiste múltiples registros en `resultado_personas`
- `scraper-lemas`: `get_keywords()` y `get_categorias_activas()` en Python leen de `familias_lemas` directamente
- `categorias-familia`: Enum renombrado a `PEP-designacion`, `PEP-renuncia`, `OPI-crimen`; `buildContextoCategoria()` actualizado
- `cambios-pep-prompt`: `analisisCambio()` instruye explícitamente a ignorar cambios de documentos y resoluciones

## Approach

**5 fases independientemente deployables**, en orden estricto para evitar romper el scraper:

| Fase | Contenido | Deploy sin romper prod |
|------|-----------|----------------------|
| 1 — Categorías | Migración de valores en `familias_lemas.categoria` + `CategoriaFamilia` enum + `buildContextoCategoria()` | ✓ |
| 2 — Python scraper | `database.py`: `get_keywords()` → `familias_lemas`; `get_categorias_activas()`; fix `ORDER BY` MySQL→PostgreSQL | ✓ (requiere restart VPS) |
| 3 — Drop keywords | Migrations drop, delete `Livewire\Keywords`, ruta, nav, permiso, truncate `resultados_scraping` | ✓ (irreversible) |
| 4 — Multi-persona | Nueva tabla `resultado_personas`, nuevo prompt array, nuevo DTO, actualizar `GeminiFiltroService` | ✓ |
| 5 — Prompt Cambios PEP | `analisisCambio()` con instrucción explícita anti-documentos | ✓ |

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `app/Enums/CategoriaFamilia.php` | Modified | Nuevos valores: `PEP-designacion`, `PEP-renuncia`, `OPI-crimen` |
| `app/Services/Gemini/GeminiPromptBuilder.php` | Modified | `buildContextoCategoria()` + `filtroPEP()` multi-persona + `analisisCambio()` |
| `app/Services/Gemini/GeminiFiltroService.php` | Modified | Persiste array en `resultado_personas` en vez de un único campo en `resultados_scraping` |
| `app/Services/Gemini/DTOs/FiltroResultadoDTO.php` | Modified | Soporte para array de personas |
| `app/Livewire/Scraper/Keywords.php` | Removed | Eliminar componente completo |
| `app/Livewire/Scraper/FamiliasLemas.php` | Modified | Actualizar enum de categorías en UI |
| `app/Livewire/Scraper/Resultados.php` | Modified | Filtros para nuevas categorías |
| `routes/web.php` | Modified | Remover ruta keywords |
| `resources/views/layouts/app.blade.php` | Modified | Remover nav link keywords |
| `resources/views/livewire/scraper/keywords.blade.php` | Removed | Eliminar vista |
| `database/migrations/` | New (×3) | create `resultado_personas`, update categorias, drop `palabras_clave`+`keyword_paises` |
| `scraper_v2.2/core/database.py` | Modified | `get_keywords()`, `get_categorias_activas()`, `verify_tables()`, fix ORDER BY |
| `scraper_v2.2/scheduler.py` | Modified | Mapping de categorías actualizado |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Scraper VPS no reiniciado tras cambio Python | Med | Documentar paso de restart en tasks; agregar health-check |
| Drop `palabras_clave` irreversible | Low | Usuario confirmó clean start; backup manual previo al deploy |
| `ORDER BY FIELD()` MySQL-only en Python | High | Ya en PostgreSQL — reemplazar por `CASE WHEN` o sort en Python |
| `resultado_personas` rompe queries existentes de Resultados | Med | Fase 4 aislada; verificar Livewire Resultados antes de deploy |
| Categorías antiguas (`crimen`, `designacion`, `renuncia`) en datos históricos | N/A | Truncate en Fase 3 elimina todo — no hay datos que migrar |

## Rollback Plan

- **Fase 1**: Revertir migración de categorías + revertir enum → sin impacto funcional
- **Fase 2**: Revertir `database.py` en VPS + restart scraper
- **Fase 3**: ⚠️ IRREVERSIBLE — `palabras_clave` se droppea y `resultados_scraping` se trunca. Único rollback: restaurar backup manual pre-deploy
- **Fase 4**: Drop `resultado_personas` + revertir DTO/Service/Prompt a versión single-persona
- **Fase 5**: Revertir `analisisCambio()` al prompt anterior

## Dependencies

- Scraper Python corriendo en VPS — requiere acceso SSH para restart en Fase 2
- `familias_lemas` debe tener datos cargados con las nuevas categorías antes de Fase 2

## Success Criteria

- [ ] Scraper Python inserta resultados usando `familias_lemas` como fuente (sin leer `palabras_clave`)
- [ ] `resultado_personas` se puebla con ≥1 persona por artículo analizado por Gemini Flash
- [ ] Tablas `palabras_clave` y `keyword_paises` no existen en producción
- [ ] `CategoriaFamilia` enum tiene exactamente: `PEP-designacion`, `PEP-renuncia`, `OPI-crimen`
- [ ] `buildContextoCategoria()` retorna contexto correcto para los 3 nuevos valores
- [ ] `analisisCambio()` no reporta cambios de documentos/resoluciones como cambios de autoridades
- [ ] Tests PHPUnit pasan (sin regresiones en `GeminiFiltroService`, `GeminiPromptBuilder`)
