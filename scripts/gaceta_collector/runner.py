#!/usr/bin/env python3
"""
runner.py — Gaceta Collector Orchestrator
==========================================
Mirrors website_monitor_pro/runner.py for the 'gaceta' script.

Reads config_scripts WHERE script='gaceta', seeds ultimo_ciclo from log_scripts,
and launches main.py --once as a subprocess on the configured interval.

Usage:
    python runner.py

Stop: Ctrl+C
"""

import os
import sys
import time
import subprocess
import logging
from datetime import datetime, timedelta
from logging.handlers import RotatingFileHandler
from pathlib import Path
from typing import Optional

import psycopg2
import psycopg2.extras
from dotenv import load_dotenv

# ── Dual .env loading ─────────────────────────────────────────────────────────
_SCRIPT_DIR = Path(__file__).resolve().parent
_SCRIPT_ENV = _SCRIPT_DIR / ".env"
_LARAVEL_ROOT = _SCRIPT_DIR.parent.parent
_DOTENV_PATH = _LARAVEL_ROOT / ".env"

if _SCRIPT_ENV.is_file():
    load_dotenv(dotenv_path=_SCRIPT_ENV)
if _DOTENV_PATH.is_file():
    load_dotenv(dotenv_path=_DOTENV_PATH, override=False)

# ── Constants ─────────────────────────────────────────────────────────────────
BASE_DIR = Path(__file__).resolve().parent

LOOP_INTERVAL_DEFAULT = int(os.getenv("RUNNER_LOOP_INTERVAL", "30"))
LOOP_INTERVAL = LOOP_INTERVAL_DEFAULT

LOCK_FILE = BASE_DIR / "gaceta_runner.lock"

_GACETA_PYTHON = str(BASE_DIR / ".venv" / "bin" / "python")
_GACETA_MAIN = BASE_DIR / "main.py"

_DB_RETRY_DELAYS = [5, 15, 30]

# Estado mapping: runner internal → log_scripts CHECK constraint values
_ESTADO_DB_MAP: dict[str, str] = {
    "ok": "completado",
    "timeout": "interrumpido",
    "error": "error",
}

# ── Logging ───────────────────────────────────────────────────────────────────
_LOG_FILE = BASE_DIR / "gaceta_runner.log"
_LOG_LEVEL = os.getenv("LOG_LEVEL", "INFO").upper()

_fmt = logging.Formatter(
    "[%(asctime)s] %(levelname)-7s | gaceta_runner | %(message)s",
    datefmt="%Y-%m-%d %H:%M:%S",
)
_ch = logging.StreamHandler(sys.stdout)
_ch.setFormatter(_fmt)
_fh = RotatingFileHandler(
    str(_LOG_FILE),
    maxBytes=10 * 1024 * 1024,
    backupCount=5,
    encoding="utf-8",
)
_fh.setFormatter(_fmt)

log = logging.getLogger("gaceta_runner")
log.setLevel(getattr(logging, _LOG_LEVEL, logging.INFO))
log.addHandler(_ch)
log.addHandler(_fh)


# ── Decision helpers (shared with website_monitor_pro pattern) ────────────────

def en_ventana_horaria(cfg: dict, ahora: datetime) -> bool:
    """Return True if ahora is within the configured time window."""
    hora_inicio = cfg.get("hora_inicio")
    hora_fin = cfg.get("hora_fin")

    if hora_inicio is None or hora_fin is None:
        return True

    if isinstance(hora_inicio, timedelta):
        hora_inicio = (datetime.min + hora_inicio).time()
    if isinstance(hora_fin, timedelta):
        hora_fin = (datetime.min + hora_fin).time()

    if hora_inicio > hora_fin:
        log.warning("Window crosses midnight — not supported in V1. Skipping.")
        return False

    hora_actual = ahora.time().replace(second=0, microsecond=0)
    return hora_inicio <= hora_actual <= hora_fin


def dia_activo(cfg: dict, ahora: datetime) -> bool:
    """Return True if today's weekday is in dias_semana (ISO: Mon=1, Sun=7)."""
    dias_raw = cfg.get("dias_semana")
    if not dias_raw:
        return True
    try:
        dias_activos = [int(d.strip()) for d in str(dias_raw).split(",") if d.strip()]
    except ValueError:
        return True
    return ahora.isoweekday() in dias_activos


def debe_ejecutar(ultimo: Optional[datetime], intervalo_min: int, ahora: datetime) -> bool:
    """Return True if intervalo_min minutes have elapsed since ultimo (or no prior run)."""
    if intervalo_min <= 0:
        log.warning(f"intervalo_min={intervalo_min} <= 0 — fail-safe, skipping")
        return False
    if ultimo is None:
        return True
    return (ahora - ultimo).total_seconds() >= intervalo_min * 60


# ── Lock file ─────────────────────────────────────────────────────────────────

def lock_existe_y_activo(lock_path: Path) -> bool:
    """Return True if lock exists and the recorded PID is alive."""
    if not lock_path.exists():
        return False
    try:
        pid = int(lock_path.read_text(encoding="utf-8").strip())
    except (ValueError, OSError):
        log.warning(f"Corrupt lock at {lock_path} — cleaning up")
        _try_unlink(lock_path)
        return False
    try:
        os.kill(pid, 0)
        return True
    except ProcessLookupError:
        pass
    except PermissionError:
        log.warning(f"Lock PID {pid} no signal permission — assuming active")
        return True
    except OSError:
        log.warning(f"Lock PID {pid} unexpected OS error — assuming active")
        return True
    log.warning(f"Orphan lock (PID {pid} dead) — cleaning up")
    _try_unlink(lock_path)
    return False


def escribir_lock(lock_path: Path) -> None:
    """Write current PID to lock_path."""
    lock_path.write_text(str(os.getpid()), encoding="utf-8")


def limpiar_lock(lock_path: Path) -> None:
    """Remove lock_path if it exists."""
    if lock_path.exists():
        _try_unlink(lock_path)


def _try_unlink(path: Path) -> None:
    try:
        path.unlink()
    except OSError as exc:
        log.warning(f"Could not remove {path}: {exc}")


# ── DB helpers ────────────────────────────────────────────────────────────────

def get_db_connection() -> psycopg2.extensions.connection:
    """Return a psycopg2 connection with exponential backoff retries."""
    db_kwargs = {
        "host": os.getenv("DB_HOST", "localhost"),
        "port": int(os.getenv("DB_PORT", "5432")),
        "user": os.getenv("DB_USER", "postgres"),
        "password": os.getenv("DB_PASSWORD", ""),
        "dbname": os.getenv("DB_NAME", "simo"),
        "connect_timeout": 10,
    }
    last_exc: Optional[Exception] = None
    for attempt, delay in enumerate(_DB_RETRY_DELAYS, start=1):
        try:
            conn = psycopg2.connect(**db_kwargs)
            conn.autocommit = True
            return conn
        except psycopg2.OperationalError as exc:
            last_exc = exc
            log.warning(
                f"DB connection error (attempt {attempt}/{len(_DB_RETRY_DELAYS)}): {exc}. "
                f"Retrying in {delay}s…"
            )
            time.sleep(delay)
    raise last_exc  # type: ignore[misc]


def leer_config_gaceta(conn: psycopg2.extensions.connection) -> Optional[dict]:
    """Read the 'gaceta' row from config_scripts. Returns dict or None."""
    try:
        with conn.cursor(cursor_factory=psycopg2.extras.RealDictCursor) as cur:
            cur.execute(
                "SELECT * FROM config_scripts WHERE script = 'gaceta' LIMIT 1"
            )
            row = cur.fetchone()
            return dict(row) if row else None
    except Exception as exc:
        log.error(f"Error reading config_scripts for gaceta: {exc}")
        return None


def leer_ultimo_ciclo_gaceta(conn: psycopg2.extensions.connection) -> Optional[datetime]:
    """Return the most recent inicio for script='gaceta' in log_scripts, or None."""
    try:
        with conn.cursor(cursor_factory=psycopg2.extras.RealDictCursor) as cur:
            cur.execute(
                "SELECT inicio FROM log_scripts WHERE script = 'gaceta' "
                "ORDER BY inicio DESC LIMIT 1"
            )
            row = cur.fetchone()
            return row["inicio"] if row else None
    except Exception as exc:
        log.error(f"Error reading ultimo_ciclo_gaceta: {exc}")
        return None


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
    """Insert a wrapper row in log_scripts (counts=0 — main.py writes the real counts)."""
    duracion = (fin - inicio).total_seconds()
    estado_db = _ESTADO_DB_MAP.get(estado, "error")
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
                    script, inicio, fin, estado_db,
                    duracion, items_procesados, items_resultado,
                    errores, mensaje_error,
                ),
            )
            conn.commit()
    except Exception as exc:
        log.error(f"Error writing log_scripts for {script}: {exc}")


# ── Subprocess ────────────────────────────────────────────────────────────────

def ejecutar_gaceta(cfg: dict) -> dict:
    """
    Launch gaceta main.py --once as subprocess.

    Returns dict: estado ('ok'|'error'|'timeout'), returncode, duracion, mensaje_error.
    """
    cmd = [_GACETA_PYTHON, str(_GACETA_MAIN), "--once"]
    timeout_seg = int(cfg.get("timeout_minutos", 30)) * 60

    log.info(f"Starting gaceta | cmd: {' '.join(cmd)} | timeout: {timeout_seg // 60}min")

    inicio = datetime.now()
    try:
        proc = subprocess.Popen(
            cmd,
            cwd=str(BASE_DIR),
            stdout=subprocess.DEVNULL,
            stderr=subprocess.PIPE,
        )
        log.info(f"Gaceta PID {proc.pid}")
        try:
            _, stderr_bytes = proc.communicate(timeout=timeout_seg)
        except subprocess.TimeoutExpired:
            log.warning(f"Gaceta exceeded timeout ({cfg.get('timeout_minutos')}min) — terminating")
            proc.terminate()
            try:
                proc.wait(timeout=10)
            except subprocess.TimeoutExpired:
                log.warning("Gaceta did not stop after SIGTERM — sending SIGKILL")
                proc.kill()
            duracion = (datetime.now() - inicio).total_seconds()
            return {
                "estado": "timeout",
                "returncode": -1,
                "duracion": duracion,
                "mensaje_error": f"Timeout after {cfg.get('timeout_minutos')} minutes",
            }

        duracion = (datetime.now() - inicio).total_seconds()
        if proc.returncode == 0:
            log.info(f"Gaceta finished OK in {duracion:.1f}s")
            return {"estado": "ok", "returncode": 0, "duracion": duracion, "mensaje_error": None}
        else:
            msg = (stderr_bytes or b"").decode("utf-8", errors="replace")[:300].strip()
            log.warning(f"Gaceta exit {proc.returncode}: {msg}")
            return {
                "estado": "error",
                "returncode": proc.returncode,
                "duracion": duracion,
                "mensaje_error": msg or None,
            }
    except Exception as exc:
        duracion = (datetime.now() - inicio).total_seconds()
        log.error(f"Error launching gaceta: {exc}", exc_info=True)
        return {"estado": "error", "returncode": -1, "duracion": duracion, "mensaje_error": str(exc)}


# ── Main loop ─────────────────────────────────────────────────────────────────

def main() -> None:
    """Main scheduler loop for the gaceta collector."""
    log.info("=" * 60)
    log.info("Gaceta Runner started")
    log.info(f"  Lock file     : {LOCK_FILE}")
    log.info(f"  Loop interval : {LOOP_INTERVAL}s")
    log.info("=" * 60)

    ultimo_ciclo: Optional[datetime] = None

    # Seed ultimo_ciclo from log_scripts so interval survives restarts
    try:
        conn_seed = get_db_connection()
        try:
            ultimo_ciclo = leer_ultimo_ciclo_gaceta(conn_seed)
            if ultimo_ciclo:
                log.info(f"Last cycle recovered from DB: {ultimo_ciclo}")
            else:
                log.info("No history — first run will execute immediately")
        finally:
            conn_seed.close()
    except Exception as exc:
        log.warning(f"Could not read ultimo_ciclo at startup: {exc} — starting with None")
        ultimo_ciclo = None

    while True:
        conn: Optional[psycopg2.extensions.connection] = None
        try:
            conn = get_db_connection()
            cfg = leer_config_gaceta(conn)

            if not cfg:
                log.warning("No 'gaceta' row in config_scripts — waiting")
                time.sleep(LOOP_INTERVAL)
                continue

            if not cfg.get("habilitado"):
                log.info("Gaceta disabled in config_scripts — waiting")
                time.sleep(LOOP_INTERVAL)
                continue

            ahora = datetime.now()

            if not dia_activo(cfg, ahora):
                log.info(f"Day {ahora.isoweekday()} not active — waiting")
                time.sleep(LOOP_INTERVAL)
                continue

            if not en_ventana_horaria(cfg, ahora):
                log.info("Outside configured time window — waiting")
                time.sleep(LOOP_INTERVAL)
                continue

            intervalo_min = int(cfg.get("intervalo_minutos", 60))
            if not debe_ejecutar(ultimo_ciclo, intervalo_min, ahora):
                time.sleep(LOOP_INTERVAL)
                continue

            if lock_existe_y_activo(LOCK_FILE):
                log.warning("Lock active — gaceta already running, skipping cycle")
                time.sleep(LOOP_INTERVAL)
                continue

            # ── Execute ──────────────────────────────────────────────────────
            escribir_lock(LOCK_FILE)
            inicio = datetime.now()
            log.info(f"CYCLE START — {inicio.strftime('%Y-%m-%d %H:%M:%S')}")

            resultado = ejecutar_gaceta(cfg)

            fin = datetime.now()
            # main.py (_log_run) registra su propia fila con los counts reales en
            # cada ciclo. El runner solo registra cuando el subproceso NO llegó a
            # loguear (error o timeout: fue matado o abortó), para no duplicar filas
            # en el happy path (una ejecución = una fila).
            if resultado["estado"] != "ok":
                registrar_log_scripts(
                    conn,
                    script="gaceta",
                    inicio=inicio,
                    fin=fin,
                    estado=resultado["estado"],
                    items_procesados=0,
                    items_resultado=0,
                    errores=1,
                    mensaje_error=resultado.get("mensaje_error"),
                )
            limpiar_lock(LOCK_FILE)
            ultimo_ciclo = inicio

            log.info(
                f"CYCLE END — estado={resultado['estado']} "
                f"duracion={resultado['duracion']:.1f}s"
            )

        except KeyboardInterrupt:
            log.info("Stopping gaceta runner (Ctrl+C)…")
            limpiar_lock(LOCK_FILE)
            log.info("Runner stopped.")
            break
        except Exception as exc:
            log.error(f"Unexpected error in loop: {exc}", exc_info=True)
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
