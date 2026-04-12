# Delta Spec: dashboard-estadisticas

## Metadata

- **Change**: dashboard-estadisticas
- **Date**: 2026-04-11
- **Status**: Draft
- **Capabilities**: dashboard-metrics (NEW), dashboard-page (MODIFIED)

---

## 1. dashboard-metrics (NEW)

### Purpose

Servicio backend que computa agregaciones cacheadas de datos del sistema para el dashboard de estadísticas.

### Requirements

#### REQ-1: Método getPrecisionMetrics

El sistema **DEBE** proveer un método `getPrecisionMetrics(array $filters): PrecisionMetricsDTO` que retorne métricas de precisión del sistema.

**Escenarios:**

| ID | Escenario | GIVEN | WHEN | THEN |
|----|-----------|-------|------|------|
| PM-01 | Sin feedbacks | La tabla `clasificaciones_feedback` está vacía | Se invoca `getPrecisionMetrics([])` | Retorna accuracy 0 con estado "Sin datos suficientes" |
| PM-02 | Solo feedbacks correctos | Existen 10 feedbacks tipo 'correcto', 0 'incorrecto' | Se invoca el método | Retorna accuracy 100% |
| PM-03 | Buckets de confianza | Existen feedbacks con confianza 30, 60, 90 | Se invoca el método | Retorna accuracy por bucket: 0-50, 51-80, 81-100 |

#### REQ-2: Método getVolumeMetrics

El sistema **DEBE** proveer un método `getVolumeMetrics(array $filters): VolumeMetricsDTO` que retorne métricas de volumen de detecciones.

**Escenarios:**

| ID | Escenario | GIVEN | WHEN | THEN |
|----|-----------|-------|------|------|
| VM-01 | Datos últimos 12 meses | Existen PEPs de los últimos 12 meses | Se invoca con filtro default | Retorna array con 12 meses de datos |
| VM-02 | Volumen por categoría | Existen PEPs y OPIs | Se invoca el método | Retorna desglose por categoría |
| VM-03 | Tabla vacía | No existen resultados scraping | Se invoca el método | Retorna arrays vacíos, no lanza error |

#### REQ-3: Método getGeographicMetrics

El sistema **DEBE** proveer un método `getGeographicMetrics(array $filters): GeographicMetricsDTO` que retorne métricas por país.

**Escenarios:**

| ID | Escenario | GIVEN | WHEN | THEN |
|----|-----------|-------|------|------|
| GM-01 | Datos por país | Existen PEPs de Argentina y Chile | Se invoca el método | Retorna filas con pais, peps_count, opis_count, avg_confianza, error_rate |
| GM-02 | País sin resultados | Bolivia no tiene PEPs analizados | Se invoca el método | Bolivia NO aparece en la lista |
| GM-03 | Filtro por país | Se pasa filtro `pais => ['AR']` | Se invoca el método | Solo retorna datos de Argentina |

#### REQ-4: Método getRecentActivity

El sistema **DEBE** proveer un método `getRecentActivity(array $filters): RecentActivityDTO` que retorne actividad reciente del sistema.

**Escenarios:**

| ID | Escenario | GIVEN | WHEN | THEN |
|----|-----------|-------|------|------|
| RA-01 | PEPs alta confianza | Existen PEPs con confianza 90, 85, 95 | Se invoca el método | Solo incluye confianza >= 90 |
| RA-02 | Correcciones recientes | Usuarios hicieron feedback recientemente | Se invoca el método | Retorna últimas 10 correcciones con usuario |
| RA-03 | Sin datos | No existen PEPs ni feedbacks | Se invoca el método | Retorna listas vacías |

#### REQ-5: Método getTrendIndicators

El sistema **DEBE** proveer un método `getTrendIndicators(array $filters): TrendIndicatorsDTO` que retorne indicadores de tendencia comparando períodos.

**Escenarios:**

| ID | Escenario | GIVEN | WHEN | THEN |
|----|-----------|-------|------|------|
| TI-01 | Comparación mensual | Mes actual: 100 PEPs, mes anterior: 80 PEPs | Se invoca el método | Retorna delta +25% con flecha up |
| TI-02 | División por cero | Mes actual: 50 PEPs, mes anterior: 0 PEPs | Se invoca el método | Maneja división por cero, retorna N/A o 0% |
| TI-03 | OPIs y feedback | Existen OPIs y feedbacks en ambos meses | Se invoca el método | Retorna deltas para PEPs, OPIs y feedbacks |

#### REQ-6: Caché de resultados

El sistema **DEBE** cachear resultados de todos los métodos por 5 minutos usando una clave derivada de los filtros.

**Escenarios:**

| ID | Escenario | GIVEN | WHEN | THEN |
|----|-----------|-------|------|------|
| CA-01 | Cache hit | Se invocó previamente con mismos filtros | Se invoca nuevamente | No ejecuta SQL, retorna cache |
| CA-02 | Cache miss | Se invoca con filtros diferentes | Se invoca el método | Ejecuta SQL y guarda en cache |
| CA-03 | TTL expirado | Pasaron 5 minutos desde última invocación | Se invoca el método | Ejecuta SQL nuevamente |

#### REQ-7: Clave de caché única

El sistema **DEBE** generar claves de caché únicas por combinación de `(date_range, pais, categoria)`.

**Escenarios:**

| ID | Escenario | GIVEN | WHEN | THEN |
|----|-----------|-------|------|------|
| CK-01 | Diferentes date_range | Filtro A: 'week', Filtro B: 'month' | Se invocan ambos | Generan claves diferentes |
| CK-02 | Diferentes países | Filtro A: 'AR', Filtro B: 'CL' | Se invocan ambos | Generan claves diferentes |
| CK-03 | Mismos parámetros | Dos invocaciones con ['month', 'AR'] | Se invocan | Generan misma clave, segundo usa cache |

#### REQ-8: Raw SQL para agregaciones

El sistema **DEBE** usar consultas SQL raw (no Eloquent models en memoria) para todas las agregaciones.

**Escenarios:**

| ID | Escenario | GIVEN | WHEN | THEN |
|----|-----------|-------|------|------|
| SQ-01 | Agregación volumen | Se requiere volumen por mes | Se ejecuta método | Usa `DB::select()` o `DB::table()->raw()` |
| SQ-02 | JOIN feedback | Se requiere precisión por cargo | Se ejecuta método | JOIN en SQL, no carga modelos |

#### REQ-9: Cálculo de accuracy

El sistema **DEBE** computar accuracy como: `(correct feedbacks / total feedbacks) * 100`.

**Escenarios:**

| ID | Escenario | GIVEN | WHEN | THEN |
|----|-----------|-------|------|------|
| AC-01 | Fórmula correcta | 8 correctos, 2 incorrectos | Se calcula accuracy | Retorna 80% |
| AC-02 | Sin feedbacks | 0 total | Se calcula accuracy | Retorna 0 (no lanza error) |

#### REQ-10: Buckets de confianza

El sistema **DEBE** agrupar precision metrics en buckets: 0-50, 51-80, 81-100.

**Escenarios:**

| ID | Escenario | GIVEN | WHEN | THEN |
|----|-----------|-------|------|------|
| BK-01 | Distribución correcta | Feedbacks con confianza 45, 75, 95 | Se agrupa | Asigna a buckets 0-50, 51-80, 81-100 respectivamente |
| BK-02 | Límites inclusive | Confianza exacta 50, 80, 100 | Se agrupa | 50→0-50, 80→51-80, 100→81-100 |

#### REQ-11: Muestras mínimas para top failing

El sistema **DEBE** requerir mínimo 3 feedback samples para incluir un cargo en top failing positions.

**Escenarios:**

| ID | Escenario | GIVEN | WHEN | THEN |
|----|-----------|-------|------|------|
| TF-01 | Cargo con 2 muestras | Cargo "Alcalde" tiene 2 feedbacks | Se computa top failing | Excluye "Alcalde" de resultados |
| TF-02 | Cargo con 3 muestras | Cargo "Senador" tiene 3 feedbacks, 2 errores | Se computa top failing | Incluye "Senador" con 66.7% error rate |
| TF-03 | Ordenamiento | Múltiples cargos cumplen mínimo | Se computa top failing | Ordena por error_rate DESC |

#### REQ-12: Datos mensuales para trend

El sistema **DEBE** retornar datos mensuales para los últimos 12 meses en volume trend.

**Escenarios:**

| ID | Escenario | GIVEN | WHEN | THEN |
|----|-----------|-------|------|------|
| VT-01 | Rango 12 meses | Fecha actual: abril 2026 | Se solicita trend | Retorna datos desde abril 2025 |
| VT-02 | Meses sin datos | Julio 2025 no tiene PEPs | Se genera trend | Incluye mes con count 0 |
| VT-03 | Formato array | Datos existen | Se retorna | Array con 12 elementos, orden cronológico |

#### REQ-13: Columnas geographic metrics

El sistema **DEBE** incluir en geographic metrics: pais, peps_count, opis_count, avg_confianza, error_rate.

**Escenarios:**

| ID | Escenario | GIVEN | WHEN | THEN |
|----|-----------|-------|------|------|
| GM-04 | Columnas presentes | Existen datos de Argentina | Se retorna métrica | Objeto con los 5 campos requeridos |
| GM-05 | Cálculo avg_confianza | PEPs de Argentina con confianza 90, 80 | Se computa | avg_confianza = 85 |
| GM-06 | Cálculo error_rate | 10 feedbacks de Argentina, 2 incorrectos | Se computa | error_rate = 20% |

#### REQ-14: Filtro de confianza >= 90

El sistema **DEBE** limitar recent high-confidence PEPs a confianza >= 90.

**Escenarios:**

| ID | Escenario | GIVEN | WHEN | THEN |
|----|-----------|-------|------|------|
| HC-01 | PEP con 89 confianza | PEP "Juan Pérez" tiene confianza 89 | Se lista recientes | Excluye a "Juan Pérez" |
| HC-02 | PEP con 90 confianza | PEP "María García" tiene confianza 90 | Se lista recientes | Incluye a "María García" |
| HC-03 | Orden cronológico | PEPs de distintas fechas | Se lista recientes | Ordenados por fecha DESC |

#### REQ-15: Comparación de tendencias

El sistema **DEBE** comparar mes actual vs mes anterior mostrando delta porcentual.

**Escenarios:**

| ID | Escenario | GIVEN | WHEN | THEN |
|----|-----------|-------|------|------|
| TR-01 | Crecimiento positivo | Actual: 120, Anterior: 100 | Se computa delta | Retorna +20% con indicador up |
| TR-02 | Decrecimiento | Actual: 80, Anterior: 100 | Se computa delta | Retorna -20% con indicador down |
| TR-03 | Sin cambio | Actual: 100, Anterior: 100 | Se computa delta | Retorna 0% con indicador neutral |

#### REQ-16: Parámetro pais flexible

El sistema **PUEDE** aceptar `pais` como null (sin filtro), string único, o array de strings.

**Escenarios:**

| ID | Escenario | GIVEN | WHEN | THEN |
|----|-----------|-------|------|------|
| PF-01 | Null = todos | Filtro `pais => null` | Se aplica filtro | No filtra por país, incluye todos |
| PF-02 | String único | Filtro `pais => 'AR'` | Se aplica filtro | Solo incluye Argentina |
| PF-03 | Array de países | Filtro `pais => ['AR', 'CL']` | Se aplica filtro | Incluye Argentina y Chile |

#### REQ-17: Presets de date_range

El sistema **DEBE** soportar presets de date_range: 'today', 'week', 'month', 'quarter', 'year'.

**Escenarios:**

| ID | Escenario | GIVEN | WHEN | THEN |
|----|-----------|-------|------|------|
| DR-01 | Today | Preset 'today' | Se aplica | Filtra fecha = CURRENT_DATE |
| DR-02 | Week | Preset 'week' | Se aplica | Filtra últimos 7 días |
| DR-03 | Month | Preset 'month' | Se aplica | Filtra mes actual |
| DR-04 | Quarter | Preset 'quarter' | Se aplica | Filtra trimestre actual |
| DR-05 | Year | Preset 'year' | Se aplica | Filtra año actual |

#### REQ-18: Manejo de tabla vacía

El sistema **DEBE** manejar gracefulmente tablas de feedback vacías retornando counts en cero sin errores.

**Escenarios:**

| ID | Escenario | GIVEN | WHEN | THEN |
|----|-----------|-------|------|------|
| EV-01 | Feedback vacío | `clasificaciones_feedback` vacía | Se computa accuracy | Retorna 0, no lanza DivisionByZeroError |
| EV-02 | Resultados vacío | `resultados_scraping` vacía | Se computa volumen | Retorna arrays vacíos |
| EV-03 | Estado explícito | Sin datos | Se renderiza widget | Muestra "Sin datos suficientes" |

---

## 2. dashboard-page (MODIFIED)

### Purpose

El componente Dashboard Livewire existente adquiere una sección colapsable de estadísticas con 11 widgets organizados en grid responsive.

### Requirements

#### REQ-1: Toggle visibility por permiso

El sistema **DEBE** mostrar el botón toggle "Ver estadísticas" solo para usuarios con permiso `ver dashboard estadisticas`.

**Escenarios:**

| ID | Escenario | GIVEN | WHEN | THEN |
|----|-----------|-------|------|------|
| TV-01 | Admin ve toggle | Usuario con rol admin | Carga dashboard | Botón toggle es visible |
| TV-02 | Supervisor ve toggle | Usuario con rol supervisor | Carga dashboard | Botón toggle es visible |
| TV-03 | Operador no ve toggle | Usuario con rol operador | Carga dashboard | Botón toggle NO es visible |
| TV-04 | Server-side check | Usuario intenta acceder directo | Se verifica permiso | Server-side check bloquea sin permiso |

#### REQ-2: Toggle sin page reload

El sistema **DEBE** mostrar/ocultar la sección de estadísticas al hacer click sin recargar la página.

**Escenarios:**

| ID | Escenario | GIVEN | WHEN | THEN |
|----|-----------|-------|------|------|
| TG-01 | Expandir sección | Sección colapsada | Click en toggle | Sección se expande con animación |
| TG-02 | Colapsar sección | Sección expandida | Click en toggle | Sección se colapsa |
| TG-03 | Preservar estado | Sección expandida | Livewire re-render | Estado se mantiene (Alpine.js) |

#### REQ-03: Grid de 11 widgets

El sistema **DEBE** mostrar 11 widgets organizados en grid responsive cuando la sección está expandida.

**Widgets requeridos:**
1. KPI Card: Total PEPs
2. KPI Card: Total OPIs
3. KPI Card: Accuracy %
4. KPI Card: Unread ratio
5. System Precision Widget (accuracy por bucket)
6. Volume Trend Chart (Chart.js)
7. Top Failing Positions Table
8. Geographic Distribution Table
9. Recent High-Confidence PEPs List
10. Latest Corrections List
11. Trend Indicators Widget

**Escenarios:**

| ID | Escenario | GIVEN | WHEN | THEN |
|----|-----------|-------|------|------|
| WG-01 | Todos visibles | Sección expandida | Carga dashboard | Los 11 widgets renderizan |
| WG-02 | Grid responsive | Desktop viewport | Sección expandida | Grid de múltiples columnas |
| WG-03 | Mobile reflow | Mobile viewport (<768px) | Sección expandida | Widgets en columna única |

#### REQ-04: Filter bar

El sistema **DEBE** proveer barra de filtros con: selector de date range, multi-select de país, dropdown de categoría.

**Escenarios:**

| ID | Escenario | GIVEN | WHEN | THEN |
|----|-----------|-------|------|------|
| FB-01 | Cambio de filtro | Usuario selecciona 'week' | Aplica filtro | Todos los widgets actualizan |
| FB-02 | Multi-select país | Usuario selecciona AR y CL | Aplica filtro | Solo datos de esos países |
| FB-03 | Livewire update | Cambio cualquier filtro | Se dispara evento | Re-fetch métricas vía Livewire |

#### REQ-05: KPI cards con valores reales

El sistema **DEBE** mostrar valores numéricos calculados (no placeholders) en las 4 KPI cards.

**Escenarios:**

| ID | Escenario | GIVEN | WHEN | THEN |
|----|-----------|-------|------|------|
| KP-01 | Total PEPs | Existen 150 PEPs | Carga widget | Muestra "150" |
| KP-02 | Accuracy | Accuracy es 87.5% | Carga widget | Muestra "87.5%" |
| KP-03 | Sin datos | No existen datos | Carga widget | Muestra "0" o "N/A" (no placeholder) |

#### REQ-06: Chart.js via CDN

El sistema **DEBE** renderizar Volume Trend Chart usando Chart.js cargado desde CDN.

**Escenarios:**

| ID | Escenario | GIVEN | WHEN | THEN |
|----|-----------|-------|------|------|
| CH-01 | Carga CDN | Chart.js 4.4.1 en jsdelivr | Sección expandida | Script carga correctamente |
| CH-02 | Renderizado | Datos de volumen disponibles | Chart inicializa | Línea chart renderiza con 12 meses |
| CH-03 | Datos inline | Blade incluye `@json($volumeData)` | Alpine lee datos | Chart usa datos embebidos (no fetch) |

#### REQ-07: wire:ignore en containers

El sistema **DEBE** usar `wire:ignore` en contenedores de Chart.js para prevenir que Livewire destruya el canvas.

**Escenarios:**

| ID | Escenario | GIVEN | WHEN | THEN |
|----|-----------|-------|------|------|
| WI-01 | Re-render Livewire | Chart renderizado | Livewire polling (10s) | Canvas persiste, no se recrea |
| WI-02 | Re-expandir | Sección colapsada y re-expandida | Segunda expansión | Chart se inicializa correctamente |

#### REQ-08: Tabla Top Failing

El sistema **DEBE** mostrar tabla Top Failing Positions con columnas: cargo, total samples, errors, error rate %.

**Escenarios:**

| ID | Escenario | GIVEN | WHEN | THEN |
|----|-----------|-------|------|------|
| TF-04 | Columnas correctas | Datos de cargos disponibles | Renderiza tabla | Encabezados: Cargo, Muestras, Errores, % Error |
| TF-05 | Mínimo samples | Cargo con solo 2 muestras | Filtra datos | No aparece en tabla |
| TF-06 | Scroll horizontal | Mobile viewport | Tabla ancha | Scroll horizontal habilitado |

#### REQ-09: Tabla Geographic Distribution

El sistema **DEBE** mostrar tabla Geographic Distribution con columnas: país, PEPs, OPIs, avg confianza, error rate.

**Escenarios:**

| ID | Escenario | GIVEN | WHEN | THEN |
|----|-----------|-------|------|------|
| GD-01 | Columnas correctas | Datos por país disponibles | Renderiza tabla | 5 columnas con datos calculados |
| GD-02 | Ordenamiento | Múltiples países | Renderiza tabla | Ordenado por PEPs DESC |
| GD-03 | Sin resultados | País sin PEPs | Computa métricas | Excluido de la lista |

#### REQ-10: Lista Recent PEPs

El sistema **DEBE** mostrar lista Recent High-Confidence PEPs con: título, nombre, cargo, país, confianza, fecha.

**Escenarios:**

| ID | Escenario | GIVEN | WHEN | THEN |
|----|-----------|-------|------|------|
| RP-01 | Campos visibles | PEPs con confianza >= 90 | Renderiza lista | 6 campos por elemento |
| RP-02 | Límite 10 | Más de 10 PEPs disponibles | Renderiza lista | Solo muestra últimos 10 |
| RP-03 | Sin datos | No hay PEPs con confianza >= 90 | Renderiza lista | Muestra "Sin datos suficientes" |

#### REQ-11: Lista Latest Corrections

El sistema **DEBE** mostrar lista Latest Corrections con: usuario, tipo, cargo corregido, fecha.

**Escenarios:**

| ID | Escenario | GIVEN | WHEN | THEN |
|----|-----------|-------|------|------|
| LC-01 | Campos visibles | Feedbacks existen | Renderiza lista | 4 campos por corrección |
| LC-02 | Usuario visible | Feedback con usuario_id | Renderiza lista | Muestra nombre del usuario |
| LC-03 | Límite 10 | Más de 10 correcciones | Renderiza lista | Solo últimas 10 |

#### REQ-12: Trend Indicators

El sistema **DEBE** mostrar Trend Indicators con flechas up/down y % delta.

**Escenarios:**

| ID | Escenario | GIVEN | WHEN | THEN |
|----|-----------|-------|------|------|
| TR-04 | Flecha up | Delta positivo (+15%) | Renderiza | Icono ↑ verde con "+15%" |
| TR-05 | Flecha down | Delta negativo (-8%) | Renderiza | Icono ↓ rojo con "-8%" |
| TR-06 | Color + icono | Delta mostrado | Accesibilidad | No solo color, también icono y texto |

#### REQ-13: Empty state

El sistema **DEBE** mostrar "Sin datos suficientes" cuando un widget no tiene datos.

**Escenarios:**

| ID | Escenario | GIVEN | WHEN | THEN |
|----|-----------|-------|------|------|
| ES-01 | Widget sin datos | Tabla de feedback vacía | Renderiza precision widget | Muestra "Sin datos suficientes" |
| ES-02 | Widget parcial | Algunos datos disponibles | Renderiza | Muestra datos disponibles, vacíos donde no hay |
| ES-03 | No blank states | Nunca hay datos | Renderiza dashboard | Nunca muestra espacio vacío sin mensaje |

#### REQ-14: Gate en blade

El sistema **DEBE** usar `@can('ver dashboard estadisticas')` en blade para gatear la sección.

**Escenarios:**

| ID | Escenario | GIVEN | WHEN | THEN |
|----|-----------|-------|------|------|
| GA-01 | Doble protección | Usuario con permiso | Renderiza | Pasa gate de Livewire Y de Blade |
| GA-02 | HTML no enviado | Usuario sin permiso | Renderiza | Blade no incluye HTML de estadísticas |

#### REQ-15: Lazy loading

El sistema **NO DEBE** cargar métricas si la sección está colapsada.

**Escenarios:**

| ID | Escenario | GIVEN | WHEN | THEN |
|----|-----------|-------|------|------|
| LL-01 | Colapsado inicial | Dashboard carga | First render | No invoca DashboardMetricsService |
| LL-02 | Expansión trigger | Usuario expande sección | Click toggle | Recién ahí carga métricas |
| LL-03 | Cache aprovechado | Métricas ya en cache | Expansión | Usa cache, no recalcula |

#### REQ-16: Loading state

El sistema **DEBE** mostrar estado de carga visible mientras se computan métricas.

**Escenarios:**

| ID | Escenario | GIVEN | WHEN | THEN |
|----|-----------|-------|------|------|
| LS-01 | Spinner visible | Usuario expande sección | Métricas loading | Muestra spinner o skeleton |
| LS-02 | Reemplazo datos | Datos listos | Loading completa | Reemplaza spinner con widgets |
| LS-03 | Preservar layout | Durante loading | Skeleton visible | Layout no colapsa ni salta |

#### REQ-17: Timestamp de actualización

El sistema **DEBE** mostrar timestamp de última actualización visible en la sección.

**Escenarios:**

| ID | Escenario | GIVEN | WHEN | THEN |
|----|-----------|-------|------|------|
| TS-01 | Timestamp visible | Métricas cargadas | Renderiza sección | Muestra "Actualizado: H:i:s" |
| TS-02 | Cache hit | Datos de cache | Renderiza | Timestamp refleja hora original |
| TS-03 | Auto-refresh | Pasa tiempo | Livewire poll | Timestamp actualiza en próximo refresh |

---

## Non-Functional Requirements

### Performance

| ID | Requerimiento | Prioridad |
|----|---------------|-----------|
| NFP-01 | Queries con cache hit **DEBEN** completarse en <500ms | MUST |
| NFP-02 | Queries con cache miss **DEBEN** completarse en <2s | MUST |
| NFP-03 | Cache **DEBE** invalidarse automáticamente vía TTL | MUST |
| NFP-04 | Queries **DEBEN** usar índices apropiados | SHOULD |

### Security

| ID | Requerimiento | Prioridad |
|----|---------------|-----------|
| NFS-01 | Check de permiso **DEBE** ser server-side en componente Livewire | MUST |
| NFS-02 | Raw SQL **DEBE** usar parameter binding | MUST |
| NFS-03 | No **DEBE** exponer datos de otros usuarios | MUST |

### Accessibility

| ID | Requerimiento | Prioridad |
|----|---------------|-----------|
| NFA-01 | Charts **DEBEN** tener aria-label para screen readers | MUST |
| NFA-02 | Toggle button **DEBE** ser keyboard-accessible | MUST |
| NFA-03 | Indicadores de color **DEBEN** incluir iconos/texto | MUST |
| NFA-04 | Tablas **DEBEN** tener headers scope definidos | SHOULD |

### Responsive Design

| ID | Requerimiento | Prioridad |
|----|---------------|-----------|
| NFR-01 | Widgets **DEBEN** reflow a columna única en mobile | MUST |
| NFR-02 | Tablas **DEBEN** ser scrollable horizontalmente | MUST |
| NFR-03 | Charts **DEBEN** redimensionar con container | MUST |
| NFR-04 | Breakpoint mobile: <768px | SHOULD |

### Data Freshness

| ID | Requerimiento | Prioridad |
|----|---------------|-----------|
| NFD-01 | Cache de 5 min **ES ACEPTABLE** para analytics | MUST |
| NFD-02 | Timestamp de last-updated **DEBE** ser visible | SHOULD |
| NFD-03 | Manual refresh **PUEDE** ser disponible | MAY |

---

## Glossary

| Término | Definición |
|---------|------------|
| **PEP** | Persona Expuesta Políticamente (Políticamente Expuesta) |
| **OPI** | Operador de Información (Información de Particular Interés) |
| **Feedback** | Clasificación manual correcto/incorrecto de una detección Gemini |
| **Accuracy** | Porcentaje de feedbacks marcados como 'correcto' |
| **Confidence** | Valor 0-100 de confianza de la detección Gemini |
| **Bucket** | Rango de agrupación (ej: 0-50, 51-80, 81-100) |
| **Raw SQL** | Consultas SQL directas sin usar Eloquent ORM |
| **CDN** | Content Delivery Network para assets externos |

---

## REQ Count Summary

| Capability | NEW | MODIFIED | REMOVED | Total REQ | Total Scenarios |
|------------|-----|----------|---------|-----------|-----------------|
| dashboard-metrics | 18 | 0 | 0 | 18 | 54 |
| dashboard-page | 0 | 17 | 0 | 17 | 51 |
| **Total** | **18** | **17** | **0** | **35** | **105** |

## Non-Functional REQ Count

| Category | Count |
|----------|-------|
| Performance | 4 |
| Security | 3 |
| Accessibility | 4 |
| Responsive Design | 4 |
| Data Freshness | 3 |
| **Total NFR** | **18** |

**Grand Total: 53 Requirements | 105+ Scenarios**
