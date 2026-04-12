# Proposal: Sistema Feedback Clasificaciones

## Intent

Los analistas necesitan poder corregir clasificaciones erróneas de Gemini (PEP/OPI/NO_REL) directamente desde la tabla de resultados. Sin este mecanismo, los errores de clasificación se acumulan sin forma de medirlos ni corregirlos, comprometiendo la calidad del sistema de inteligencia.

## Scope

### In Scope
- Migración: tabla `clasificaciones_feedback` con composite PK `(resultado_scraping_id, usuario_id)`
- Modelo `ClasificacionFeedback` con `$fillable`, `$casts`, scopes y relaciones
- Permiso `dar feedback clasificaciones` seedeado a roles `admin` y `supervisor` (confirmado: son los únicos 2 roles con acceso a scraper avanzado; `operador` no recibe el permiso — calidad sobre cantidad)
- Botones inline "✅ Correcto" / "❌ Incorrecto" por fila (solo si `gemini_analyzed = true` + `@can`)
- "Correcto" → guardado inmediato sin modal
- "Incorrecto" → modal `feedbackModalId` con form de corrección
- Métodos en `Resultados.php`: `guardarFeedbackCorrecto()`, `abrirModalFeedback()`, `guardarFeedbackIncorrecto()`
- Lógica upsert via `updateOrCreate` por `(resultado_scraping_id, usuario_id)`
- Estados visuales por fila: neutral / correcto / incorrecto
- Tests: Feature (Livewire) + Unit (modelo y scopes)

### Out of Scope
- Dashboard de métricas y agregaciones (change separado)
- Operaciones de feedback masivo
- Notificaciones/email cuando una clasificación es disputada
- Feedback sobre tabla `cambios` de PEP Monitor (change separado)
- Ajuste adaptativo del prompt de Gemini basado en feedback
- Export/reporting de feedback
- Vista de historial de feedback por usuario

## Capabilities

### New Capabilities
- `classification-feedback`: Registro, actualización y consulta del feedback de usuarios sobre clasificaciones Gemini por resultado de scraping

### Modified Capabilities
- `scraper-resultados-ui`: Agregar acciones de feedback (botones inline + modal) al listado de resultados

## Approach

Extender el componente Livewire `Resultados` existente siguiendo el patrón `verAnalisisId` → `feedbackModalId`. No se crea un componente separado. Nueva tabla con composite PK garantiza una fila por usuario por resultado. Upsert via `updateOrCreate`. Permission guard inline con `@can`. Cascade on delete (los resultados raramente se borran; se usa `descartado` flag).

**Roles que reciben `dar feedback clasificaciones`** (verificado en `RolesPermisosSeeder.php`):
- ✅ `admin` — acceso total
- ✅ `supervisor` — analista senior, gestiona todo menos usuarios
- ❌ `operador` — solo lectura y acciones básicas; excluido intencionalmente

## Affected Areas

| Área | Acción | Descripción |
|------|--------|-------------|
| `database/migrations/xxxx_create_clasificaciones_feedback_table.php` | Crear | Tabla nueva con composite PK, FK cascade, indexes |
| `app/Models/ClasificacionFeedback.php` | Crear | Modelo con fillable, casts, scopes, relaciones |
| `app/Models/ResultadoScraping.php` | Modificar | Agregar relación `feedback()` hasMany |
| `app/Models/User.php` | Modificar | Agregar relación `clasificacionesFeedback()` hasMany |
| `app/Livewire/Scraper/Resultados.php` | Modificar | Props `feedbackModalId`, métodos feedback, eager loading |
| `resources/views/livewire/scraper/resultados.blade.php` | Modificar | Botones por fila + modal feedback |
| `database/seeders/RolesPermisosSeeder.php` | Modificar | Permiso `dar feedback clasificaciones` a admin y supervisor |
| `tests/Feature/Livewire/Scraper/ResultadosFeedbackTest.php` | Crear | Tests Feature Livewire |
| `tests/Unit/Models/ClasificacionFeedbackTest.php` | Crear | Tests Unit modelo y scopes |

## Risks

| Riesgo | Probabilidad | Mitigación |
|--------|-------------|------------|
| `operador` se siente excluido del workflow | Baja | El permiso puede asignarse luego; decisión consciente y documentada |
| Composite PK requiere ajuste del modelo Eloquent (no usa `id` auto-increment) | Media | Configurar `$primaryKey`, `$incrementing = false`, `$keyType` en el modelo |
| Eager loading de feedback en paginación puede degradar performance | Media | Cargar solo feedback del usuario autenticado con `whereUserId(Auth::id())` |
| Race condition si dos pestañas guardan simultáneamente | Baja | `updateOrCreate` es atómico a nivel SQL + unique constraint en DB |

## Rollback Plan

1. Revertir migration: `php artisan migrate:rollback` (tabla nueva, sin datos previos afectados)
2. Revertir cambios en `Resultados.php` y blade (vía git)
3. Revertir `RolesPermisosSeeder.php` y re-seedear: `php artisan db:seed --class=RolesPermisosSeeder`
4. Ningún dato existente es modificado — rollback es seguro

## Dependencies

- `lematizacion-pep-opi` COMPLETO — los campos `gemini_analyzed`, `gemini_is_pep`, `gemini_categoria`, `gemini_confianza`, `gemini_nombre`, `gemini_cargo`, `gemini_motivo` ya existen en `resultados_scraping`
- Spatie laravel-permission ya instalado y configurado
- Livewire 4 disponible (componente `Resultados` ya usa WithPagination)

## Success Criteria

- [ ] Migración ejecuta sin errores en SQLite (tests) y PostgreSQL (producción)
- [ ] Solo filas con `gemini_analyzed = true` muestran botones de feedback
- [ ] Solo usuarios con `dar feedback clasificaciones` ven los botones (`admin`, `supervisor`)
- [ ] "Correcto" guarda sin abrir modal; feedback aparece inmediatamente en el estado visual de la fila
- [ ] "Incorrecto" abre modal; `motivo` y `corregidoCategoria` son requeridos; guardado cierra el modal
- [ ] Si el usuario ya dio feedback, los botones reflejan su estado actual; el modal se pre-rellena
- [ ] Upsert funciona: cambiar de "correcto" a "incorrecto" actualiza el registro existente (no duplica)
- [ ] Tests Feature >85% cobertura del flujo de feedback
- [ ] Tests Unit cubren todos los scopes del modelo
- [ ] `php artisan test` pasa en verde
