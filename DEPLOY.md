# SIMO — Guía de Deploy

Instrucciones para deployar SIMO en un VPS Ubuntu/Debian con Nginx + PHP-FPM + PostgreSQL 17.

---

## Requisitos mínimos

| Componente | Versión mínima | Notas |
|---|---|---|
| PHP | 8.2+ | Requerido por Laravel 12 |
| PostgreSQL | 17+ | Probado en 17, debería funcionar 14+ |
| Python | 3.9+ | Para el scraper `pep_monitor.py` (usa `list[dict]` syntax) |
| Composer | 2.x | Para dependencias PHP |
| Node.js | 20+ | Solo para `npm run build` (Vite + Tailwind) |

### Extensiones PHP requeridas

```bash
sudo apt install -y php8.2-cli php8.2-fpm php8.2-pgsql php8.2-mbstring \
    php8.2-xml php8.2-curl php8.2-zip php8.2-bcmath php8.2-intl
```

> ⚠️ `php8.2-intl` es necesario para `php artisan db:show` y para los formatters de Laravel. Sin él, varias features dan `RuntimeException: The "intl" PHP extension is required`.

---

## Workflow de actualización en VPS

```bash
sudo -u www-data git -C /var/www/simo pull origin main
php artisan migrate
supervisorctl restart simo-pep-monitor
supervisorctl restart simo-gemini-worker
```

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
