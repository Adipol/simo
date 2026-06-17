#!/usr/bin/env python3
"""
gaceta_collector/main.py — One-shot collection cycle.

Usage:
    python main.py [--once] [--pais BO]

Fetches pages from the Bolivia Gaceta Oficial, parses Decreto Presidencial
rows, extracts appointments, and persists to gaceta_normas + gaceta_eventos_pep.
Writes a log_scripts row with the result.
"""
import argparse
import logging
import os
import sys
from dataclasses import dataclass
from datetime import datetime
from pathlib import Path
from typing import Optional

from dotenv import load_dotenv

# ── Dual .env loading (mirrors website_monitor_pro/runner.py) ────────────────
_SCRIPT_DIR = Path(__file__).resolve().parent
_DOTENV_LOCAL = _SCRIPT_DIR / ".env"
_LARAVEL_ROOT = _SCRIPT_DIR.parent.parent
_DOTENV_LARAVEL = _LARAVEL_ROOT / ".env"

if _DOTENV_LOCAL.is_file():
    load_dotenv(dotenv_path=_DOTENV_LOCAL)
if _DOTENV_LARAVEL.is_file():
    load_dotenv(dotenv_path=_DOTENV_LARAVEL, override=False)

# ── Deferred imports (after .env loaded) ─────────────────────────────────────
import psycopg2

from config.settings import Settings
from core.client import GacetaClient
from core.database import GacetaRepository
from core.extractor import extract_eventos
from drivers.bolivia.parser import BoliviaParser

# ── Logging ───────────────────────────────────────────────────────────────────
logging.basicConfig(
    level=logging.INFO,
    format="[%(asctime)s] %(levelname)-7s | gaceta | %(message)s",
    datefmt="%Y-%m-%d %H:%M:%S",
)
log = logging.getLogger("gaceta.main")

# ── Bolivia base URL ──────────────────────────────────────────────────────────
# tipo_norma_id=11 in Bolivia's system = Decreto Presidencial
_BOLIVIA_BASE = "https://www.gacetaoficialdebolivia.gob.bo"
_BOLIVIA_LIST_PATH = "/normas/listadonor/11"


# ── Data model ────────────────────────────────────────────────────────────────

@dataclass
class RunResult:
    """Outcome of a single collection cycle."""

    pais: str
    normas_nuevas: int = 0
    eventos_insertados: int = 0
    paginas_procesadas: int = 0
    estado: str = "ok"
    mensaje_error: Optional[str] = None


# ── Public API ────────────────────────────────────────────────────────────────

def run_cycle(
    conn,
    pais: str = "BO",
    max_pages: Optional[int] = None,
    settings: Optional[Settings] = None,
) -> RunResult:
    """
    Execute one full collection cycle for `pais`.

    Args:
        conn: Open psycopg2 connection (autocommit or explicit transaction mode).
        pais: ISO-2 country code. Currently only 'BO' is supported.
        max_pages: Override Settings.gaceta.max_pages for this run.
        settings: Optional Settings instance (falls back to defaults).

    Returns:
        RunResult with counts.

    Per-norma atomicity: upsert_norma + insert_eventos + update_estado_extraccion
    are wrapped in a single transaction per norma.  If the process is killed or
    an exception occurs mid-norma, the entire norma write is rolled back — no
    partial event sets are ever committed.  The connection is briefly set to
    non-autocommit for the duration of the three writes, then restored.
    """
    if settings is None:
        settings = Settings()

    _max_pages = max_pages if max_pages is not None else settings.gaceta.max_pages

    client = GacetaClient(settings.gaceta)
    repo = GacetaRepository(conn)
    parser = BoliviaParser()

    inicio = datetime.now()
    result = RunResult(pais=pais)

    try:
        # Incremental cursor
        cursor_id = repo.get_ultimo_gaceta_id(pais)
        log.info(f"Cursor for {pais}: {cursor_id} (None = first run)")

        for page_num in range(1, _max_pages + 1):
            url = f"{_BOLIVIA_BASE}{_BOLIVIA_LIST_PATH}?page={page_num}"
            log.info(f"Fetching page {page_num}: {url}")
            html = client.get(url)

            rows = parser.parse_listing(html)
            if not rows:
                log.info(f"No rows on page {page_num} — stopping pagination")
                break

            result.paginas_procesadas += 1
            reached_known = False

            for norma in rows:
                gid = norma["gaceta_id_externo"]

                # Skip known normas (incremental)
                if cursor_id is not None and gid <= cursor_id:
                    log.debug(f"Norma {gid} already known (cursor={cursor_id}) — stopping")
                    reached_known = True
                    break

                # Extract events from sumario (pure function — outside the DB transaction)
                extract_result = extract_eventos(norma.get("sumario", ""))

                # Atomic per-norma persistence: all three writes commit together or
                # not at all.  Temporarily disable autocommit so that upsert_norma,
                # insert_eventos, and update_estado_extraccion share one transaction.
                # On any exception the transaction is rolled back and re-raised so
                # the outer error handler can record the failure in log_scripts.
                prev_autocommit = conn.autocommit
                try:
                    conn.autocommit = False

                    norma_id = repo.upsert_norma(norma)
                    if norma_id is None:
                        # Row already exists — rollback the no-op transaction and skip.
                        conn.rollback()
                        conn.autocommit = prev_autocommit
                        log.debug(f"Norma {gid} duplicate — skipping events")
                        continue

                    if extract_result.eventos:
                        n_inserted = repo.insert_eventos(
                            norma_id=norma_id,
                            pais=norma["pais"],
                            eventos=extract_result.eventos,
                        )
                        result.eventos_insertados += n_inserted

                    repo.update_estado_extraccion(norma_id, extract_result.estado_extraccion)
                    conn.commit()
                    # Increment only after a successful commit — ensures the counter
                    # never over-reports when the per-norma transaction is rolled back.
                    result.normas_nuevas += 1
                    conn.autocommit = prev_autocommit

                except Exception:
                    conn.rollback()
                    conn.autocommit = prev_autocommit
                    raise

            if reached_known:
                break

        log.info(
            f"Cycle done: {result.normas_nuevas} new normas, "
            f"{result.eventos_insertados} eventos, "
            f"{result.paginas_procesadas} pages"
        )

    except Exception as exc:
        log.error(f"Cycle error: {exc}", exc_info=True)
        result.estado = "error"
        result.mensaje_error = str(exc)

    fin = datetime.now()
    _log_run(conn, inicio=inicio, fin=fin, result=result)
    return result


# ── Private helpers ───────────────────────────────────────────────────────────

def _log_run(conn, inicio: datetime, fin: datetime, result: RunResult) -> None:
    """
    Write a log_scripts row for this cycle.
    Maps run result to log_scripts.estado allowed values:
      ok → completado, error → error
    """
    estado_db = "completado" if result.estado == "ok" else "error"
    duracion = (fin - inicio).total_seconds()
    msg = result.mensaje_error

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
                    "gaceta",
                    inicio,
                    fin,
                    estado_db,
                    duracion,
                    result.normas_nuevas,
                    result.eventos_insertados,
                    1 if result.estado != "ok" else 0,
                    msg[:500] if msg else None,
                ),
            )
        conn.commit()
    except Exception as exc:
        log.warning(f"Could not write log_scripts row: {exc}")


# ── Entry point ───────────────────────────────────────────────────────────────

def main() -> None:
    """CLI entry point for one-shot gaceta collection."""
    parser = argparse.ArgumentParser(
        description="Gaceta Oficial collector — one-shot cycle"
    )
    parser.add_argument("--once", action="store_true", help="Run once and exit (default behavior)")
    parser.add_argument("--pais", default="BO", help="Country code (default: BO)")
    args = parser.parse_args()

    settings = Settings()
    log.info("=" * 60)
    log.info("Gaceta Collector starting")
    log.info(f"  País   : {args.pais}")
    log.info(f"  MaxPag : {settings.gaceta.max_pages}")
    log.info("=" * 60)

    try:
        conn = psycopg2.connect(**settings.db.as_psycopg2_kwargs())
        conn.autocommit = True
    except Exception as exc:
        log.error(f"Cannot connect to database: {exc}")
        sys.exit(1)

    try:
        result = run_cycle(conn=conn, pais=args.pais, settings=settings)
        log.info(f"Done: {result}")
        sys.exit(0 if result.estado == "ok" else 1)
    finally:
        conn.close()


if __name__ == "__main__":
    main()
