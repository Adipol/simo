"""
runner.py — Orquestador de scripts SIMO
========================================
Ciclo:
  1. Lanza pep_monitor  (espera que termine)
  2. Lanza scraper con --loop-paises --espera 0 --once
     (recorre: BO/PEP → BO/OPI → HN/PEP → HN/OPI → ... y termina)
  3. Espera el intervalo configurado en config_scripts para scraper
  4. Vuelve a 1.

Uso:
    py runner.py

Para detener: Ctrl+C
"""

import os
import sys
import time
import subprocess
import logging
from datetime import datetime, time as dtime
from pathlib import Path

import mysql.connector
from dotenv import load_dotenv

# ---------------------------------------------------------------------------
# Configuracion base
# ---------------------------------------------------------------------------
BASE_DIR = Path(__file__).parent
SCRAPER_DIR = Path(r"D:\proyectos\scraper_v2.2")

load_dotenv(BASE_DIR / ".env")

DB_CONFIG = {
    "host": os.getenv("DB_HOST", "localhost"),
    "port": int(os.getenv("DB_PORT", 3306)),
    "user": os.getenv("DB_USER", "root"),
    "password": os.getenv("DB_PASSWORD", ""),
    "database": os.getenv("DB_NAME", "monitor_app"),
}

# Intervalo de revision del loop principal (segundos)
LOOP_INTERVAL = 30

# ---------------------------------------------------------------------------
# Logging
# ---------------------------------------------------------------------------
logging.basicConfig(
    level=logging.INFO,
    format="[%(asctime)s] %(levelname)-7s | runner | %(message)s",
    datefmt="%Y-%m-%d %H:%M:%S",
    handlers=[
        logging.StreamHandler(sys.stdout),
        logging.FileHandler(BASE_DIR / "runner.log", encoding="utf-8"),
    ],
)
log = logging.getLogger("runner")


# ---------------------------------------------------------------------------
# BD helpers
# ---------------------------------------------------------------------------
def get_config(script: str) -> dict | None:
    """Lee la config de un script desde config_scripts."""
    try:
        conn = mysql.connector.connect(**DB_CONFIG)
        cur = conn.cursor(dictionary=True)
        cur.execute("SELECT * FROM config_scripts WHERE script = %s LIMIT 1", (script,))
        row = cur.fetchone()
        cur.close()
        conn.close()
        return row
    except Exception as e:
        log.error(f"Error leyendo config de {script}: {e}")
        return None


# ---------------------------------------------------------------------------
# Logica de horario
# ---------------------------------------------------------------------------
def en_ventana_horaria(cfg: dict) -> bool:
    """Devuelve True si ahora esta dentro del horario configurado."""
    ahora = datetime.now()

    dias_activos = [int(d) for d in str(cfg["dias_semana"]).split(",") if d.strip()]
    if ahora.isoweekday() not in dias_activos:
        return False

    hora_ini = cfg.get("hora_inicio")
    hora_fin = cfg.get("hora_fin")

    if hora_ini and hora_fin:
        if hasattr(hora_ini, "seconds"):
            s = int(hora_ini.total_seconds())
            hora_ini = dtime(s // 3600, (s % 3600) // 60)
        if hasattr(hora_fin, "seconds"):
            s = int(hora_fin.total_seconds())
            hora_fin = dtime(s // 3600, (s % 3600) // 60)
        hora_actual = ahora.time().replace(second=0, microsecond=0)
        if not (hora_ini <= hora_actual <= hora_fin):
            return False

    return True


def debe_ejecutar_ciclo(ultimo_inicio: datetime | None, intervalo_min: int) -> bool:
    """Devuelve True si ya paso el intervalo desde el ultimo ciclo completo."""
    if ultimo_inicio is None:
        return True
    return (datetime.now() - ultimo_inicio).total_seconds() >= intervalo_min * 60


# ---------------------------------------------------------------------------
# Ejecucion bloqueante de un script (espera que termine o timeout)
# ---------------------------------------------------------------------------
def ejecutar_y_esperar(script: str) -> bool:
    """
    Lanza el script y BLOQUEA hasta que termine o supere el timeout.
    Devuelve True si termino con exit 0.
    """
    cfg = get_config(script)
    if not cfg:
        log.error(f"No se encontro config para {script}")
        return False

    if not cfg["habilitado"]:
        log.info(f"{script} deshabilitado — omitiendo")
        return True

    if not en_ventana_horaria(cfg):
        log.info(f"{script} fuera de ventana horaria — omitiendo")
        return True

    timeout_seg = int(cfg["timeout_minutos"]) * 60

    if script == "scraper":
        # --once --pais todos: ejecuta cada pais x cada categoria (PEP→OPI) y termina
        cmd = [
            sys.executable,
            str(SCRAPER_DIR / "main.py"),
            "--once",
            "--pais",
            "todos",
        ]
        cwd = str(SCRAPER_DIR)
    else:  # pep_monitor
        cmd = [sys.executable, str(BASE_DIR / "pep_monitor.py"), "check"]
        cwd = str(BASE_DIR)

    log.info(f"Iniciando {script} | cmd: {' '.join(cmd)}")
    try:
        proc = subprocess.Popen(
            cmd,
            cwd=cwd,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            text=True,
            encoding="utf-8",
            errors="replace",
        )
        log.info(f"{script} PID {proc.pid} | timeout: {timeout_seg // 60} min")

        try:
            stdout, _ = proc.communicate(timeout=timeout_seg)
        except subprocess.TimeoutExpired:
            log.warning(
                f"{script} supero timeout de {cfg['timeout_minutos']} min — terminando"
            )
            proc.terminate()
            try:
                proc.wait(timeout=10)
            except subprocess.TimeoutExpired:
                proc.kill()
            return False

        if proc.returncode == 0:
            log.info(f"{script} finalizo correctamente (exit 0)")
            return True
        else:
            salida = (stdout or "")[:300].strip()
            log.warning(
                f"{script} finalizo con exit {proc.returncode}. Salida: {salida}"
            )
            return False

    except Exception as e:
        log.error(f"Error al lanzar {script}: {e}")
        return False


# ---------------------------------------------------------------------------
# Loop principal
# ---------------------------------------------------------------------------
def main() -> None:
    log.info("=" * 60)
    log.info("SIMO Runner iniciado")
    log.info(f"  Scraper dir : {SCRAPER_DIR}")
    log.info(f"  PEP dir     : {BASE_DIR}")
    log.info(f"  Ciclo       : pep_monitor → scraper → espera → repite")
    log.info(f"  Loop check  : {LOOP_INTERVAL}s")
    log.info("  Para detener: Ctrl+C")
    log.info("=" * 60)

    ultimo_ciclo: datetime | None = None

    while True:
        try:
            cfg_scraper = get_config("scraper")
            intervalo_min = int(cfg_scraper["intervalo_minutos"]) if cfg_scraper else 60

            if not debe_ejecutar_ciclo(ultimo_ciclo, intervalo_min):
                elapsed = (
                    (datetime.now() - ultimo_ciclo).total_seconds()
                    if ultimo_ciclo
                    else 0
                )
                seg_restantes = int(intervalo_min * 60 - elapsed)
                log.debug(
                    f"Esperando ciclo — {seg_restantes // 60}m {seg_restantes % 60}s restantes"
                )
                time.sleep(LOOP_INTERVAL)
                continue

            # Verificar que el scraper este habilitado y en ventana horaria
            if cfg_scraper and (
                not cfg_scraper["habilitado"] or not en_ventana_horaria(cfg_scraper)
            ):
                log.info("Scraper deshabilitado o fuera de ventana — esperando")
                time.sleep(LOOP_INTERVAL)
                continue

            log.info("=" * 60)
            log.info(f"NUEVO CICLO — {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
            log.info("=" * 60)

            # PASO 1: PEP Monitor (bloqueante)
            log.info("--- PASO 1: PEP Monitor ---")
            ejecutar_y_esperar("pep_monitor")

            # PASO 2: Scraper (bloqueante, PEP→OPI por pais)
            log.info("--- PASO 2: Scraper (PEP→OPI por pais) ---")
            ejecutar_y_esperar("scraper")

            ultimo_ciclo = datetime.now()
            log.info(f"Ciclo completo. Proximo ciclo en {intervalo_min} min")

        except KeyboardInterrupt:
            log.info("Deteniendo runner (Ctrl+C)...")
            log.info("Runner detenido.")
            break
        except Exception as e:
            log.error(f"Error inesperado en loop: {e}", exc_info=True)
            time.sleep(LOOP_INTERVAL)


if __name__ == "__main__":
    main()
