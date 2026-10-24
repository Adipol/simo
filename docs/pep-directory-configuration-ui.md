# Configurar un directorio PEP (panel inactivo por defecto)

## Recorrido

`/pep/directorio/configuracion` es un panel hermano del historial de eventos, no un reemplazo de Fuentes, Cambios PEP ni Scraper.

1. Seleccione una fuente **ya registrada como configurable** y una captura **ya almacenada**. Se muestran fecha, huella y procedencia guardadas; el panel no crea ni certifica su origen.
2. Complete los selectores CSS del directorio y de cada registro. Sección y cantidad de columnas son opcionales. La cantidad admite 1–32; los índices empiezan en 0 y deben ser menores que esa cantidad.
3. Asocie unidad/sede, cargo y persona mediante selector o índice. La unidad también permite un valor constante o un selector contextual del directorio/sección. Condición es opcional. Selectores y constantes tienen un límite de 512 bytes; no todos los selectores CSS son interpretables por el extractor acotado.
4. Indique un motivo no vacío (hasta 10000 caracteres) y genere la vista previa. Corregir las asociaciones y volver a generar **reutiliza la misma captura seleccionada** y elimina la aprobación anterior. No hay recaptura automática. Las personas extraídas no son editables.
5. Examine validez, calificación, posiciones y evidencia escapada. Los controles modificados muestran **Cambios sin previsualizar**; la vista almacenada no representa esas ediciones. Previsualice antes de confirmar.
6. Registre una confirmación explícita o revóquela, siempre con motivo. **Confirmación registrada. Detección no activada.** La confirmación utiliza la evidencia almacenada y no requiere un compilador disponible.

Una vista no vacía e `incomplete` puede ser candidata; eso no demuestra completitud. `qualification.authority=none` no es aprobación operativa. El servicio rechaza personas nulas/vacías y resultados estructuralmente vacíos. Un fallo de compilación conserva el estado anterior. Ante conflicto, la versión mostrada no cambia y no se repite la mutación: use **Recargar fuente y renovar decisión**, revise la evidencia y escriba un motivo nuevo.

## Límites operativos y seguridad

- `pep_directory.ui_enabled=false` permanece sin cambios. Con el panel apagado se devuelve 404 antes de cualquier consulta PEP, tanto al abrir como al hidratar.
- Lectura: autenticación, UsuarioActivo y permiso persistido `ver cambios pep`. Publicación/confirmación/revocación: usuario activo y `gestionar fuentes`, verificados nuevamente por los servicios existentes. Los operadores pueden inspeccionar, no decidir.
- Fuente, captura, vista y versión son propiedades bloqueadas; la pertenencia se verifica en servidor. Identidad, URL, bytes, artefactos y actor no proceden del formulario.
- Sin captura: **Sin captura almacenada; generación no disponible.** Sin binding de compilador o intérprete configurado se deshabilita la generación, pero no la confirmación almacenada. El provider enlaza `PreviewCompiler` a `ProcessPreviewCompiler` únicamente con `config('pep_directory.preview_interpreter')`; su valor nulo falla cerrado. El renderizado nunca resuelve ni ejecuta el compilador. Una ruta configurada no prueba que Python/dependencias funcionen.
- No registra fuentes, descarga páginas, recibe archivos/JSON arbitrario, activa detección ni modifica observaciones/eventos/revisiones. La confirmación del directorio es distinta de la revisión de eventos.
- La invalidación de caché de permisos en lecturas y mutaciones y las asignaciones de secuencia son efectos no transaccionales acotados; el harness con caché array no prueba comportamiento de caché compartida en producción.

## Verificación acotada

El panel utiliza fuentes configurables y capturas ya almacenadas. No añade transporte de captura, registro de fuentes, flujo manual ni opciones de sufijos. La disponibilidad del intérprete no demuestra un runtime operativo.

`DirectoryConfigurationUiTest` usa el harness PostgreSQL opt-in: autenticación y configuración se crean dentro de una transacción exterior que se revierte. El compilador es falso; los servicios de publicación y decisión son reales. El diferencial de rutas y layout se ejecuta aisladamente contra la referencia anterior al panel de revisión, incluyendo `routes/auth.php`. Su helper configura las dos vistas de paginación Livewire y restaura y comprueba ambos valores en `finally`.

La prueba no acredita PHP→Python, navegador, CSRF de producción, concurrencia real, caché compartida, captura real ni activación operativa. En esta entrega, PostgreSQL: 2 pruebas/146 aserciones, exit 0, con 2 deprecaciones upstream visibles de Livewire/PHP 8.5; diferencial aislado: 1 prueba/56 aserciones, exit 0. Sin opt-in: 2 skips, exit 0; opt-in vacío o inválido: 2 errores de guardia por ejecución, exit 2. No son pruebas positivas. Los cuatro inventarios externos antes/después de las ejecuciones positivas preservaron exactamente seis tablas/38 filas, 28 triggers FK, namespace nativo/propietario/ACL y las cuatro pertenencias a extensiones, sin overlay de autenticación/configuración ni rutinas restantes. No se compararon ni reiniciaron valores de secuencias. Estas comprobaciones no reutilizan resultados históricos.
