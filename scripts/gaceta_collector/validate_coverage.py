#!/usr/bin/env python3
"""
validate_coverage.py — TOOL (not a committed test).

Measures real extractor coverage over live gazette pages.

Usage:
    .venv/bin/python validate_coverage.py [--pages N] [--delay MS]

Fetches the first N pages from Bolivia's Gaceta Oficial
(http://www.gacetaoficialdebolivia.gob.bo/normas/listadonor/11),
runs the parser + new extractor on every Decreto Presidencial, then prints
coverage stats per bucket:

  procesado         — >=1 appointment fully extracted
  requiere_detalle  — designation verb found but no individual name (bulk)
  requiere_revision — no designation verb / non-appointment / incomplete

Target: ~85% procesado on appointment decrees; rest legitimately to human review.

Exit code: 0 always (tool, not gate).
"""
import argparse
import sys
import time
import random
from pathlib import Path

# Make the package importable without installing it.
_ROOT = Path(__file__).resolve().parent
if str(_ROOT) not in sys.path:
    sys.path.insert(0, str(_ROOT))

import requests
from drivers.bolivia.parser import BoliviaParser
from core.extractor import extract_eventos, ESTADO_PROCESADO, ESTADO_REQUIERE_DETALLE, ESTADO_REQUIERE_REVISION

_BASE_URL = "http://www.gacetaoficialdebolivia.gob.bo"
_LIST_PATH = "/normas/listadonor/11"
_USER_AGENT = "Mozilla/5.0 (compatible; SIMO-CoverageValidator/1.0)"


def fetch_page(session: requests.Session, page: int, delay_ms: int) -> str:
    """Fetch a single listing page with throttling."""
    if page > 1:
        time.sleep(random.uniform(delay_ms / 1000.0, delay_ms * 1.5 / 1000.0))
    url = f"{_BASE_URL}{_LIST_PATH}?page={page}"
    resp = session.get(url, headers={"User-Agent": _USER_AGENT}, timeout=30)
    resp.raise_for_status()
    resp.encoding = "utf-8"
    return resp.text


def run(pages: int = 5, delay_ms: int = 1500) -> None:
    parser = BoliviaParser()
    session = requests.Session()

    buckets: dict[str, list] = {
        ESTADO_PROCESADO: [],
        ESTADO_REQUIERE_DETALLE: [],
        ESTADO_REQUIERE_REVISION: [],
    }
    total = 0

    print(f"\n{'='*70}")
    print(f"  SIMO Gaceta Extractor — Coverage Validation ({pages} pages)")
    print(f"{'='*70}\n")

    for page_num in range(1, pages + 1):
        print(f"  Fetching page {page_num} ...", end=" ", flush=True)
        try:
            html = fetch_page(session, page_num, delay_ms)
        except Exception as exc:
            print(f"ERROR: {exc}")
            break

        normas = parser.parse_listing(html)
        if not normas:
            print(f"(empty — stopping)")
            break
        print(f"{len(normas)} decrees")

        for norma in normas:
            sumario = norma.get("sumario") or ""
            if not sumario.strip():
                continue
            result = extract_eventos(sumario)
            buckets[result.estado_extraccion].append({
                "sumario": sumario,
                "eventos": result.eventos,
                "numero": norma.get("numero_decreto", "?"),
                "fecha": norma.get("fecha_publicacion", "?"),
            })
            total += 1

    # ── Summary ────────────────────────────────────────────────────────────────

    n_proc = len(buckets[ESTADO_PROCESADO])
    n_det  = len(buckets[ESTADO_REQUIERE_DETALLE])
    n_rev  = len(buckets[ESTADO_REQUIERE_REVISION])

    print(f"\n{'─'*70}")
    print(f"  RESULTS  (total Decreto Presidencial with sumario: {total})")
    print(f"{'─'*70}")
    pct = lambda n: f"{n / total * 100:.1f}%" if total else "—"
    print(f"  procesado         : {n_proc:4d}  ({pct(n_proc)})")
    print(f"  requiere_detalle  : {n_det:4d}  ({pct(n_det)})")
    print(f"  requiere_revision : {n_rev:4d}  ({pct(n_rev)})")
    print(f"{'─'*70}")

    # ── Sample extractions — procesado ────────────────────────────────────────

    print(f"\n  SAMPLE EXTRACTIONS — procesado (first 5):")
    for item in buckets[ESTADO_PROCESADO][:5]:
        print(f"\n  Decreto {item['numero']}  [{item['fecha']}]")
        print(f"  Sumario: {item['sumario'][:100]}{'...' if len(item['sumario']) > 100 else ''}")
        for ev in item["eventos"]:
            interino_tag = " [INTERINO]" if ev["interino"] else ""
            print(f"    -> {ev['persona_nombre']}{interino_tag}  |  {ev['cargo']}")

    # ── Sample — requiere_detalle ─────────────────────────────────────────────

    print(f"\n  SAMPLE — requiere_detalle (first 3 — bulk, no individual name):")
    for item in buckets[ESTADO_REQUIERE_DETALLE][:3]:
        print(f"  Decreto {item['numero']}  [{item['fecha']}]")
        print(f"  Sumario: {item['sumario'][:120]}")

    # ── Sample — requiere_revision ────────────────────────────────────────────

    print(f"\n  SAMPLE — requiere_revision (first 5 — check for false negatives):")
    for item in buckets[ESTADO_REQUIERE_REVISION][:5]:
        print(f"  Decreto {item['numero']}  [{item['fecha']}]")
        print(f"  Sumario: {item['sumario'][:120]}")

    print(f"\n{'='*70}\n")


def main() -> None:
    ap = argparse.ArgumentParser(description="Validate gaceta extractor coverage against live data.")
    ap.add_argument("--pages", type=int, default=5, help="Number of listing pages to fetch (default: 5)")
    ap.add_argument("--delay", type=int, default=1500, help="Base delay between requests in ms (default: 1500)")
    args = ap.parse_args()
    run(pages=args.pages, delay_ms=args.delay)


if __name__ == "__main__":
    main()
