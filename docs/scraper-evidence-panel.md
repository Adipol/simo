# Scraper: tabla expandible y comparación de fuentes

## Alcance

S9 sustituye el layout exclusivo de 02: la tabla expandible de
`design/scraper-conservative-groups/01-expandable-table.html` es la vista principal.
«Comparar fuentes» abre explícitamente el panel compacto inspirado en 02, debajo de
la tabla, sin tabs ni modal adicional. Criterios en ayuda desplegable, no motor.
Se conservan las maquetas originales y el shell real. Cards planas, bordes zinc,
acento indigo, espaciado y tipografía proceden de `design/system.md`.
No se incorporan filas ilustrativas, recomendaciones calculadas ni navegación falsa.
Gemini, agrupamiento, modelos, exportador, consultas compartidas y esquema no cambian.

## Uso y límites de la evidencia

- La tabla conserva filtros, búsqueda, contador pendiente y paginación de 25.
  Sus cinco columnas son Keyword / Artículo, Sitio, Score, Fecha y Acciones.
  Cada fila ofrece expansión nativa de todos sus registros (sin controles dentro
  del summary) y las acciones existentes. CSV exporta toda la consulta filtrada.
- No se abre ninguna comparación al cargar. «Comparar fuentes» abre el grupo de
  esa fila; «Cerrar comparación · Volver a la tabla» elimina el panel sin cambiar
  filtros o página. La tabla permanece montada, con claves estables para expansiones.
  No se fuerza scroll ni se instala trampa de foco. Se indica la apertura con
  `aria-expanded`; con panel presente, `aria-controls` referencia su ID.
- La selección es local, sin URL nueva ni escritura. `#[Computed]` resuelve solo
  desde la página filtrada actual. Un ID inexistente, oculto, secundario fuera del
  modo pendiente o de otra página no abre evidencia ni cae al primer resultado.
  Cambiar filtros/página cierra; una acción que excluye la fila cierra la evidencia.
- Los resúmenes de fuentes se cargan por lote para la página, sin cargar personas.
  Solo la comparación explícita carga el análisis del grupo seleccionado. En modo
  pendiente un secundario conserva su primario y hermanos registrados. Todas las
  fuentes siguen disponibles en la expansión y, en el panel, en el selector de
  contraparte y detalles por fuente, aunque no cumplan el filtro de la tabla.
  Son contexto del grupo, no filas adicionales. Acciones dirigidas a la fila elegida.
- Hasta 1100px se apilan los títulos enfrentados. Tabla con scroll horizontal
  etiquetado, encabezados semánticos y foco visible. El contenido ocupa el 100% del
  ancho disponible, conservando los márgenes del layout.
  La ayuda «Criterios propuestos · no ejecutados» es un details nativo cerrado.
- Se conservan lectura, relevancia, descarte/restauración, archivo/desarchivo y
  modal de personas; archivo/desarchivo mantiene su autorización. Ninguna acción
  se ejecuta por seleccionar. Los enlaces solo activan URLs HTTP(S) válidas,
  escapan contenido y usan `noopener noreferrer` al abrir otra pestaña.
- Primario no significa autor ni fuente original. Una relación almacenada no
  valida identidad, institución ni mismo evento. La fecha de hallazgo no es fecha
  de evento; score/confianza no prueban coincidencia entre artículos. El análisis
  está atribuido a cada artículo, sin proponer cruces entre primarios. La comparación
  de títulos indica igualdad literal, diferencia o dato ausente respecto del primario;
  no interpreta eventos ni constituye una nueva regla de agrupamiento.
- Título, sitio, contexto o personas ausentes tienen estados explícitos. Un registro
  sin secundarios dice «Sin fuentes vinculadas», no que sea cobertura exclusiva.
  El badge histórico `+N medios` conserva el conteo de registros secundarios,
  no acredita que sean N sitios distintos.

## Compatibilidad de búsqueda PostgreSQL / SQLite

La corrección posterior a S9 usa `whereLike` / `orWhereLike` de Laravel 12
para keyword, título, URL y contexto, en el mismo grupo OR con parámetros
vinculados y patrón de subcadena (`%texto%`). PostgreSQL conserva `ILIKE`;
SQLite usa su `LIKE` compatible. Por ejemplo, `mixedneedle` encuentra
`MiXeDNeEdLe` en cualquiera de esos campos sin eludir los demás filtros.
No se promete idéntico plegado Unicode entre motores. Listado, paginación y CSV
siguen compartiendo la misma consulta; no se omiten tests en SQLite.

El harness separado `.scratch/scraper-sqlite-compatibility/` conserva snapshots,
hashes/autoload y streams. Solo en sus snapshots se retiran las entradas XML
`DB_DATABASE` y `DB_CONNECTION`: cada runner fija explícitamente su conexión.
SQLite usa exclusivamente `:memory:` sin socket ni credenciales PostgreSQL,
con PHP 8.5.12 / SQLite 3.45.1 en imagen descartable derivada; PostgreSQL mantiene
la imagen PHP original y los controles históricos de identidad/permisos con una
base vacía nueva por ejecución. Ambos ejecutan sin red, como usuario no root,
con fuentes/vendor de solo lectura y cachés/storage efímeros, sin `.env` ambiente.
No se modifican dependencias de aplicación ni el entorno habitual.

## Verificación aislada

No ejecutar estos tests con la configuración ambiente. El harness exclusivo en
`.scratch/scraper-evidence-panel-t2/` comprueba servidor/imagen/volúmenes/socket,
permisos y configuración antes de cada ejecución. PHP 8.5.6 y PostgreSQL 17.11 usan
imágenes locales sin red; cada suite crea una base vacía nueva. Fuentes/vendor y
socket son de solo lectura; cachés/storage son efímeros. No carga `.env` ni cachés
existentes; DB URLs vacías y ausencia de overrides PG se comprueban antes de PHPUnit.

Comandos desde la raíz, únicamente mientras exista ese harness verificado:

```sh
sh .scratch/scraper-evidence-panel-t2/run changed evidence
sh .scratch/scraper-evidence-panel-t2/run changed resultados
python3 .scratch/scraper-evidence-panel-t2/compare.py
sh .scratch/scraper-evidence-panel-t2/build
sh .scratch/scraper-evidence-panel-t2/lint
```

Resultados históricos T2: 9 tests/45 assertions dedicados; 43/110 en Resultados, sin risky ni
warnings de PHPUnit. RED observado antes de implementar el panel; también RED
específico antes de proteger enlaces inseguros, reiniciar selección y comparar títulos. El test de
badge distingue el selector de la evidencia secundaria legítimamente visible.
Los dos logs de aplicación por manifest Vite ausente al renderizar 403 ya aparecen
en BASE; no se suprimen. El build Vite aislado pasa y escribe solo en scratch.

La comparación usa fixtures idénticos con fecha/score fijos: 14 escenarios de
filtros y CSV, segunda página, modal y seis acciones. Observaciones, exits y streams
coinciden tras normalizar únicamente nombres de bases nuevas, timestamps de logs
y medidas de tiempo/memoria. Streams originales quedan en scratch. BASE conserva
los bytes pre-edición; componente/vista/query/exportador/modelo relevantes fueron
comparados con HEAD. Esto no acredita un checkout histórico completo.

## Histórico T4 — layout sustituido por S9/T5

La captura autorizada `Captura de pantalla_20261029_203940.png` mostraba una lista
ocupando un tercio de todo el ancho y fichas repetitivas que desplazaban la evidencia.
La corrección sigue S1–S4 y S7–S8: máximo 1600px, lista de 18rem (15rem hasta
1100px), separación 1.25rem, panel p-6, borde plano/radio .75rem. Solo se agregan
estilos `.simo-evidence-*`; no se alteran utilidades compartidas ni el shell.

El primario se enfrenta a una contraparte elegida con un select nativo y estado
Alpine local, reiniciado al cambiar de selección mediante `wire:key`. Las demás
fuentes no se pierden: todas figuran en el selector y en detalles nativos con
URL, contexto, keyword, score, fecha y análisis. Sin secundarios se indica
«Sin contraparte registrada». La definición alineada resume datos disponibles;
no inventa persona, institución ni evento compartidos. El panel ámbar explica la
limitación y las acciones originales siguen explícitas al pie, con sus permisos
y confirmación de descarte.

Pruebas T4, en el harness anterior y tras refrescar sus copias editadas:

- RED: `sh .scratch/scraper-evidence-panel-t2/run changed evidence`:
  11 tests/48 assertions, dos fallos esperados: faltaban comparación compacta y
  estado sin contraparte. Los nueve tests existentes pasaron.
- GREEN final: mismo comando, 11 tests/61 assertions, sin fallos/risky/warnings.
- `sh .scratch/scraper-evidence-panel-t2/run changed resultados`:
  45 tests/126 assertions, sin fallos/risky/warnings. Persisten los dos logs
  históricos de manifest ausente en la vista 403 del sandbox.
- `sh .scratch/scraper-evidence-panel-t2/build`: 59 módulos; CSS
  `build-output/assets/app-DQghfBlx.css` y JS `app-WC-ZjLzv.js` en scratch.
- `npm run build`: 59 módulos; **build local real actualizado**, manifest activo
  apunta a `public/build/assets/app-DjzKRFlR.css` y `app-WC-ZjLzv.js`.
  La comprobación del manifest confirma los selectores del workspace 18rem y
  de ocultación de contraparte. Las diferencias de tamaño CSS entre builds no
  implican igualdad byte a byte: Tailwind también inspecciona vistas compiladas.

### Evidencia visual observada

En `.scratch/scraper-evidence-panel-t2/`:

- `t4-artboard-1440.png`: lámina seleccionada, sin modificar.
- `t4-rendered-final-1440.png`: vista Blade actual y layout real, renderizados
  mediante Livewire en fixtures exclusivos de la base aislada. Ambos se capturaron
  con Firefox headless a **1440×1200** y se inspeccionaron como imágenes.
- `t4-rendered.html`, `t4-artboard.html`, `prepare-t4-visual.py` y
  `T4FixtureEvidencePanelTest.php` conservan el material de reproducción.
  El exportador se usó solo en la copia scratch; el test entregado no exporta HTML.
  Antes de guardar se retiraron scripts y campos CSRF. Se incrustó el CSS del
  manifest local quitando su import remoto; Firefox usó perfil/HOME exclusivos,
  proxy bloqueado y preferencias offline, sin tocar perfiles ni sesiones humanas.
- La primera captura `t4-rendered-1440.png` quedó sin estilos por un recorte
  incorrecto del import de fuente (el URL contiene punto y coma); se corrigió el
  generador, conservando la captura fallida y la intermedia corregida.

La comparación muestra proporciones, tarjetas enfrentadas compactas, filas de
hechos y bloque ámbar recuperados. No es igualdad píxel a píxel: se conserva el
shell real, toolbar operativa (puede envolver líneas), selector de contraparte,
datos sin medio registrado y detalles/acciones adicionales. Se omiten tabs de
diseño, seis casos ilustrativos y propuestas semánticas que no existen en el motor.
No es captura de la sesión local autenticada ni validación interactiva de Alpine;
la captura offline prueba el estado inicial y los tests comprueban el contrato
de selección/ocultación y disponibilidad de todas las fuentes. Revisión humana
final, teclado y cambios interactivos de contraparte quedan al padre.

`pre-t4/resources/` conserva las vistas y CSS previos a aquella corrección. `base/`
sigue siendo el BASE anterior a T2, **no** un baseline pre-T4. No hubo cambios de
componente PHP, DB local, flags, datos DEMO 14–17, Gemini o agrupamiento. No se
instaló nada ni se creó ruta/servidor de preview. Scratch se conserva, fuera de entrega.

## T5 — tabla principal + comparación explícita (S9)

Se sustituye el chooser lateral, no se reubica la lista anterior. La tabla recupera
la jerarquía de 01: encabezado + subtítulo, aviso indigo, card blanca redondeada,
encabezados compactos, cinco columnas y fuentes bajo cada título. El panel conserva
la composición de 02: dos títulos originales enfrentados, hechos alineados y aviso
ámbar. No hay tres pestañas, reglas ejecutadas ni sugerencias calculadas.

`pre-t5/` conserva los ocho archivos permitidos relevantes antes de la primera
edición. No se usa Git histórico como dependencia de tests. El partial de acciones
no cambió; se reutiliza en filas y comparación con los mismos permisos y confirmación.
Las pruebas anteriores solo cambian sus supuestos obsoletos: selección automática
pasa a explícita; IDs inválidos cierran en vez de elegir el primero; secundarios
aparecen dentro de la expansión pero nunca como filas principales.

### Verificación observada

Todos los tests usan el runner con identidad/socket/config de PostgreSQL 17.11
probados y una base nueva por ejecución; sin DB ambiente ni nuevos servidores.

| Comando | Resultado T5 |
| --- | --- |
| `sh .scratch/scraper-evidence-panel-t2/run changed evidence` (RED) | 12 tests/52 assertions, 1 error por `cerrarComparacion` inexistente y 3 fallos esperados: apertura automática, falta de tabla y fallback de ID inválido |
| mismo comando (GREEN inicial) | 12/78 PASS |
| mismo comando (RED adicional de reinicio por acción) | 14/112, un fallo esperado: ID seleccionado retenido tras descarte; se corrige solo estado de presentación para impedir reapertura al restaurar |
| mismo comando (final, triangulación) | **14/116 PASS**, sin risky/warnings |
| `sh .scratch/scraper-evidence-panel-t2/run changed resultados` (final) | **48/181 PASS**, sin risky/warnings |
| `python3 .scratch/scraper-evidence-panel-t2/compare-t5.py` | pre-T5 y T5, 1 test/23 assertions cada uno: 14 filtros/CSV, página 2, modal y seis acciones idénticos; exits y streams normalizados iguales |
| `sh .scratch/scraper-evidence-panel-t2/build` | 59 módulos; scratch CSS `app-XKKOeE-O.css` |
| `npm run build` | 59 módulos; manifest real actualizado a **`app-BxqFtl8O.css`**, JS `app-WC-ZjLzv.js` |
| `sh .scratch/scraper-evidence-panel-t2/lint` | seis archivos PHP/Blade sin errores de sintaxis; compilación Blade ejercitada por Livewire |
| `python3 .scratch/scraper-evidence-panel-t2/export-t5-fixture.py` | 1 test/2 assertions PASS; HTML de ambos estados reales exportado en fixtures aislados |
| `python3 .scratch/scraper-evidence-panel-t2/capture-t5.py` | seis capturas Firefox offline a 1440×1200, exit0; manifest/selectores comprobados |

Los dos eventos históricos de manifest ausente en los tests de autorización 403
siguen en stderr (cada evento incluye excepciones anidadas). No se ocultaron.
La triangulación cubre apertura/cierre sin cambios DB/colas, ausencia de análisis al
cargar, grupo de tres fuentes, singleton, ID forjado por método y propiedad,
intersección de filtros/página, reinicio de los nueve filtros y de ID excluido por acción (sin reapertura al restaurar), conservación de
página/búsqueda al cerrar, escape/enlaces seguros y contexto del secundario pendiente.
El diferencial compara comportamientos reales de Livewire y bytes CSV, no salidas
CLI ficticias. Solo normaliza identidad DB nueva, timestamps y medidas de ejecución.

### Capturas y divergencias retenidas

En `.scratch/scraper-evidence-panel-t2/`, inspeccionadas como imágenes:

- `t5-artboard-01-1440.png` y `t5-default-1440.png`: maqueta intacta frente a
  tabla real por defecto, sin evidencia abierta.
- `t5-artboard-02-1440.png` y `t5-comparison-top-1440.png`: maqueta intacta
  frente al documento real después de llamar explícitamente a la selección en
  Livewire. La comparación está debajo de la tabla, no en un layout lateral.
- `t5-comparison-excerpt-1440.png`: **extracto estático de la sección abierta**
  en el shell real, sin tabla precedente, para ver títulos/hechos/aviso ámbar
  completos al mismo viewport. No representa la posición normal del panel.
  El script conserva además el HTML completo de apertura y el HTML del extracto.
- `t5-comparison-1440.png`: navegación estática por fragmento; Firefox deja
  elementos fijos desplazados y no encuadra toda la comparación. Se conserva como
  limitación de captura, no prueba de scroll/foco correcto.

Son renders offline de Blade/Livewire + layout actual y CSS del manifest local;
se retiran scripts/CSRF y el import remoto de fuente. Perfil/HOME de Firefox son
exclusivos de scratch, proxy bloqueado y preferencias offline, sin sesión humana.
**No son pruebas de clicks, teclado, morph de Livewire o cambio Alpine de contraparte.**
No se reparó ni reintentó el helper BiDi agotado. Ningún servidor/ruta/auth bypass.

Divergencias deliberadas: toolbar, shell y acciones reales; dos filas principales
con fixtures de prueba en vez de seis casos ilustrativos; títulos/datos almacenados,
no resúmenes ni identidad/evento inferidos; expansiones cerradas inicialmente;
comparación de ancho completo bajo la tabla; ayuda cerrada en vez de tercer tab o
cards de regla. La toolbar puede envolver otra línea cuando aparece la scrollbar.
Las parejas conservan padding 1rem, separación 1rem, hechos 8rem y explicación
ámbar; tabla/card y página usan los radios/espaciados/tipografía del sistema.
Estas capturas aisladas no acreditan igualdad píxel a píxel ni aceptación humana.
La aceptación posterior y sus límites se registran a continuación.

### Cierre local y aceptación posterior

- Revisión independiente de T5: **48 tests / 181 assertions PASS**, sin bloqueos
  concretos de código o seguridad en el alcance revisado.
- A pedido del usuario se eliminó el límite de 1600px: `.simo-evidence-page` usa
  `width: 100%`. Build local y comprobación del CSS compilado aprobados.
- Capturas de la sesión local confirmaron apertura del panel y cambio de fuente
  con actualización coherente del título y la comparación literal. El usuario
  confirmó el cierre y aprobó la presentación final: «ahora si todo bien».
- Teclado, móvil, foco y morph instrumentado no fueron verificados; el intento
  automatizado BiDi quedó interrumpido antes de ejecutar JavaScript.
- Tras autorización separada, se eliminaron únicamente los cuatro DEMO y se
  restauraron las dos configuraciones locales originales. La prueba final confirmó
  ausencia de esos registros y ambos servicios habilitados por configuración.
  No hubo despliegue ni arranque de workers. Los helpers y recibos permanecen
  exclusivamente en scratch, fuera del código entregado.

Riesgo de revisión: ítems 2/3, alto por cambio de selección y presentación compartida.
El padre debe revisar interacción real, retorno/foco/scroll y expansión persistente,
además de la fidelidad visual. Sin cambios en Gemini, agrupamiento, flags locales,
DEMO 14–17, permisos, consulta compartida, dependencias o artefactos ODD/diseño.
