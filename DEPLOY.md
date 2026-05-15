# SIMO — Guía de Deploy

Instrucciones para deployar SIMO en un VPS Ubuntu/Debian con Nginx + PHP-FPM + PostgreSQL 17.

---

## Requisitos mínimos

| Componente | Versión mínima | Notas |
|---|---|---|
| PHP | 8.4+ (prod: 8.5) | `composer.lock` requiere ≥8.4 por spatie/laravel-permission 7.x, symfony v8.x y carbon 3.x. VPS productivo corre 8.5.x. |
| PostgreSQL | 17+ | Probado en 17, debería funcionar 14+ |
| Python | 3.9+ | Para el scraper `pep_monitor.py` (usa `list[dict]` syntax) |
| Composer | 2.x | Para dependencias PHP |
| Node.js | 20+ | Solo para `npm run build` (Vite + Tailwind) |

### Extensiones PHP requeridas

```bash
# Reemplazá 8.5 por la versión exacta que corras (mínimo 8.4)
sudo apt install -y php8.5-cli php8.5-fpm php8.5-pgsql php8.5-mbstring \
    php8.5-xml php8.5-curl php8.5-zip php8.5-bcmath php8.5-intl
```

> ⚠️ `intl` es necesario para `php artisan db:show` y para los formatters de Laravel. Sin él, varias features dan `RuntimeException: The "intl" PHP extension is required`.

> 📝 **Histórico**: hasta 2026-05 la doc decía "PHP 8.2+", pero `composer.lock` ya requería ≥8.4 desde el upgrade de Carbon 3 y Symfony 8 (2026-03-29). El primer run de CI (PR #21) expuso el mismatch; este fix alinea la doc con la realidad. Si necesitás bajar a 8.4 en un nuevo VPS, regenerá `composer.lock` con `composer update` en 8.4 — algunos paquetes pueden bajar de versión.

---

## Workflow de actualización en VPS

```bash
sudo -u www-data git -C /var/www/simo pull origin main
php artisan migrate
supervisorctl restart simo-pep-monitor
supervisorctl restart simo-gemini-worker
supervisorctl restart simo-dedupe-worker
```

---

## CI / Branch Protection

Two CI jobs run on every PR and push to `main`:
- `test-sqlite` — fast SQLite in-memory tests (default driver)
- `test-pgsql` — full suite against PostgreSQL 17 (matches production VPS)

Both MUST pass before merge. To enforce this:

1. Repo → Settings → Branches → Branch protection rules → `main` → Edit
2. Under "Require status checks to pass before merging", click "Edit"
3. In the search box, add BOTH:
   - `test-sqlite`
   - `test-pgsql`
4. Save changes

If only one is configured as required, the missing one becomes optional and bugs that only surface on the unconfigured driver may slip through.

---

## Variables de entorno

Copiar `.env.example` a `.env` y ajustar cada valor:

```bash
cp .env.example .env
php artisan key:generate
```

### Variables de Gemini Multimodal (nuevas en v2.0)

| Variable | Default | Descripción |
|---|---|---|
| `GEMINI_VISION_MODEL` | `gemini-2.5-flash` | Modelo Gemini para análisis multimodal |
| `GEMINI_MULTIMODAL_ENABLED` | `true` | Activa/desactiva análisis de imágenes (kill switch). Setear a `false` para desactivar globalmente sin tocar código |
| `GEMINI_MULTIMODAL_MAX_PAYLOAD_BYTES` | `20971520` (20 MB) | Límite total del payload multimodal |
| `GEMINI_MULTIMODAL_MAX_IMAGE_BYTES` | `5242880` (5 MB) | Límite por imagen individual |

> **Nota**: Con `GEMINI_MULTIMODAL_ENABLED=false` el job usa sólo texto. El scraper Python SIEMPRE descarga y guarda imágenes, permitiendo activación retroactiva sin re-scrapear.

---

## Migraciones

Ejecutar en orden cronológico (el orden correcto ya está garantizado por los timestamps de archivo):

```bash
php artisan migrate
```

Las migraciones relevantes para Gemini Multimodal:
1. `2026_05_05_000001_add_imagenes_cambio_json_to_cambios_table.php`
2. `2026_05_05_000002_create_snapshot_imagenes_table.php`

---

## Directorio de imágenes

### Crear y dar permisos en el VPS

```bash
mkdir -p /var/www/simo/storage/app/img_cambios
chown www-data:www-data /var/www/simo/storage/app/img_cambios
chmod 775 /var/www/simo/storage/app/img_cambios
```

El directorio `storage/app/img_cambios/` ya viene con un `.gitkeep` en el repo para que exista en deploys frescos con `git pull`. Los permisos deben darse manualmente en el VPS.

### Variable de entorno del scraper

El scraper Python necesita saber dónde está el storage de Laravel:

```bash
# En el .env del VPS o en el script de supervisor
LARAVEL_STORAGE_PATH=/var/www/simo/storage/app
```

---

## Scheduler (limpieza de imágenes)

El comando `cleanup:imagenes-cambios` se ejecuta diariamente vía el scheduler de Laravel:

```
0 3 * * *  php artisan cleanup:imagenes-cambios --days=90
```

Verificar que el scheduler esté corriendo en el VPS:

```bash
# Opción A: cron (recomendado en producción)
crontab -e
# Agregar:
* * * * * cd /var/www/simo && php artisan schedule:run >> /dev/null 2>&1

# Opción B: schedule:work (solo para desarrollo/staging)
php artisan schedule:work
```

---

## Dependencias Python (solo para tests locales)

Las dependencias de desarrollo Python (pytest) están en `requirements-dev.txt`:

```bash
pip install -r requirements-dev.txt
```

Solo necesario para correr tests Python localmente. En producción el scraper usa únicamente `requirements.txt`.

---

## Runner orquestador del scraper

`scripts/website_monitor_pro/runner.py` es el **orquestador** que reemplaza al antiguo daemon `simo-scraper`. Lee `config_scripts WHERE script='scraper'` en cada tick (cada 30s) y decide si lanzar el scraper según:

- `habilitado` — toggle desde la UI de Configuración de Scripts
- `intervalo_minutos` — cadencia entre ejecuciones
- `hora_inicio` / `hora_fin` — ventana horaria
- `dias_semana` — CSV de días ISO (lunes=1, domingo=7)
- `timeout_minutos` — tiempo máximo antes de SIGTERM→SIGKILL

> **Resultado**: los sliders de "Configuración de Scripts" en la UI **SÍ se aplican** al scraper desde que `runner.py` está activo.

### Variables de entorno opcionales

| Variable | Default | Descripción |
|---|---|---|
| `SCRAPER_DIR` | `<repo>/scripts/scraper_v2.2` | Directorio del scraper v2.2 |
| `SCRAPER_PYTHON` | `$SCRAPER_DIR/venv/bin/python` | Ejecutable Python del venv del scraper |
| `RUNNER_LOOP_INTERVAL` | `30` | Segundos entre ticks del loop principal |

> **Nota**: `SCRAPE_INTERVAL_HOURS` en el `.env` del scraper queda ignorado — la cadencia la controla `intervalo_minutos` en `config_scripts`.

### Configuración en Supervisor (VPS)

Eliminar el bloque `[program:simo-scraper]` si existe y agregar este bloque a `/etc/supervisor/conf.d/simo.conf`:

```ini
[program:simo-runner]
command=/var/www/simo/scripts/website_monitor_pro/venv/bin/python /var/www/simo/scripts/website_monitor_pro/runner.py
directory=/var/www/simo/scripts/website_monitor_pro
user=www-data
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
redirect_stderr=true
stdout_logfile=/var/log/simo/runner.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=5
environment=LARAVEL_STORAGE_PATH="/var/www/simo/storage/app"
```

### Pasos de switchover

```bash
# 1. Detener el daemon anterior (si existe)
sudo supervisorctl stop simo-scraper

# 2. Editar /etc/supervisor/conf.d/simo.conf:
#    - Comentar o eliminar el bloque [program:simo-scraper]
#    - Agregar el bloque [program:simo-runner] de arriba

# 3. Recargar supervisor
sudo supervisorctl reread && sudo supervisorctl update

# 4. Verificar que el runner está RUNNING
sudo supervisorctl status simo-runner

# 5. Seguir los logs del primer ciclo (esperar ~30s)
sudo tail -f /var/log/simo/runner.log

# 6. Validar en BD que el runner registró una fila wrapper
sudo -u postgres psql simo -c \
  "SELECT id, script, inicio, fin, estado, duracion_segundos FROM log_scripts WHERE script='scraper' ORDER BY id DESC LIMIT 5;"
```

### Rollback en menos de 30 segundos

```bash
# 1. Detener runner
sudo supervisorctl stop simo-runner

# 2. Restaurar bloque simo-scraper en /etc/supervisor/conf.d/simo.conf
#    (o git checkout el archivo del VPS si usás conf en repo)

# 3. Opcional: revertir runner.py al estado anterior
git checkout HEAD~1 -- scripts/website_monitor_pro/runner.py

# 4. Reactivar el daemon anterior
sudo supervisorctl reread && sudo supervisorctl update
sudo supervisorctl start simo-scraper
```

Total estimado: ~20-30 segundos.

---

## Supervisor

Ejemplo de configuración de Supervisor para los workers:

```ini
[program:simo-gemini-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/simo/artisan queue:work --queue=gemini --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/simo/storage/logs/gemini-worker.log

[program:simo-dedupe-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/simo/artisan queue:work --queue=dedupe --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/simo/storage/logs/dedupe-worker.log

[program:simo-pep-monitor]
process_name=%(program_name)s_%(process_num)02d
command=python3 /var/www/simo/scripts/website_monitor_pro/pep_monitor.py
autostart=true
autorestart=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/simo/storage/logs/pep-monitor.log
```

### Activar simo-dedupe-worker (primer deploy)

Al agregar `[program:simo-dedupe-worker]` por primera vez, ejecutar:

```bash
# 1. Copiar el bloque de configuración a supervisor
sudo cp /etc/supervisor/conf.d/simo.conf /etc/supervisor/conf.d/simo.conf.bak

# 2. Editar el archivo y agregar el bloque [program:simo-dedupe-worker] (ver arriba)
sudo nano /etc/supervisor/conf.d/simo.conf

# 3. Recargar la configuración de supervisor
sudo supervisorctl reread && sudo supervisorctl update

# 4. Iniciar el worker
sudo supervisorctl start simo-dedupe-worker

# 5. Verificar que está corriendo
sudo supervisorctl status simo-dedupe-worker
# Esperado: simo-dedupe-worker RUNNING pid XXXXX, uptime 0:00:XX

# 6. Verificar el log
tail -20 /var/www/simo/storage/logs/dedupe-worker.log
```

> **Kill switch**: Para deshabilitar temporalmente el processing de dedupe sin detener el worker,
> agregar `DEDUPE_ENABLED=false` al `.env` y correr `php artisan config:cache`.
> El comando `simo:dedupar-pendientes` seguirá ejecutándose en schedule pero no despachará jobs.

---

## Troubleshooting

### `php artisan migrate` falla con `Duplicate table: 7 ERROR: relación X ya existe`

La tabla existe en BD pero el registro de la migración no está en la tabla `migrations`. Insertar manualmente:

```bash
php artisan tinker --execute="DB::table('migrations')->insert(['migration' => '<NOMBRE_MIGRACION>', 'batch' => 1]);"
```

Después correr `php artisan migrate` y debería continuar normalmente.

### Cambios sin sección "Análisis Gemini" en la UI

Significa que el job `AnalizarCambioConPro` falló y marcó `gemini_analyzed=true` con `gemini_analisis_json=null`. Causas comunes:

1. **Cap mensual quemado**: revisar https://ai.studio/spend del proyecto Google AI
2. **API key inválida**: chequear `GEMINI_API_KEY` en `.env`
3. **Worker caído**: `sudo supervisorctl status simo-gemini-worker`

Para re-procesar cambios huérfanos una vez resuelto el origen:

```bash
php artisan tinker --execute="
DB::table('cambios')->where('gemini_analyzed', true)->whereNull('gemini_analisis_json')->update(['gemini_analyzed' => false]);
App\Jobs\AnalizarCambioConPro::dispatch()->onQueue('gemini');
"
```

### `git pull` falla con `Permission denied`

Ownership inconsistente del repo. Fixear:

```bash
sudo chown -R www-data:www-data /var/www/simo/.git
sudo -u www-data git -C /var/www/simo pull origin main
```

### Imágenes no se descargan / `<img>` no detectadas

1. Verificar que `LARAVEL_STORAGE_PATH` está seteada correctamente en el environment del scraper
2. Verificar permisos del directorio: `ls -la /var/www/simo/storage/app/img_cambios/` (debe ser `www-data:www-data 775`)
3. Revisar logs del scraper: `sudo supervisorctl tail -f simo-pep-monitor stdout`

### Workers duplicados de la misma cola

Síntoma: `ps aux | grep queue:work` muestra 2 procesos del mismo queue. Causa: configs duplicados en supervisor. Solución: revisar `/etc/supervisor/conf.d/` y consolidar — debe haber **un solo** programa por queue.

### Logs de Gemini

El channel `gemini` es **daily** — el archivo se llama `gemini-YYYY-MM-DD.log`, NO `gemini.log`:

```bash
tail -100 /var/www/simo/storage/logs/gemini-$(date +%Y-%m-%d).log
```
