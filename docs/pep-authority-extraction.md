# Extracción configurable de autoridades PEP (HTML)

Guía local para operadores y desarrolladores. La configuración `structured_css_v1` sirve para listas de autoridades en tablas o tarjetas HTML con una persona y un cargo por fila; no implica compatibilidad universal ni habilitación en producción.

## Ruta rápida

1. En **Fuentes PEP**, elegí **Tipo: HTML** y **Extractor: CSS estructurado**. Indicá los selectores de **contenedor**, **fila**, **persona** y **cargo**; fijá mínimo y máximo de filas entre 1 y 200 (valores iniciales: 1 y 200). Guardá la fuente **inactiva**. Crear una fuente genérica fuerza el estado inactivo; al editar, mantené desmarcada la activación.
2. Pulsá **Solicitar vista previa**. La solicitud no consulta la página desde PHP: el trabajador Python `PEPMonitor.check_all()` descubre también fuentes inactivas y procesa el pedido de forma asíncrona. **No** es el `runner.py` de noticias. La pantalla actualiza el estado mientras haya pedidos pendientes o en proceso.
3. Cuando figure **Lista**, revisá la lista visible de **cargo — persona** contra el contenido esperado de la fuente. Si está completa y correcta, pulsá **Confirmar vista previa**; el botón confirma únicamente ese resultado visible y actual. Si otra solicitud reemplazó la lista o cambió su orden, el clic anterior se rechaza: revisá el resultado actual antes de confirmarlo. La confirmación **no** activa la fuente.
4. Pulsá **Activar** por separado, luego de la revisión humana. La fuente genérica no se puede activar sin la confirmación de su revisión actual.

## Cómo elegir selectores

Cada selector CSS tiene como máximo 500 caracteres. Debe existir **un solo contenedor**; dentro de él, el selector de filas debe encontrar entre `min_rows` y `max_rows` elementos. Dentro de cada fila, persona y cargo deben coincidir **exactamente una vez** con elementos separados, sin solapamiento. Si una fila seleccionada carece de persona o cargo, se repite un par persona/cargo o se superan los límites, la extracción es incompleta: no se publica una lista parcial. Cada campo visible se limita a 300 caracteres.

**`ready` valida las filas seleccionadas, no toda la nómina del sitio.** Una autoridad omitida por el selector o retirada del HTML puede pasar inadvertida si el conteo sigue dentro de los límites. Revisá el alcance del selector y compará la lista con la página: ni el mínimo de filas ni un conteo igual o mayor garantizan que se conserven las autoridades aceptadas.

| HTML sintético | Contenedor | Fila | Persona | Cargo |
| --- | --- | --- | --- | --- |
| `<section id="equipo"><table><tr class="autoridad"><td class="nombre">Ana Ruiz</td><td class="cargo">Rectora</td></tr></table></section>` | `#equipo` | `tr.autoridad` | `td.nombre` | `td.cargo` |
| `<div class="directorio"><article class="ficha"><h3 class="persona">Luis Paz</h3><p class="rol">Secretario</p></article></div>` | `.directorio` | `article.ficha` | `h3.persona` | `p.rol` |

Son ejemplos de estructura, **no URL ni selectores para sitios reales**. PHP valida la forma y límites de la configuración; Python valida además la sintaxis CSS y ejecuta los selectores. La vista genérica muestra los campos escapados, sin HTML crudo ni credenciales.

### Caso CBES verificado solo en local

Para `https://www.cbes.org.bo/index.php/nomina-de-autoridades/`, una consulta pública y una preview en PostgreSQL descartable devolvieron tres pares completos con esta configuración **provisional**:

| Campo | Valor |
| --- | --- |
| Contenedor | `#nota-prensa` |
| Fila | `div.accordion.p-2` |
| Persona | `h2.accordion-header h4` |
| Cargo | `p.subtitulo` |
| Mínimo / máximo de filas | `3` / `200` |

El mínimo de tres protege esta prueba contra lecturas con menos filas, pero **también deja incompleta una baja legítima de tres a dos**: en tal caso hay que investigar la página antes de ajustar la configuración; cambiarla reinicia la línea base sin emitir una baja retrospectiva. Estos selectores y la nómina son una observación puntual, no garantía de completitud futura. **No se cambió, confirmó ni activó la fuente registrada**; hacelo solo tras una revisión humana y una autorización operativa separada. Este modo requiere una fuente de tipo HTML; no promete soporte general para PDF, OCR ni URLs arbitrarias. El fetch de una fuente HTML puede usar un fallback JS cuando el HTML estático resulta insuficiente: esto no convierte a las fuentes configuradas como tipo JS en elegibles para `structured_css_v1`. El piloto UMSA añadió un adaptador propio y su migración de activación; Divi conserva su comportamiento existente.

## Estados y acciones seguras

| Estado | Qué hacer |
| --- | --- |
| Sin solicitar / `pending` | Solicitá explícitamente si aún no hay pedido; si está pendiente, esperá un ciclo de `check_all`. |
| `running` | Esperá el resultado; un trabajo vencido puede ser retomado en otro ciclo. No hay promesa de latencia. |
| `ready` (Lista) | Compará persona/cargo; confirmá solo si es correcto y actual, después activá aparte. |
| `incomplete` | Revisá contenedor, alcance, campos y conteo; corregí la configuración, guardá inactiva y solicitá otra vista. No confirmes una lista parcial. |
| `error` | Revisá disponibilidad pública de la fuente y configuración; después solicitá de nuevo. La interfaz muestra motivos acotados, no excepciones ni HTML remoto. |
| `stale` (Desactualizada) | La URL o configuración cambió: pedí una vista nueva y repetí la revisión humana. No uses una confirmación anterior. |

Cada ciclo descubre hasta **25** pedidos; cada intento tiene un lease de **5 minutos** y hay hasta **3 intentos** antes de `error` por agotamiento. Una nueva solicitud reinicia el pedido. El progreso depende de que se ejecute el trabajador; los ciclos con solo vistas previas no generan logs de monitoreo falsos. Una vista previa no crea snapshots, cambios, revisiones de remoción ni trabajo Gemini.

## Seguridad, cambios y remociones

La solicitud y confirmación PHP operan sobre registros, sin fetch ni subprocess. Python consulta URLs públicas con controles SSRF y de redirecciones. TLS se verifica por defecto; `SSL_VERIFY_SKIP_HOSTS` permite excepciones configuradas por host, que deben revisarse por separado. En la vista se persisten solo pares persona/cargo acotados, huella de configuración y códigos fijos de error, nunca HTML bruto, credenciales ni detalles de excepción. Evitá pegar secretos en selectores o documentación.

Confirmar requiere estado `ready`, revisión y URL actuales, huella de configuración válida y lista bien formada. El botón envía una identidad del resultado mostrado, ligada a la nómina completa y su orden, a la fuente, a la vista previa y a su configuración/huella; el servicio la compara con el resultado actual bajo bloqueo. Una identidad ausente, inválida o desactualizada no confirma ni activa la fuente. Una nueva solicitud con contenido y contexto idénticos puede conservar esa identidad: no es un identificador único por solicitud. Un cambio de identidad (URL, tipo, extractor o configuración) rota la revisión, invalida la vista previa y la confirmación/activación previa; solicitá y revisá nuevamente. Una lectura incompleta conserva la línea base aceptada y no emite bajas. Una configuración nueva o modificada establece una nueva línea base coherente sin eventos; confirmar la vista previa no aprueba automáticamente los cambios posteriores.

### Cuando desaparece un par aceptado

Con `structured_css_v1` y una línea base de la revisión actual, cualquier par **cargo/persona normalizado** que deje de aparecer exige revisión humana antes de aceptar la nueva nómina o emitir un Cambio primario. Esto incluye remociones, reemplazos y cambios de cargo, aunque el total de filas se mantenga o aumente.

| Observación completa | Resultado |
| --- | --- |
| Falta un par aceptado, con o sin nuevas autoridades | Se conserva la línea base; se guarda la nómina candidata y sus eventos propuestos para revisión. |
| Solo se agregan pares nuevos | Las designaciones se procesan automáticamente. |
| Solo cambia el orden o el formato equivalente tras normalización | No se generan eventos de autoridades. |
| Extracción incompleta | No se promueve la nómina ni se inicia una revisión por esos datos. |

En la pantalla **Revisión de cambios de autoridades**, compará la línea base y la nómina observada con la página y la evidencia técnica. **Confirmar** acepta la nómina candidata y envía los cambios a análisis; **Rechazar** conserva la línea base sin crear un Cambio. La URL y los nombres internos del circuito de remociones no cambian. Los adaptadores legados UMSA/Divi conservan su regla anterior basada en reducción del conteo; esta protección por pares no se aplica a ellos.

El estado de monitoreo `authority_review_pending` se conserva completo en `log_fuente_runs.estado`. La migración `2026_10_16_000001_widen_log_fuente_runs_estado.php` amplía esa columna de 20 a 64 caracteres sin modificar los registros existentes. Su reversión se rechaza si hay estados de más de 20 caracteres, para evitar truncarlos.

## Verificación local y límites

Para desarrollo, consultá [parser y sus pruebas](../scripts/website_monitor_pro/tests/test_structured_authorities.py), [trabajador y sus pruebas](../scripts/website_monitor_pro/tests/test_authorities.py), [servicio PHP](../tests/Feature/Services/Fuente/AuthorityExtractionConfigurationTest.php), [UI](../tests/Feature/Livewire/Pep/FuentesAuthorityPreviewTest.php) y [ciclo integrado](../tests/Integration/AuthorityPreviewLifecycle.php). Las migraciones [000002](../database/migrations/2026_09_20_000002_add_configurable_authority_extraction_to_fuentes_and_snapshots.php) y [000003](../database/migrations/2026_09_20_000003_create_authority_extraction_previews_table.php) agregan configuración/revisión en fuentes y snapshots, y una fila de vista previa por fuente con estado, intentos, lease y resultado. No se requiere un script de harness cacheado ni ejecutar una prueba sobre una URL real para leer esta guía.

El ciclo local de integración usa HTML sintético y prueba una contención PostgreSQL concreta, no todos los interleavings ni acceso a sitios reales. En el `HEAD` W6a `cc16c8d`, la verificación independiente local en Docker con PostgreSQL 17 terminó con **exit 0: 1.391 tests / 4.175 assertions** para la suite PHPUnit completa (1 skipped, 1 risky sin assertions, 2 deprecaciones de metadatos); Pint sobre cuatro archivos y Vite pasaron. El monitor Python pasó **162 pruebas focalizadas y 340 en la suite completa**. Consultá los registros y límites en [la tarea de implementación](../odd/tasks/configurable-authority-extraction.md). Las cuatro fallas de `KpiBandejaConsistencyTest` (1.386 tests / 4.096 assertions) pertenecían a una línea base anterior; **no reaparecieron en esta verificación**. Estas pruebas no sustituyen una revisión en navegador ni una prueba de URL en vivo. **No se desplegó nada**: tampoco está demostrada la topología existente de scheduler/worker. Antes de operar fuera del entorno local, comprobá por separado migraciones, proceso periódico `PEPMonitor.check_all()`, red saliente permitida y pruebas de despliegue según el ambiente autorizado.
