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

## Respaldo PostgreSQL previo al deploy

Este respaldo lógico de `simo` es una medida de rollback para el deploy, no un plan completo de recuperación ante desastres. En PostgreSQL 17, `pg_dump` genera una copia consistente de una sola base de datos mientras lectores y escritores concurrentes continúan operando. El formato personalizado (`--format=custom` / `-Fc`) se restaura con `pg_restore`.

`pg_dump` no incluye objetos globales del clúster, como roles y tablespaces; una estrategia de recuperación integral debe respaldarlos por separado con `pg_dumpall`. Este procedimiento tampoco elimina ni rota respaldos anteriores.

Ejecutar este bloque en el VPS **antes de actualizar el código**:

```bash
(
    set -euo pipefail

    backup_dir=/var/backups/simo
    database=simo

    if sudo test -L "$backup_dir"; then
        printf 'ERROR: %s debe ser un directorio real, no un enlace simbólico.\n' "$backup_dir" >&2
        exit 1
    fi

    if ! sudo test -d "$backup_dir"; then
        printf 'ERROR: no existe el directorio requerido; créelo con un modelo de ownership permitido: %s\n' \
            "$backup_dir" >&2
        exit 1
    fi

    dir_owner="$(sudo stat --format='%U' -- "$backup_dir")"
    dir_mode="$(sudo stat --format='%a' -- "$backup_dir")"

    if [[ ! "$dir_mode" =~ ^[0-7]{3,4}$ ]]; then
        printf 'ERROR: no se pudo interpretar el modo octal de %s: %s.\n' \
            "$backup_dir" "$dir_mode" >&2
        exit 1
    fi

    if (( (8#${dir_mode} & 8#022) != 0 )); then
        printf 'ERROR: %s no debe permitir escritura de grupo/otros; modo actual: %s.\n' \
            "$backup_dir" "$dir_mode" >&2
        exit 1
    fi

    case "$dir_owner" in
        postgres)
            if (( 8#${dir_mode} != 8#700 )); then
                printf 'ERROR: con owner postgres, %s debe tener modo 0700 exacto; modo actual: %s.\n' \
                    "$backup_dir" "$dir_mode" >&2
                exit 1
            fi
            ;;
        root)
            ;;
        *)
            printf 'ERROR: owner no permitido para %s: %s; use postgres con modo 0700 o root con permisos seguros.\n' \
                "$backup_dir" "$dir_owner" >&2
            exit 1
            ;;
    esac

    if ! sudo -u postgres test -x "$backup_dir"; then
        if [[ "$dir_owner" == root ]]; then
            printf 'ERROR: con owner root, postgres debe poder atravesar %s; ajuste permisos/ACL sin habilitar escritura.\n' \
                "$backup_dir" >&2
        else
            printf 'ERROR: postgres no puede atravesar %s con modo 0700; revise los permisos de sus directorios padre.\n' \
                "$backup_dir" >&2
        fi
        exit 1
    fi

    short_head="$(sudo -u www-data git -C /var/www/simo rev-parse --short=12 HEAD)"
    timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
    final_path="${backup_dir}/simo_pre_${short_head}_${timestamp}.dump"

    if sudo test -e "$final_path" || sudo test -L "$final_path"; then
        printf 'ERROR: el destino ya existe; no se sobrescribirá: %s\n' "$final_path" >&2
        exit 1
    fi

    cleanup_partial() {
        status=$?
        trap - EXIT
        if (( status != 0 )); then
            sudo rm -f -- "$partial_path" || true
        fi
        exit "$status"
    }

    partial_path="$(sudo mktemp --tmpdir="$backup_dir" \
        "simo_pre_${short_head}_${timestamp}.XXXXXXXXXX.partial")"
    trap cleanup_partial EXIT

    sudo chown postgres:postgres -- "$partial_path"
    sudo chmod 0600 -- "$partial_path"

    sudo -u postgres pg_dump \
        --format=custom \
        --dbname="$database" \
        --file="$partial_path"

    if ! sudo test -s "$partial_path"; then
        printf 'ERROR: pg_dump no produjo un archivo no vacío.\n' >&2
        exit 1
    fi

    sudo -u postgres pg_restore --list "$partial_path" >/dev/null
    sudo -u postgres sync -f "$partial_path"

    sudo mv --no-clobber --no-target-directory -- "$partial_path" "$final_path"
    if sudo test -e "$partial_path" || sudo test -L "$partial_path"; then
        printf 'ERROR: el destino apareció durante la operación; no se sobrescribió.\n' >&2
        exit 1
    fi

    sudo -u postgres sync -f "$final_path"

    owner="$(sudo stat --format='%U:%G' -- "$final_path")"
    mode="$(sudo stat --format='%a' -- "$final_path")"
    size_bytes="$(sudo stat --format='%s' -- "$final_path")"

    if [[ "$owner" != postgres:postgres || "$mode" != 600 ]]; then
        printf 'ERROR: ownership o permisos finales inesperados.\n' >&2
        exit 1
    fi

    printf 'DATABASE=%s\nHEAD=%s\nCREATED_UTC=%s\nSIZE_BYTES=%s\nOWNER=%s\nMODE=%s\nBACKUP_READY=%s\n' \
        "$database" "$short_head" "$timestamp" "$size_bytes" "$owner" "$mode" "$final_path"
)
```

En el VPS Ubuntu/Debian objetivo, `sync -f` solicita vaciar al almacenamiento el sistema de archivos que contiene el archivo antes y después del renombrado atómico. El deploy **debe detenerse** salvo que el operador confirme que el nuevo archivo indicado por `BACKUP_READY` corresponde al `HEAD` actual y a la ventana de deploy en curso.

`pg_restore --list` valida que el archivo sea legible y permite recorrer su tabla de contenidos (TOC), pero no demuestra que una restauración completa sea exitosa. El simulacro de restauración debe realizarse en un entorno aislado que no sea producción.

> **Límite de rollback:** un dump lógico no revierte automáticamente el código ni las migraciones de la aplicación. Nunca debe restaurarse de forma casual sobre la base de datos activa; una restauración requiere un procedimiento separado, revisado y ensayado.

---

## Workflow de actualización en VPS

**Precondiciones obligatorias:** el respaldo anterior debe haber finalizado correctamente y el operador debe haber confirmado un `BACKUP_READY` nuevo y vigente. Además, Node.js 20+ y npm deben resolver en el mismo contexto no interactivo de `www-data` que ejecutará el build. Si cualquiera de estas comprobaciones falla, detener el deploy: no ejecutar `git pull`, `artisan migrate` ni el build, y no improvisar una instalación o un cambio de `PATH` durante la ventana.

```bash
# PRE-FLIGHT: exige Node.js 20+, npm y un estado seguro de Vite en el contexto exacto del build.
(
    set -euo pipefail

    project=/var/www/simo
    vite_temp="$project/node_modules/.vite-temp"

    sudo -u www-data -- sh -c 'set -eu; cd /var/www/simo; command -v node >/dev/null 2>&1; command -v npm >/dev/null 2>&1; node -e "const major = Number(process.versions.node.split(\".\")[0]); if (!Number.isInteger(major) || major < 20) process.exit(1)"; node --version; npm --version'

    if sudo test -e "$vite_temp" || sudo test -L "$vite_temp"; then
        if sudo test -L "$vite_temp" || ! sudo test -d "$vite_temp"; then
            printf 'ERROR: %s debe ser un directorio real, no un enlace simbólico.\n' "$vite_temp" >&2
            exit 1
        fi

        owner="$(sudo stat --format='%U:%G' -- "$vite_temp")"
        if [[ "$owner" != www-data:www-data ]]; then
            printf 'ERROR: ownership inesperado en %s: %s.\n' "$vite_temp" "$owner" >&2
            exit 1
        fi

        if ! sudo -u www-data -- test -w "$vite_temp"; then
            printf 'ERROR: www-data no puede escribir en %s.\n' "$vite_temp" >&2
            exit 1
        fi

        if ! sudo -u www-data -- test -x "$vite_temp"; then
            printf 'ERROR: www-data no puede atravesar %s.\n' "$vite_temp" >&2
            exit 1
        fi
    fi
)

sudo -u www-data git -C /var/www/simo pull origin main

# PUERTA DE CONTROL: detenerse aquí si no se confirmó el BACKUP_READY vigente.
sudo -u www-data php /var/www/simo/artisan migrate --force

# Si el pull trae cambios de UI (blade / css / js), recompilar los assets.
# OJO: el CSS compilado (public/build) está gitignored — NO viaja con git pull,
# hay que rebuildearlo en cada entorno o el navegador sirve estilos viejos.
sudo -u www-data php /var/www/simo/artisan view:cache    # precompila vistas (Tailwind escanea storage/framework/views)
sudo -u www-data -- sh -c 'cd /var/www/simo && npm run build'
# Después: hard-refresh del navegador (Ctrl+Shift+R) para bajar el CSS nuevo.

supervisorctl restart simo-pep-monitor
supervisorctl restart simo-gemini-worker
supervisorctl restart simo-dedupe-worker
supervisorctl restart simo-gaceta-runner   # ver sección "Colector de la Gaceta"
```

> **Lección (2026-06):** correr cualquier `artisan`/`npm` como **root** deja archivos root-owned que rompen php-fpm (cache de Spatie, `storage/`, `public/build`). El pre-flight y el build deben ejecutarse siempre como `www-data`; si el output no conserva ese ownership, detenerse e investigar en vez de normalizar un build de root mediante un `chown -R`. **Nunca** correr la suite de tests (`php artisan test`) apuntando a la BD real — `RefreshDatabase` hace `migrate:fresh` y la borra entera.

La puerta de Vite es permanente y fail-closed. En un deploy normal no autoriza `chown`, `chmod`, `rm -rf`, builds como root, reinstalación de dependencias ni cambio del config loader de Vite. Si falla, detenerse y diagnosticar fuera de la ventana; no normalizar permisos para forzar el build.

---

## Recuperación única del incidente Vite en `4db1450`

Este procedimiento sirve **una sola vez** para continuar el incidente que quedó detenido, antes de los reinicios, sobre el `HEAD` productivo exacto `4db14509564880449564285cbefdf92a3ba629ed`. En ese intento ya finalizaron correctamente `migrate --force` y `view:cache`: **no repetir ninguno de los dos comandos**. La procedencia del directorio root-owned no está demostrada y no debe atribuirse.

Los bloques siguientes documentan planes condicionados; **no conceden autorización vigente**. La Etapa A y la Etapa B requieren resúmenes separados y autorización explícita para el commit exacto antes de ejecutar cualquier mutación.

### Prueba local de publicación

La excepción solo adquiere autoridad cuando `RECOVERY_COMMIT` sea el hijo directo de `4db14509564880449564285cbefdf92a3ba629ed`, esté publicado como `origin/main` y su diff completo modifique exactamente `DEPLOY.md`. Validar ese vínculo en un checkout local confiable, limpio y actualizado; **no ejecutar este bloque en el VPS**:

```bash
(
    set -euo pipefail

    base=4db14509564880449564285cbefdf92a3ba629ed
    : "${RECOVERY_COMMIT:?Defina el SHA completo del commit de recuperación publicado}"

    [[ "$RECOVERY_COMMIT" =~ ^[0-9a-f]{40}$ ]]
    [[ "$RECOVERY_COMMIT" != "$base" ]]
    branch="$(git branch --show-current)"
    head="$(git rev-parse HEAD)"
    parents="$(git show -s --format='%P' "$RECOVERY_COMMIT")"
    worktree_status="$(git status --porcelain=v1 --untracked-files=all)"
    [[ "$branch" == main ]]
    [[ "$head" == "$RECOVERY_COMMIT" ]]
    [[ "$parents" == "$base" ]]
    [[ -z "$worktree_status" ]]

    published_line="$(git ls-remote --exit-code origin refs/heads/main)"
    read -r published_commit published_ref <<< "$published_line"
    [[ "$published_commit" == "$RECOVERY_COMMIT" ]]
    [[ "$published_ref" == refs/heads/main ]]

    changed_paths="$(git diff --name-only --diff-filter=ACDMRTUXB "$base" "$RECOVERY_COMMIT")"
    [[ "$changed_paths" == DEPLOY.md ]]
)
```

La prueba local debe registrarse en el resumen de la Etapa A sin incluir datos del respaldo. Antes de abrir la ventana, el operador debe exportar estas variables en la misma shell:

| Variable | Valor requerido |
|---|---|
| `RECOVERY_COMMIT` | SHA completo validado por el bloque local anterior |
| `RUNBOOK_ONLY_COMMIT_ATTESTED` | Mismo valor que `RECOVERY_COMMIT` |
| `STAGE_A_AUTHORIZED_FOR_COMMIT` | Mismo valor que `RECOVERY_COMMIT`, solo tras autorización explícita de la Etapa A |
| `BACKUP_READY` | Literal opaco `CONFIRMADO` |
| `BACKUP_READY_ATTESTED_FOR_HEAD` | `4db14509564880449564285cbefdf92a3ba629ed` |
| `EXCLUSIVE_DEPLOYMENT_WINDOW` | `CONFIRMADA` |

`BACKUP_READY=CONFIRMADO` no contiene una ruta ni contenido del respaldo. Este flujo nunca solicita, exporta, almacena, inspecciona ni muestra una ruta o contenido de respaldo; el token solo atestigua un respaldo fresco para el commit base exacto y la ventana exclusiva actual. No reutilizar el token en otra ventana.

### Etapa A — bootstrap exclusivo del runbook

Antes de ejecutar, presentar un resumen que limite esta etapa al fetch, la verificación inmutable y el fast-forward exacto del commit documental autorizado, y obtener autorización explícita para la Etapa A. El fetch es una mutación explícita de metadatos de Git que actualiza `FETCH_HEAD`, no el worktree. El bloque se detiene ante el primer fallo; no ejecuta migraciones, cache, build ni reinicios.

```bash
(
    set -euo pipefail

    base=4db14509564880449564285cbefdf92a3ba629ed
    project=/var/www/simo
    runbook="$project/DEPLOY.md"

    fail() {
        printf 'ERROR: %s\n' "$1" >&2
        exit 1
    }

    : "${RECOVERY_COMMIT:?Falta el SHA del commit publicado y validado localmente}"
    [[ "$RECOVERY_COMMIT" =~ ^[0-9a-f]{40}$ ]] || fail 'RECOVERY_COMMIT no es un SHA completo'
    [[ "$RECOVERY_COMMIT" != "$base" ]] || fail 'RECOVERY_COMMIT no puede ser el commit base'
    [[ "${RUNBOOK_ONLY_COMMIT_ATTESTED:-}" == "$RECOVERY_COMMIT" ]] || fail 'falta la prueba local del commit publicado que solo modifica DEPLOY.md'
    [[ "${STAGE_A_AUTHORIZED_FOR_COMMIT:-}" == "$RECOVERY_COMMIT" ]] || fail 'la Etapa A no está autorizada para RECOVERY_COMMIT'
    [[ "${BACKUP_READY:-}" == CONFIRMADO ]] || fail 'falta el token opaco BACKUP_READY=CONFIRMADO'
    [[ "${BACKUP_READY_ATTESTED_FOR_HEAD:-}" == "$base" ]] || fail 'BACKUP_READY no está atestado para el commit base exacto'
    [[ "${EXCLUSIVE_DEPLOYMENT_WINDOW:-}" == CONFIRMADA ]] || fail 'la ventana exclusiva de deploy no está confirmada'

    if sudo test -L "$project" || ! sudo test -d "$project"; then
        fail "el proyecto debe ser un directorio real: $project"
    fi

    production_branch="$(sudo -u www-data -- git -C "$project" branch --show-current)"
    production_head="$(sudo -u www-data -- git -C "$project" rev-parse HEAD)"
    production_status="$(sudo -u www-data -- git -C "$project" status --porcelain=v1 --untracked-files=all)"
    [[ "$production_branch" == main ]] || fail 'producción no está en main'
    [[ "$production_head" == "$base" ]] || fail 'HEAD productivo no coincide con el incidente'
    [[ -z "$production_status" ]] || fail 'el worktree productivo no está limpio'

    # Esta operación actualiza solo metadatos de Git (FETCH_HEAD), no el worktree.
    sudo -u www-data -- git -C "$project" fetch --no-tags origin refs/heads/main

    fetched_commit="$(sudo -u www-data -- git -C "$project" rev-parse FETCH_HEAD)"
    fetched_parents="$(sudo -u www-data -- git -C "$project" show -s --format='%P' "$fetched_commit")"
    changed_paths="$(sudo -u www-data -- git -C "$project" diff --name-only --diff-filter=ACDMRTUXB "$base" "$fetched_commit")"
    [[ "$fetched_commit" == "$RECOVERY_COMMIT" ]] || fail 'FETCH_HEAD no coincide con RECOVERY_COMMIT'
    [[ "$fetched_parents" == "$base" ]] || fail 'FETCH_HEAD no es hijo directo único del commit base'
    [[ "$changed_paths" == DEPLOY.md ]] || fail 'el diff obtenido desde el commit base no es exactamente DEPLOY.md'

    sudo -u www-data -- git -C "$project" merge --ff-only "$fetched_commit"

    production_head="$(sudo -u www-data -- git -C "$project" rev-parse HEAD)"
    production_parents="$(sudo -u www-data -- git -C "$project" show -s --format='%P' "$fetched_commit")"
    production_status="$(sudo -u www-data -- git -C "$project" status --porcelain=v1 --untracked-files=all)"
    changed_paths="$(sudo -u www-data -- git -C "$project" diff --name-only --diff-filter=ACDMRTUXB "$base" "$fetched_commit")"
    [[ "$production_head" == "$fetched_commit" ]] || fail 'el fast-forward no dejó HEAD exactamente en el objeto verificado'
    [[ "$production_head" == "$RECOVERY_COMMIT" ]] || fail 'HEAD no coincide con RECOVERY_COMMIT'
    [[ "$production_parents" == "$base" ]] || fail 'el objeto verificado no es hijo directo único del commit base'
    [[ -z "$production_status" ]] || fail 'el worktree no quedó limpio después del fast-forward'
    [[ "$changed_paths" == DEPLOY.md ]] || fail 'el diff desplegado desde el commit base no es exactamente DEPLOY.md'

    if sudo test -L "$runbook" || ! sudo test -f "$runbook"; then
        fail "el runbook desplegado debe ser un archivo real: $runbook"
    fi
)
```

### Lectura obligatoria entre etapas

Tras finalizar la Etapa A, detenerse. El operador debe releer **completo** el runbook ya desplegado antes de resumir la Etapa B y solicitar una autorización nueva y específica:

```bash
sudo -u www-data -- cat -- /var/www/simo/DEPLOY.md
```

La lectura no autoriza la Etapa B. Solo después de completarla, exportar `DEPLOYED_RUNBOOK_REREAD_FOR_COMMIT` y `STAGE_B_AUTHORIZED_FOR_COMMIT`, ambos con el valor exacto de `RECOVERY_COMMIT`; el segundo únicamente después de recibir autorización explícita para la Etapa B.

### Etapa B — recuperación de Vite y reinicios acotados

Ejecutar todo el bloque en una sola shell después de la lectura y autorización anteriores. Se detiene ante el primer fallo. La única remediación manual permitida es el `rmdir` exacto indicado; el build es el único paso de aplicación que se reintenta.

```bash
(
    set -euo pipefail

    base=4db14509564880449564285cbefdf92a3ba629ed
    project=/var/www/simo
    node_modules="$project/node_modules"
    vite_temp="$node_modules/.vite-temp"
    public_dir="$project/public"
    build_dir="$public_dir/build"

    fail() {
        printf 'ERROR: %s\n' "$1" >&2
        exit 1
    }

    require_real_directory() {
        local path="$1"
        local label="$2"

        if sudo test -L "$path" || ! sudo test -d "$path"; then
            fail "$label debe ser un directorio real, no un enlace simbólico: $path"
        fi
    }

    require_running() {
        local identity="$1"
        local status_line observed_identity observed_state remainder

        status_line="$(sudo supervisorctl status "$identity")"
        read -r observed_identity observed_state remainder <<< "$status_line"
        if [[ "$observed_identity" != "$identity" || "$observed_state" != RUNNING ]]; then
            fail "Supervisor no confirmó RUNNING para la identidad exacta $identity"
        fi
    }

    : "${RECOVERY_COMMIT:?Falta el SHA del commit publicado y desplegado}"
    [[ "$RECOVERY_COMMIT" =~ ^[0-9a-f]{40}$ ]] || fail 'RECOVERY_COMMIT no es un SHA completo'
    [[ "$RECOVERY_COMMIT" != "$base" ]] || fail 'RECOVERY_COMMIT no puede ser el commit base'
    [[ "${RUNBOOK_ONLY_COMMIT_ATTESTED:-}" == "$RECOVERY_COMMIT" ]] || fail 'falta la prueba local del commit publicado que solo modifica DEPLOY.md'
    [[ "${BACKUP_READY:-}" == CONFIRMADO ]] || fail 'falta el token opaco BACKUP_READY=CONFIRMADO'
    [[ "${BACKUP_READY_ATTESTED_FOR_HEAD:-}" == "$base" ]] || fail 'BACKUP_READY no está atestado para el commit base exacto'
    [[ "${EXCLUSIVE_DEPLOYMENT_WINDOW:-}" == CONFIRMADA ]] || fail 'la ventana exclusiva de deploy no está confirmada'
    [[ "${DEPLOYED_RUNBOOK_REREAD_FOR_COMMIT:-}" == "$RECOVERY_COMMIT" ]] || fail 'no se atestó la lectura del runbook desplegado para RECOVERY_COMMIT'
    [[ "${STAGE_B_AUTHORIZED_FOR_COMMIT:-}" == "$RECOVERY_COMMIT" ]] || fail 'la Etapa B no está autorizada para RECOVERY_COMMIT'

    require_real_directory "$project" 'El proyecto'
    require_real_directory "$node_modules" 'El parent de Vite'
    require_real_directory "$public_dir" 'El directorio public'

    production_branch="$(sudo -u www-data -- git -C "$project" branch --show-current)"
    production_head="$(sudo -u www-data -- git -C "$project" rev-parse HEAD)"
    production_parents="$(sudo -u www-data -- git -C "$project" show -s --format='%P' "$RECOVERY_COMMIT")"
    production_status="$(sudo -u www-data -- git -C "$project" status --porcelain=v1 --untracked-files=all)"
    changed_paths="$(sudo -u www-data -- git -C "$project" diff --name-only --diff-filter=ACDMRTUXB "$base" "$RECOVERY_COMMIT")"
    [[ "$production_branch" == main ]] || fail 'producción no está en main'
    [[ "$production_head" == "$RECOVERY_COMMIT" ]] || fail 'HEAD productivo no coincide con RECOVERY_COMMIT'
    [[ "$production_parents" == "$base" ]] || fail 'RECOVERY_COMMIT no es hijo directo único del commit base'
    [[ -z "$production_status" ]] || fail 'el worktree productivo no está limpio'
    [[ "$changed_paths" == DEPLOY.md ]] || fail 'la aplicación difiere del commit base en rutas distintas de DEPLOY.md'

    node_modules_owner="$(sudo stat --format='%U:%G' -- "$node_modules")"
    node_modules_mode="$(sudo stat --format='%a' -- "$node_modules")"
    [[ "$node_modules_owner" == www-data:www-data ]] || fail 'node_modules no pertenece a www-data:www-data'
    [[ "$node_modules_mode" == 755 ]] || fail 'node_modules no tiene modo 0755 exacto'
    sudo -u www-data -- test -w "$node_modules" || fail 'www-data no puede escribir en node_modules'
    sudo -u www-data -- test -x "$node_modules" || fail 'www-data no puede atravesar node_modules'

    require_real_directory "$vite_temp" 'El directorio temporal de Vite'
    vite_temp_owner="$(sudo stat --format='%U:%G' -- "$vite_temp")"
    vite_temp_mode="$(sudo stat --format='%a' -- "$vite_temp")"
    [[ "$vite_temp_owner" == root:root ]] || fail '.vite-temp no pertenece a root:root'
    [[ "$vite_temp_mode" == 755 ]] || fail '.vite-temp no tiene modo 0755 exacto'
    vite_temp_entry="$(sudo find "$vite_temp" -mindepth 1 -maxdepth 1 -print -quit)"
    [[ -z "$vite_temp_entry" ]] || fail '.vite-temp no está vacío'

    sudo -u www-data -- sh -c 'set -eu; cd /var/www/simo; command -v node >/dev/null 2>&1; command -v npm >/dev/null 2>&1; node -e "const major = Number(process.versions.node.split(\".\")[0]); if (!Number.isInteger(major) || major < 20) process.exit(1)"; node --version; npm --version'

    for command_name in npm node vite; do
        if pgrep -x -- "$command_name" >/dev/null; then
            fail "hay un proceso activo con nombre exacto $command_name"
        else
            pgrep_status=$?
            [[ "$pgrep_status" == 1 ]] || fail "no se pudo verificar la ausencia de procesos $command_name"
        fi
    done

    supervisor_identities=(
        'simo-pep-monitor'
        'simo-runner'
        'simo-gaceta-runner'
        'simo-site-validation-worker:simo-site-validation-worker_00'
        'simo-dedupe-worker'
        'simo-gemini-worker'
    )
    for identity in "${supervisor_identities[@]}"; do
        require_running "$identity"
    done

    if sudo test -e "$build_dir" || sudo test -L "$build_dir"; then
        require_real_directory "$build_dir" 'public/build'
        unexpected_build_owner="$(sudo find "$build_dir" -xdev \( ! -user www-data -o ! -group www-data \) -print -quit)"
        [[ -z "$unexpected_build_owner" ]] || fail 'public/build contiene ownership distinto de www-data:www-data antes del build'
    fi

    sudo -u www-data -- rmdir -- "$vite_temp"
    if sudo test -e "$vite_temp" || sudo test -L "$vite_temp"; then
        fail 'rmdir no eliminó únicamente .vite-temp'
    fi

    sudo -u www-data -- sh -c 'set -eu; cd /var/www/simo; npm run build'

    require_real_directory "$vite_temp" 'El .vite-temp recreado por Vite'
    recreated_owner="$(sudo stat --format='%U:%G' -- "$vite_temp")"
    [[ "$recreated_owner" == www-data:www-data ]] || fail 'Vite no recreó .vite-temp como www-data:www-data'
    sudo -u www-data -- test -w "$vite_temp" || fail 'www-data no puede escribir en el .vite-temp recreado'
    sudo -u www-data -- test -x "$vite_temp" || fail 'www-data no puede atravesar el .vite-temp recreado'
    recreated_entry="$(sudo find "$vite_temp" -mindepth 1 -maxdepth 1 -print -quit)"
    [[ -z "$recreated_entry" ]] || fail 'el .vite-temp recreado no quedó vacío'

    require_real_directory "$build_dir" 'public/build después del build'
    unexpected_build_owner="$(sudo find "$build_dir" -xdev \( ! -user www-data -o ! -group www-data \) -print -quit)"
    [[ -z "$unexpected_build_owner" ]] || fail 'public/build contiene ownership distinto de www-data:www-data'
    production_head="$(sudo -u www-data -- git -C "$project" rev-parse HEAD)"
    production_status="$(sudo -u www-data -- git -C "$project" status --porcelain=v1 --untracked-files=all)"
    [[ "$production_head" == "$RECOVERY_COMMIT" ]] || fail 'HEAD cambió durante la recuperación'
    [[ -z "$production_status" ]] || fail 'Git no quedó limpio después del build'

    sudo supervisorctl restart simo-pep-monitor
    sudo supervisorctl restart simo-gemini-worker
    sudo supervisorctl restart simo-dedupe-worker
    sudo supervisorctl restart simo-gaceta-runner

    for identity in "${supervisor_identities[@]}"; do
        require_running "$identity"
    done
)
```

No usar esta sección con otro commit base, otro `RECOVERY_COMMIT`, otro estado de `.vite-temp` ni otra ventana. En la Etapa B no ejecutar `migrate --force`, `view:cache`, `rm -rf`, `chown`, `chmod`, normalización amplia de ownership, reinstalación de dependencias, cambio del config loader ni mutaciones manuales sobre otra ruta. No reiniciar `simo-runner` ni `simo-site-validation-worker`. No ejecutar tests en producción, inspeccionar la base de datos, improvisar rollback, normalizar ownership de assets ni inventar un health check; ante cualquier fallo, detenerse y obtener nueva autoridad.

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

## Colector de la Gaceta (gaceta-collector)

`scripts/gaceta_collector/` es un colector de fuente primaria que recolecta **Decretos Presidenciales de la Gaceta Oficial de Bolivia** para detección PEP. Sigue el mismo patrón que `simo-runner`: `runner.py` lee `config_scripts WHERE script='gaceta'` en cada tick y lanza `main.py --once` como subproceso según `habilitado` / `intervalo_minutos` / `hora_inicio`-`hora_fin` / `dias_semana` / `timeout_minutos`. Se monitorea y configura desde la UI (`/scripts/estado` y `/scripts/configuracion`), igual que el scraper.

### Primer deploy (pasos en el VPS)

```bash
# 1. Código (ya en main: colector + migraciones gaceta)
sudo -u www-data git -C /var/www/simo pull origin main

# 2. Migraciones (crea gaceta_normas, gaceta_eventos_pep, índices trigram/GiST,
#    widening de log_scripts.script a 'gaceta'/'gaceta_backfill', cargo_referenciado)
sudo -u www-data php /var/www/simo/artisan migrate --force

# 3. venv propio del colector (igual que website_monitor_pro)
cd /var/www/simo/scripts/gaceta_collector
sudo -u www-data python3 -m venv .venv
sudo -u www-data .venv/bin/pip install -r requirements.txt

# 4. Sembrar la fila de config del script (si no existe en prod)
sudo -u www-data php /var/www/simo/artisan db:seed --class=ConfigScriptGacetaSeeder --force

# 5. PRE-FLIGHT obligatorio: probar UN ciclo a mano ANTES de habilitar el servicio
sudo -u www-data .venv/bin/python main.py --once --pais BO
#    Debe terminar estado='ok' y conectar a la BD. Si falla, ver "Gotchas".
```

### Bloque de Supervisor

Agregar a `/etc/supervisor/conf.d/simo.conf`:

```ini
[program:simo-gaceta-runner]
command=/var/www/simo/scripts/gaceta_collector/.venv/bin/python /var/www/simo/scripts/gaceta_collector/runner.py
directory=/var/www/simo/scripts/gaceta_collector
user=www-data
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
redirect_stderr=true
stdout_logfile=/var/log/simo/gaceta-runner.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=5
```

```bash
sudo supervisorctl reread && sudo supervisorctl update
sudo supervisorctl status simo-gaceta-runner          # esperado: RUNNING
sudo tail -f /var/log/simo/gaceta-runner.log          # seguir el primer ciclo
# Validar en BD:
sudo -u postgres psql simo -c \
  "SELECT id, script, inicio, estado, items_resultado FROM log_scripts WHERE script LIKE 'gaceta%' ORDER BY id DESC LIMIT 5;"
```

### Backfill inicial (baseline de 5 años, una sola vez)

Carga el baseline histórico de PEPs (regla boliviana: PEP se mantiene 5 años tras dejar el cargo). Idempotente (`ON CONFLICT`), auto-aprueba los eventos limpios:

```bash
cd /var/www/simo/scripts/gaceta_collector
sudo -u www-data .venv/bin/python main.py --backfill --pais BO
# Opcional: --desde-fecha YYYY-MM-DD para otro corte (default = hoy − 5 años)
```

### Gotchas (verificar en el PRE-FLIGHT, paso 5)

1. **Nombres de variables de BD difieren (CONFIRMADO en prod).** El colector Python lee `DB_NAME` / `DB_USER`; el `.env` de Laravel usa `DB_DATABASE` / `DB_USERNAME`. El colector toma `DB_HOST` / `DB_PASSWORD` bien, pero `DB_NAME` / `DB_USER` caen al **default** (`simo` / `postgres`). En el VPS productivo `DB_USERNAME=simo` (NO `postgres`), así que el `--once` falla la conexión hasta agregar al `/var/www/simo/.env`:
   ```bash
   echo 'DB_NAME=simo' >> /var/www/simo/.env
   echo 'DB_USER=simo' >> /var/www/simo/.env   # = el valor de tu DB_USERNAME
   ```
   (Inofensivas para Laravel — ignora `DB_NAME`/`DB_USER`.)
2. **DNS: el VPS resuelve solo IPv6, la Gaceta es IPv4-only (CONFIRMADO).** El servidor legacy de la Gaceta solo tiene registro A (IPv4); el resolver del VPS devuelve AAAA pero no A para ese dominio → `[Errno -3] Temporary failure in name resolution`. Verificar con `getent hosts www.gacetaoficialdebolivia.gob.bo` (vacío = no resuelve). Workaround rápido — mapear la IP en `/etc/hosts`:
   ```bash
   # confirmar primero que la IP responde:
   curl -sI -H 'Host: www.gacetaoficialdebolivia.gob.bo' http://181.115.190.188/   # debe dar 200 OK
   echo '181.115.190.188 www.gacetaoficialdebolivia.gob.bo' | sudo tee -a /etc/hosts
   ```
   (Persistente y lo usa el runner del supervisor.) El fix de fondo es arreglar el resolver del VPS para que resuelva A-records (la IP de `/etc/hosts` puede cambiar a futuro).
3. **HTTP-only:** la Gaceta no tiene TLS — `_BOLIVIA_BASE` ya usa `http://`. Si el VPS bloqueara salida HTTP (puerto 80), habilitarla.

### Rollback

```bash
sudo supervisorctl stop simo-gaceta-runner
# Comentar/eliminar el bloque [program:simo-gaceta-runner] en simo.conf
sudo supervisorctl reread && sudo supervisorctl update
```

El colector no toca datos de otros scripts; deshabilitarlo solo detiene la recolección de gaceta (los datos ya recolectados quedan intactos). También se puede pausar sin tocar supervisor poniendo `habilitado=false` en `/scripts/configuracion`.

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

[program:simo-site-validation-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/simo/artisan queue:work --queue=site-validation --sleep=3 --tries=3 --backoff=30 --timeout=45 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/simo/storage/logs/site-validation-worker.log

[program:simo-pep-monitor]
process_name=%(program_name)s_%(process_num)02d
command=python3 /var/www/simo/scripts/website_monitor_pro/pep_monitor.py run
autostart=true
autorestart=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/simo/storage/logs/pep-monitor.log
```

### Activar workers dedicados (primer deploy)

`simo-dedupe-worker` y `simo-site-validation-worker` son programas independientes. Este checklist no presupone que ninguno esté configurado, cargado ni activo.

Antes de ejecutar `reread`, `update` o `start`, el operador debe:

1. Identificar el archivo de configuración real que define cada programa; no asumir que ambos están en el mismo archivo ni que `/etc/supervisor/conf.d/simo.conf` es el target vigente.
2. Establecer para cada archivo que se modificará una copia de respaldo no sobrescribible y una ruta de rollback comprobada que restaure únicamente ese archivo.
3. Comparar la configuración real con los bloques exactos documentados arriba y confirmar cuál de los dos programas falta.
4. Confirmar que la actualización no contiene cambios pendientes para otros programas de Supervisor.

Si el target real, el respaldo no sobrescribible o la ruta de rollback no están identificados, detenerse. No ejecutar `reread`, `update` ni `start`, y no reiniciar workers existentes no relacionados.

Una vez superada esa puerta de control:

```bash
# 1. Agregar al target real únicamente los bloques ausentes de los dos programas (ver arriba)
#    y validar que el rollback no sobrescribirá un respaldo anterior.

# 2. Recargar la configuración de supervisor
sudo supervisorctl reread && sudo supervisorctl update

# 3. Iniciar únicamente cada worker confirmado como nuevo y todavía no activo
sudo supervisorctl start simo-dedupe-worker
sudo supervisorctl start simo-site-validation-worker

# 4. Verificar el estado real de ambos; no asumir que site-validation quedó activo
sudo supervisorctl status simo-dedupe-worker
sudo supervisorctl status simo-site-validation-worker
# Continuar solo si ambos reportan RUNNING.

# 5. Verificar los logs de ambos workers
tail -20 /var/www/simo/storage/logs/dedupe-worker.log
tail -20 /var/www/simo/storage/logs/site-validation-worker.log
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
