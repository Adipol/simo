#!/usr/bin/env python3
"""
runner.py — Orquestador del scraper SIMO
=========================================
Lee config_scripts WHERE script='scraper' desde PostgreSQL y decide cuándo
invocar el scraper según intervalo, ventana horaria, días habilitados y timeout.
Reemplaza el hardcoding de SCRAPE_INTERVAL_HOURS en .env.

Uso:
    python runner.py

Para detener: Ctrl+C
"""

import os
import sys
import time
import signal
import subprocess
import logging
from datetime import datetime, timedelta
from logging.handlers import RotatingFileHandler
from pathlib import Path
from typing import Optional

import psycopg2
import psycopg2.extras
from dotenv import load_dotenv

# ════════════════════════════════════════════════════════════════
# DUAL .ENV LOADING — igual patrón que pep_monitor.py (líneas 50-58)
# ════════════════════════════════════════════════════════════════
_SCRIPT_DIR = Path(__file__).resolve().parent
_SCRIPT_ENV = _SCRIPT_DIR / ".env"
_LARAVEL_ROOT = _SCRIPT_DIR.parent.parent
_DOTENV_PATH = _LARAVEL_ROOT / ".env"

if _SCRIPT_ENV.is_file():
    load_dotenv(dotenv_path=_SCRIPT_ENV)
if _DOTENV_PATH.is_file():
    load_dotenv(dotenv_path=_DOTENV_PATH, override=False)

# ════════════════════════════════════════════════════════════════
# CONSTANTES
# ════════════════════════════════════════════════════════════════
BASE_DIR = Path(__file__).resolve().parent

LOOP_INTERVAL_DEFAULT = int(os.getenv("RUNNER_LOOP_INTERVAL", "30"))
LOOP_INTERVAL = LOOP_INTERVAL_DEFAULT

LOCK_FILE = BASE_DIR / "runner.lock"

# Directorio y ejecutable del scraper — configurables por env vars
_DEFAULT_SCRAPER_DIR = BASE_DIR.parent.parent / "scripts" / "scraper_v2.2"
SCRAPER_DIR = Path(os.getenv("SCRAPER_DIR", str(_DEFAULT_SCRAPER_DIR)))

_DEFAULT_SCRAPER_PYTHON = str(SCRAPER_DIR / "venv" / "bin" / "python")
SCRAPER_PYTHON = os.getenv("SCRAPER_PYTHON", _DEFAULT_SCRAPER_PYTHON)

# Backoff de reconexión a BD
_DB_RETRY_DELAYS = [5, 15, 30]

# ════════════════════════════════════════════════════════════════
# LOGGING
# ════════════════════════════════════════════════════════════════
_LOG_FILE = BASE_DIR / "runner.log"
_LOG_LEVEL = os.getenv("LOG_LEVEL", "INFO").upper()

_fmt = logging.Formatter(
    "[%(asctime)s] %(levelname)-7s | runner | %(message)s",
    datefmt="%Y-%m-%d %H:%M:%S",
)

_ch = logging.StreamHandler(sys.stdout)
_ch.setFormatter(_fmt)

_fh = RotatingFileHandler(
    str(_LOG_FILE),
    maxBytes=10 * 1024 * 1024,  # 10 MB
    backupCount=5,
    encoding="utf-8",
)
_fh.setFormatter(_fmt)

log = logging.getLogger("runner")
log.setLevel(getattr(logging, _LOG_LEVEL, logging.INFO))
log.addHandler(_ch)
log.addHandler(_fh)


# ════════════════════════════════════════════════════════════════
# HELPERS DE DECISIÓN
# ════════════════════════════════════════════════════════════════

def en_ventana_horaria(cfg: dict, ahora: datetime) -> bool:
    """
    Retorna True si `ahora` está dentro de la ventana horaria configurada.

    Casos:
    - hora_inicio=None o hora_fin=None → True (sin restricción)
    - Maneja tanto datetime.time como timedelta (psycopg2 devuelve timedelta para TIME)
    - Ventana cruzando medianoche NO soportada en V1 → log warning + False
    """
    hora_inicio = cfg.get("hora_inicio")
    hora_fin = cfg.get("hora_fin")

    # Sin restricción de horario
    if hora_inicio is None or hora_fin is None:
        return True

    # Normalizar timedelta → time (psycopg2 puede retornar timedelta para columnas TIME)
    if isinstance(hora_inicio, timedelta):
        hora_inicio = (datetime.min + hora_inicio).time()
    if isinstance(hora_fin, timedelta):
        hora_fin = (datetime.min + hora_fin).time()

    # Ventana cruzando medianoche: no soportado V1
    if hora_inicio > hora_fin:
        log.warning(
            "Ventana horaria cruza medianoche (hora_inicio > hora_fin) — "
            "no soportado en V1. Se omite ejecución. "
            f"hora_inicio={hora_inicio}, hora_fin={hora_fin}"
        )
        return False

    hora_actual = ahora.time().replace(second=0, microsecond=0)
    return hora_inicio <= hora_actual <= hora_fin


def dia_activo(cfg: dict, ahora: datetime) -> bool:
    """
    Retorna True si el día de `ahora` está en la lista dias_semana.

    dias_semana es un CSV con ISO weekday: "1,2,3,4,5,6,7" (lunes=1, domingo=7).
    Si dias_semana es None o vacío → True (sin restricción de día).
    """
    dias_raw = cfg.get("dias_semana")
    if not dias_raw:
        return True

    try:
        dias_activos = [int(d.strip()) for d in str(dias_raw).split(",") if d.strip()]
    except ValueError:
        log.warning(f"dias_semana con formato inválido: {dias_raw!r} — se asume todos activos")
        return True

    if not dias_activos:
        return True

    return ahora.isoweekday() in dias_activos


def debe_ejecutar(ultimo: Optional[datetime], intervalo_min: int, ahora: datetime) -> bool:
    """
    Retorna True si ya pasó `intervalo_min` minutos desde `ultimo`.

    - ultimo=None → True (primera ejecución)
    - intervalo_min <= 0 → False con log warning (fail-safe SS-013)
    """
    if intervalo_min <= 0:
        log.warning(
            f"intervalo_min={intervalo_min} <= 0 — fail-safe activado, no se ejecuta"
        )
        return False

    if ultimo is None:
        return True

    segundos_transcurridos = (ahora - ultimo).total_seconds()
    return segundos_transcurridos >= intervalo_min * 60


# ════════════════════════════════════════════════════════════════
# LOCK FILE
# ════════════════════════════════════════════════════════════════

def lock_existe_y_activo(lock_path: Path) -> bool:
    """
    Retorna True si el lock file existe y el PID registrado está vivo.

    Si el PID está muerto (orphan) → limpia el archivo y retorna False.
    Si el archivo no existe → retorna False.
    """
    if not lock_path.exists():
        return False

    try:
        pid_str = lock_path.read_text(encoding="utf-8").strip()
        pid = int(pid_str)
    except (ValueError, OSError):
        # Lock corrupto (no parseable o ilegible) → tratarlo como orphan
        log.warning(f"Lock corrupto en {lock_path} — limpiando")
        try:
            lock_path.unlink()
        except OSError:
            pass
        return False

    # Discriminar entre proceso vivo y proceso muerto.
    # ProcessLookupError = pid muerto → orphan, limpiar
    # PermissionError = proceso vivo pero sin permisos para señalizarlo → tratarlo como activo
    try:
        os.kill(pid, 0)  # signal 0 = check existence without sending signal
        return True
    except ProcessLookupError:
        pass
    except PermissionError:
        # Proceso existe pero no podemos señalizarlo (otro user). Considerarlo activo
        # para no borrar lock de un proceso vivo.
        log.warning(f"Lock con PID {pid} sin permisos para señalizar — asumiendo activo")
        return True
    except OSError:
        # Otros errores raros (ENOMEM, etc.) → cautela: tratar como activo
        log.warning(f"Lock con PID {pid} error inesperado al señalizar — asumiendo activo")
        return True

    # Orphan lock confirmado — limpiar
    log.warning(f"Lock orphan detectado en {lock_path} (PID {pid} muerto) — limpiando")
    try:
        lock_path.unlink()
    except OSError:
        pass
    return False


def escribir_lock(lock_path: Path) -> None:
    """Escribe el PID actual en lock_path."""
    lock_path.write_text(str(os.getpid()), encoding="utf-8")


def limpiar_lock(lock_path: Path) -> None:
    """Elimina lock_path si existe."""
    if lock_path.exists():
        try:
            lock_path.unlink()
        except OSError as e:
            log.warning(f"No se pudo eliminar lock file {lock_path}: {e}")


# ════════════════════════════════════════════════════════════════
# BD HELPERS
# ════════════════════════════════════════════════════════════════

def get_db_connection() -> psycopg2.extensions.connection:
    """
    Retorna una conexión psycopg2 a PostgreSQL.

    Reintenta 3 veces con backoff 5/15/30s sobre OperationalError.
    Si los 3 intentos fallan, propaga la excepción.
    """
    db_kwargs = {
        "host": os.getenv("DB_HOST", "localhost"),
        "port": int(os.getenv("DB_PORT", "5432")),
        "user": os.getenv("DB_USER", "postgres"),
        "password": os.getenv("DB_PASSWORD", ""),
        "dbname": os.getenv("DB_NAME", "simo"),
        "connect_timeout": 10,
    }

    last_exc: Optional[Exception] = None
    for intento, delay in enumerate(_DB_RETRY_DELAYS, start=1):
        try:
            conn = psycopg2.connect(**db_kwargs)
            conn.autocommit = True
            return conn
        except psycopg2.OperationalError as exc:
            last_exc = exc
            log.warning(
                f"Error de conexión a BD (intento {intento}/{len(_DB_RETRY_DELAYS)}): {exc}. "
                f"Reintentando en {delay}s..."
            )
            time.sleep(delay)

    # Último intento sin captura — propaga
    raise last_exc  # type: ignore[misc]


def leer_config_scraper(conn: psycopg2.extensions.connection) -> Optional[dict]:
    """
    Lee la configuración del script 'scraper' desde config_scripts.

    Retorna el dict de la fila o None si no existe o hay error.
    """
    try:
        with conn.cursor(cursor_factory=psycopg2.extras.RealDictCursor) as cur:
            cur.execute(
                "SELECT * FROM config_scripts WHERE script = 'scraper' LIMIT 1"
            )
            row = cur.fetchone()
            if row is None:
                return None
            return dict(row)
    except Exception as exc:
        log.error(f"Error leyendo config_scripts para scraper: {exc}")
        return None


# ════════════════════════════════════════════════════════════════
# SUBPROCESS EXECUTION
# ════════════════════════════════════════════════════════════════

def ejecutar_scraper(cfg: dict) -> dict:
    """
    Lanza el scraper como subproceso y espera su finalización.

    Retorna dict con:
        estado: "ok" | "error" | "timeout"
        returncode: int
        duracion: float (segundos)
        mensaje_error: Optional[str]
    """
    cmd = [
        SCRAPER_PYTHON,
        str(SCRAPER_DIR / "main.py"),
        "--once",
        "--pais",
        "todos",
    ]
    timeout_seg = int(cfg.get("timeout_minutos", 60)) * 60

    log.info(f"Iniciando scraper | cmd: {' '.join(cmd)} | timeout: {timeout_seg // 60}min")

    inicio = datetime.now()
    try:
        proc = subprocess.Popen(
            cmd,
            cwd=str(SCRAPER_DIR),
            stdout=subprocess.DEVNULL,
            stderr=subprocess.PIPE,
        )
        log.info(f"Scraper PID {proc.pid}")

        try:
            _, stderr_bytes = proc.communicate(timeout=timeout_seg)
        except subprocess.TimeoutExpired:
            log.warning(f"Scraper superó timeout de {cfg.get('timeout_minutos')} min — terminando")
            proc.terminate()
            try:
                proc.wait(timeout=10)
            except subprocess.TimeoutExpired:
                log.warning("Scraper no terminó tras SIGTERM — forzando SIGKILL")
                proc.kill()
            duracion = (datetime.now() - inicio).total_seconds()
            return {
                "estado": "timeout",
                "returncode": -1,
                "duracion": duracion,
                "mensaje_error": f"Timeout tras {cfg.get('timeout_minutos')} minutos",
            }

        duracion = (datetime.now() - inicio).total_seconds()

        if proc.returncode == 0:
            log.info(f"Scraper finalizado OK (exit 0) en {duracion:.1f}s")
            return {
                "estado": "ok",
                "returncode": 0,
                "duracion": duracion,
                "mensaje_error": None,
            }
        else:
            msg = (stderr_bytes or b"").decode("utf-8", errors="replace")[:300].strip()
            log.warning(f"Scraper exit {proc.returncode}: {msg}")
            return {
                "estado": "error",
                "returncode": proc.returncode,
                "duracion": duracion,
                "mensaje_error": msg or None,
            }

    except Exception as exc:
        duracion = (datetime.now() - inicio).total_seconds()
        log.error(f"Error lanzando scraper: {exc}", exc_info=True)
        return {
            "estado": "error",
            "returncode": -1,
            "duracion": duracion,
            "mensaje_error": str(exc),
        }


# ════════════════════════════════════════════════════════════════
# LOG_SCRIPTS
# ════════════════════════════════════════════════════════════════

def registrar_log_scripts(
    conn: psycopg2.extensions.connection,
    script: str,
    inicio: datetime,
    fin: datetime,
    estado: str,
    items_procesados: int = 0,
    items_resultado: int = 0,
    errores: int = 0,
    mensaje_error: Optional[str] = None,
) -> None:
    """
    Inserta una fila wrapper en log_scripts.

    El runner registra solo inicio/fin/estado/duracion (counts=0).
    El scraper main.py inserta su propia fila con counts reales.
    """
    duracion_segundos = (fin - inicio).total_seconds()

    try:
        with conn.cursor() as cur:
            cur.execute(
                """
                INSERT INTO log_scripts (
                    script, inicio, fin, estado,
                    duracion_segundos, items_procesados,
                    items_resultado, errores, mensaje_error
                ) VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s)
                """,
                (
                    script,
                    inicio,
                    fin,
                    estado,
                    duracion_segundos,
                    items_procesados,
                    items_resultado,
                    errores,
                    mensaje_error,
                ),
            )
            conn.commit()
    except Exception as exc:
        log.error(f"Error registrando log_scripts para {script}: {exc}")


# ════════════════════════════════════════════════════════════════
# MAIN LOOP
# ════════════════════════════════════════════════════════════════

def main() -> None:
    """Loop principal del orquestador de scraper SIMO."""
    log.info("=" * 60)
    log.info("SIMO Runner iniciado")
    log.info(f"  Scraper dir   : {SCRAPER_DIR}")
    log.info(f"  Scraper python: {SCRAPER_PYTHON}")
    log.info(f"  Lock file     : {LOCK_FILE}")
    log.info(f"  Loop interval : {LOOP_INTERVAL}s")
    log.info("  Para detener  : Ctrl+C")
    log.info("=" * 60)

    ultimo_ciclo: Optional[datetime] = None

    while True:
        conn: Optional[psycopg2.extensions.connection] = None
        try:
            conn = get_db_connection()
            cfg = leer_config_scraper(conn)

            if not cfg:
                log.warning("config_scripts sin fila para 'scraper' — esperando")
                time.sleep(LOOP_INTERVAL)
                continue

            if not cfg.get("habilitado"):
                log.info("Scraper deshabilitado en config_scripts — esperando")
                time.sleep(LOOP_INTERVAL)
                continue

            ahora = datetime.now()

            if not dia_activo(cfg, ahora):
                log.info(f"Día {ahora.isoweekday()} no activo (dias_semana={cfg.get('dias_semana')}) — esperando")
                time.sleep(LOOP_INTERVAL)
                continue

            if not en_ventana_horaria(cfg, ahora):
                log.info(f"Fuera de ventana horaria ({cfg.get('hora_inicio')}–{cfg.get('hora_fin')}) — esperando")
                time.sleep(LOOP_INTERVAL)
                continue

            intervalo_min = int(cfg.get("intervalo_minutos", 60))
            if not debe_ejecutar(ultimo_ciclo, intervalo_min, ahora):
                if ultimo_ciclo:
                    seg_restantes = intervalo_min * 60 - (ahora - ultimo_ciclo).total_seconds()
                    log.debug(f"Próximo ciclo en {seg_restantes / 60:.1f}min — esperando")
                time.sleep(LOOP_INTERVAL)
                continue

            if lock_existe_y_activo(LOCK_FILE):
                log.warning("Lock activo — scraper ya corriendo, omitiendo ciclo")
                time.sleep(LOOP_INTERVAL)
                continue

            # ── Ejecutar ──
            escribir_lock(LOCK_FILE)
            inicio = datetime.now()
            log.info(f"CICLO INICIO — {inicio.strftime('%Y-%m-%d %H:%M:%S')}")

            resultado = ejecutar_scraper(cfg)

            fin = datetime.now()
            errores = 1 if resultado["estado"] != "ok" else 0
            registrar_log_scripts(
                conn,
                script="scraper",
                inicio=inicio,
                fin=fin,
                estado=resultado["estado"],
                items_procesados=0,
                items_resultado=0,
                errores=errores,
                mensaje_error=resultado.get("mensaje_error"),
            )
            limpiar_lock(LOCK_FILE)
            ultimo_ciclo = inicio

            log.info(
                f"CICLO FIN — estado={resultado['estado']} "
                f"duracion={resultado['duracion']:.1f}s "
                f"returncode={resultado['returncode']}"
            )

        except KeyboardInterrupt:
            log.info("Deteniendo runner (Ctrl+C)...")
            limpiar_lock(LOCK_FILE)
            log.info("Runner detenido.")
            break
        except Exception as exc:
            log.error(f"Error inesperado en loop: {exc}", exc_info=True)
            limpiar_lock(LOCK_FILE)
        finally:
            if conn is not None:
                try:
                    conn.close()
                except Exception:
                    pass
            time.sleep(LOOP_INTERVAL)


if __name__ == "__main__":
    main()
