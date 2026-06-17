"""
Bolivia driver — HTML listing parser.

Parses the norma listing from:
  https://www.gacetaoficialdebolivia.gob.bo/normas/listadonor/11

Returns ONLY rows with tipo_norma == 'Decreto Presidencial'.
gaceta_id_externo is extracted from the href of the norma link.
pais is always 'BO'.
"""
import re
from datetime import date
from typing import Optional

from bs4 import BeautifulSoup

# The column index order in the listing table:
# 0=N°, 1=Número de Decreto (link), 2=Tipo de Norma, 3=Fecha, 4=Edición, 5=Sumario, 6=PDF link
_COL_NUMERO = 1
_COL_TIPO = 2
_COL_FECHA = 3
_COL_EDICION = 4
_COL_SUMARIO = 5
_COL_PDF = 6

_TIPO_NORMA_FILTER = "Decreto Presidencial"

# Extract numeric ID from URL like /normas/textonormaRE/180123
_RE_NORMA_ID = re.compile(r"/normas/textonormaRE/(\d+)", re.IGNORECASE)


class BoliviaParser:
    """
    Parser for the Bolivia Gaceta Oficial norma listing.

    Country-specific; returns dicts ready for GacetaRepository.upsert_norma().
    """

    PAIS = "BO"

    def parse_listing(self, html: str) -> list:
        """
        Parse HTML from the norma listing page.

        Returns a list of norma dicts — only Decreto Presidencial rows.
        Each dict contains:
          pais, gaceta_id_externo, numero_decreto, tipo_norma,
          sumario, pdf_url, fecha_publicacion, edicion, estado_extraccion
        """
        soup = BeautifulSoup(html, "lxml")
        table = soup.find("table", id="tNormas")
        if table is None:
            return []

        tbody = table.find("tbody")
        if tbody is None:
            return []

        rows = []
        for tr in tbody.find_all("tr"):
            norma = self._parse_row(tr)
            if norma is not None:
                rows.append(norma)
        return rows

    # ── private ──────────────────────────────────────────────────────────────

    def _parse_row(self, tr) -> Optional[dict]:
        """Parse a single <tr> and return a norma dict, or None if it should be skipped."""
        cells = tr.find_all("td")
        if len(cells) < 7:
            return None

        tipo_norma = cells[_COL_TIPO].get_text(strip=True)
        if tipo_norma != _TIPO_NORMA_FILTER:
            return None

        # Extract gaceta_id_externo from the numero link href
        numero_cell = cells[_COL_NUMERO]
        link = numero_cell.find("a")
        if link is None:
            return None
        href = link.get("href", "")
        id_match = _RE_NORMA_ID.search(href)
        if id_match is None:
            return None
        gaceta_id_externo = int(id_match.group(1))

        numero_decreto = link.get_text(strip=True)
        sumario = cells[_COL_SUMARIO].get_text(strip=True)
        edicion = cells[_COL_EDICION].get_text(strip=True) or None

        fecha_publicacion = _parse_date(cells[_COL_FECHA].get_text(strip=True))

        pdf_url: Optional[str] = None
        pdf_cell = cells[_COL_PDF]
        pdf_link = pdf_cell.find("a")
        if pdf_link:
            pdf_url = pdf_link.get("href")

        return {
            "pais": self.PAIS,
            "gaceta_id_externo": gaceta_id_externo,
            "numero_decreto": numero_decreto,
            "tipo_norma": tipo_norma,
            "sumario": sumario,
            "pdf_url": pdf_url,
            "fecha_publicacion": fecha_publicacion,
            "edicion": edicion,
            "estado_extraccion": "pendiente",
        }


def _parse_date(date_str: str) -> Optional[date]:
    """Parse 'DD/MM/YYYY' into a Python date, or None if unparseable."""
    if not date_str:
        return None
    try:
        day, month, year = date_str.strip().split("/")
        return date(int(year), int(month), int(day))
    except (ValueError, AttributeError):
        return None
