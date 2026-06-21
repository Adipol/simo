#!/usr/bin/env python3
"""
gaceta_collector/main.py — One-shot collection cycle.

Usage:
    python main.py [--once] [--pais BO]
    python main.py --backfill [--desde-fecha YYYY-MM-DD] [--pais BO]

Fetches pages from the Bolivia Gaceta Oficial, parses Decreto Presidencial
rows, extracts appointments, and persists to gaceta_normas + gaceta_eventos_pep.
Writes a log_scripts row with the result.

Modes:
  Incremental (default): fetches recent pages, stops at known IDs (cursor).
    Events land as estado_revision='pendiente' for human review.
  Backfill (--backfill): ignores the cursor; paginates back in time until
    norma.fecha_publicacion < desde_fecha (cutoff).  Events are auto-approved
    (estado_revision='aprobado', revisado_at=now, revisado_por=NULL) to avoid
    flooding the human review queue with historical decrees.
"""
import argparse
import logging
import os
import sys
from dataclasses import dataclass
from datetime import date, datetime
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
# tipo_norma_id=11 in Bolivia's system = Decreto Presidencial.
# NOTE: the source serves over plain HTTP only — the legacy server
# (Apache 2.2.4 / Win32 / PHP 5.2.3) exposes no TLS, so HTTPS times out at
# the TCP layer. HTTP is the only working scheme for this public source.
_BOLIVIA_BASE = "http://www.gacetaoficialdebolivia.gob.bo"
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


# ── Public helpers ────────────────────────────────────────────────────────────

def _compute_backfill_cutoff(today: date, years: int) -> date:
    """
    Return the backfill cutoff date: today minus `years` years.

    Handles the Feb-29 edge case gracefully by falling back to Feb-28 when
    the target year is not a leap year.

    Args:
        today: The reference date (usually date.today()).
        years: Number of years to look back.

    Returns:
        A date `years` years before `today`.
    """
    try:
        return today.replace(year=today.year - years)
    except ValueError:
        # today is Feb 29 but target year is not a leap year
        return today.replace(year=today.year - years, day=28)


# ── Public API ────────────────────────────────────────────────────────────────

def run_cycle(
    conn,
    pais: str = "BO",
    max_pages: Optional[int] = None,
    settings: Optional[Settings] = None,
    backfill: bool = False,
    desde_fecha: Optional[date] = None,
) -> RunResult:
    """
    Execute one full collection cycle for `pais`.

    Args:
        conn: Open psycopg2 connection (autocommit or explicit transaction mode).
        pais: ISO-2 country code. Currently only 'BO' is supported.
        max_pages: Override Settings.gaceta.max_pages for this run (forward mode only).
        settings: Optional Settings instance (falls back to defaults).
        backfill: When True, run in backfill mode: ignore the incremental cursor,
            paginate until desde_fecha cutoff, auto-approve events.
        desde_fecha: Backfill stop date.  Normas with fecha_publicacion < desde_fecha
            are skipped and pagination halts.  When None and backfill=True, defaults
            to today minus settings.gaceta.backfill_years.

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

    if backfill:
        _max_pages = settings.gaceta.backfill_max_pages
        if desde_fecha is None:
            desde_fecha = _compute_backfill_cutoff(date.today(), settings.gaceta.backfill_years)
        log.info(
            f"Backfill mode | país={pais} | cutoff={desde_fecha} | max_pages={_max_pages}"
        )
    else:
        _max_pages = max_pages if max_pages is not None else settings.gaceta.max_pages

    client = GacetaClient(settings.gaceta)
    repo = GacetaRepository(conn)
    parser = BoliviaParser()

    inicio = datetime.now()
    result = RunResult(pais=pais)

    try:
        # Incremental cursor — used only in forward mode.
        # In backfill mode we skip the cursor: upsert_norma's ON CONFLICT DO NOTHING
        # handles deduplication safely for re-runs.
        cursor_id = repo.get_ultimo_gaceta_id(pais) if not backfill else None
        log.info(f"Cursor for {pais}: {cursor_id} (None = first run or backfill)")

        stop_pagination = False

        for page_num in range(1, _max_pages + 1):
            url = f"{_BOLIVIA_BASE}{_BOLIVIA_LIST_PATH}?page={page_num}"
            log.info(f"Fetching page {page_num}: {url}")
            html = client.get(url)

            rows = parser.parse_listing(html)
            if not rows:
                log.info(f"No rows on page {page_num} — stopping pagination")
                break

            result.paginas_procesadas += 1
            # Tracks the newest fecha seen on this page (backfill page-granularity stop).
            page_max_fecha = None

            for norma in rows:
                gid = norma["gaceta_id_externo"]

                if not backfill:
                    # Forward/incremental: stop at known IDs
                    if cursor_id is not None and gid <= cursor_id:
                        log.debug(
                            f"Norma {gid} already known (cursor={cursor_id}) — stopping"
                        )
                        stop_pagination = True
                        break
                else:
                    # Backfill: skip individual normas older than the cutoff, but do NOT
                    # stop pagination on a single outlier.  Bolivia's listing is not
                    # strictly date-descending — occasional old decrees appear in otherwise-
                    # recent pages (confirmed: a 2010 decree on an otherwise-2023 page).
                    # Track the page's max fecha for the page-granularity stop below.
                    fecha = norma.get("fecha_publicacion")
                    if fecha is None:
                        log.debug(f"Norma {gid} has no fecha_publicacion — skipping")
                        continue
                    if page_max_fecha is None or fecha > page_max_fecha:
                        page_max_fecha = fecha
                    if fecha < desde_fecha:
                        log.debug(
                            f"Norma {gid} fecha {fecha} < cutoff {desde_fecha} "
                            f"— skipping out-of-order norma (page max will decide stop)"
                        )
                        continue

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
                            auto_aprobar=backfill,
                        )
                        result.eventos_insertados += n_inserted

                    repo.update_estado_extraccion(norma_id, extract_result.estado_extraccion)
                    conn.commit()
                    # Increment only after a successful commit — ensures the counter
                    # never over-reports when the per-norma transaction is rolled back.
                    result.normas_nuevas += 1
                    conn.autocommit = prev_autocommit

                except psycopg2.errors.UniqueViolation:
                    # Treat a unique-constraint collision as a duplicate-skip, NOT a crash.
                    # This covers the (pais, numero_decreto) constraint which is NOT handled
                    # by the ON CONFLICT (pais, gaceta_id_externo) clause in upsert_norma.
                    # Roll back this norma's transaction and continue to the next one.
                    conn.rollback()
                    conn.autocommit = prev_autocommit
                    log.warning(
                        f"Norma {gid} skipped: unique constraint collision "
                        f"(likely numero_decreto duplicate) — treating as duplicate"
                    )
                    continue
                except Exception:
                    conn.rollback()
                    conn.autocommit = prev_autocommit
                    raise

            # Page-granularity backfill stop: once the newest decree on a page is
            # older than the cutoff, every subsequent page is genuinely past the
            # backfill window → stop.  A lone out-of-order outlier on an otherwise-
            # recent page must NOT trigger this stop.
            if backfill and page_max_fecha is not None and page_max_fecha < desde_fecha:
                log.info(
                    f"Page {page_num} max fecha {page_max_fecha} < cutoff {desde_fecha} "
                    f"— stopping backfill (entire page is older than window)"
                )
                stop_pagination = True

            if stop_pagination:
                break

        log.info(
            f"{'Backfill' if backfill else 'Cycle'} done: {result.normas_nuevas} new normas, "
            f"{result.eventos_insertados} eventos, "
            f"{result.paginas_procesadas} pages"
        )

    except Exception as exc:
        log.error(f"Cycle error: {exc}", exc_info=True)
        result.estado = "error"
        result.mensaje_error = str(exc)

    fin = datetime.now()
    script_name = "gaceta_backfill" if backfill else "gaceta"
    _log_run(conn, inicio=inicio, fin=fin, result=result, script=script_name)
    return result


# ── Private helpers ───────────────────────────────────────────────────────────

def _log_run(
    conn,
    inicio: datetime,
    fin: datetime,
    result: RunResult,
    script: str = "gaceta",
) -> None:
    """
    Write a log_scripts row for this cycle.
    Maps run result to log_scripts.estado allowed values:
      ok → completado, error → error

    Args:
        script: Script name tag for the log row. Use 'gaceta' for incremental
            runs and 'gaceta_backfill' for historical backfill runs so operators
            can distinguish them in the logs.
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
                    script,
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
        description="Gaceta Oficial collector — one-shot cycle",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog=(
            "Examples:\n"
            "  # Forward incremental (default):\n"
            "  python main.py --once --pais BO\n\n"
            "  # Historical backfill (default cutoff = today - GACETA_BACKFILL_YEARS):\n"
            "  python main.py --backfill --pais BO\n\n"
            "  # Backfill with explicit cutoff:\n"
            "  python main.py --backfill --desde-fecha 2024-01-01 --pais BO\n"
        ),
    )
    parser.add_argument(
        "--once", action="store_true", help="Run once and exit (default behavior)"
    )
    parser.add_argument("--pais", default="BO", help="Country code (default: BO)")
    parser.add_argument(
        "--backfill",
        action="store_true",
        help=(
            "Run historical backfill instead of incremental collection. "
            "Events are auto-approved (estado_revision=aprobado) to avoid "
            "flooding the human review queue."
        ),
    )
    parser.add_argument(
        "--desde-fecha",
        dest="desde_fecha",
        metavar="YYYY-MM-DD",
        help=(
            "Backfill cutoff date (inclusive start of history to collect). "
            "Normas published before this date are not collected. "
            "Defaults to today minus GACETA_BACKFILL_YEARS (env var, default 5 years)."
        ),
    )
    args = parser.parse_args()

    # Parse optional --desde-fecha argument
    desde_fecha: Optional[date] = None
    if args.desde_fecha:
        try:
            desde_fecha = date.fromisoformat(args.desde_fecha)
        except ValueError as exc:
            log.error(f"Invalid --desde-fecha value '{args.desde_fecha}': {exc}")
            sys.exit(1)

    settings = Settings()
    log.info("=" * 60)
    log.info("Gaceta Collector starting")
    log.info(f"  País     : {args.pais}")
    log.info(f"  Mode     : {'backfill' if args.backfill else 'incremental'}")
    if args.backfill:
        log.info(f"  MaxPag   : {settings.gaceta.backfill_max_pages} (backfill cap)")
        log.info(f"  DesdeFecha: {desde_fecha or f'today - {settings.gaceta.backfill_years}y'}")
    else:
        log.info(f"  MaxPag   : {settings.gaceta.max_pages}")
    log.info("=" * 60)

    try:
        conn = psycopg2.connect(**settings.db.as_psycopg2_kwargs())
        conn.autocommit = True
    except Exception as exc:
        log.error(f"Cannot connect to database: {exc}")
        sys.exit(1)

    try:
        result = run_cycle(
            conn=conn,
            pais=args.pais,
            settings=settings,
            backfill=args.backfill,
            desde_fecha=desde_fecha,
        )
        log.info(f"Done: {result}")
        sys.exit(0 if result.estado == "ok" else 1)
    finally:
        conn.close()


if __name__ == "__main__":
    main()
