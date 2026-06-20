"""
Bolivia driver — HTML listing parser.

Parses the norma listing from:
  http://www.gacetaoficialdebolivia.gob.bo/normas/listadonor/11

The site uses a Bootstrap card layout (Drupal 10). There is NO table with
id="tNormas". Each decree is rendered as a <div class="card-body"> with:
  - <p class="card-text"> for edition and publication date
  - <h6><b>Decreto Presidencial N° 5637</b></h6> for tipo and number
  - <div class="contentpaneopen"> for sumario text
  - <div class="card-footer"> for download/view links (gaceta_id_externo lives here)

Returns ONLY rows with tipo_norma == 'Decreto Presidencial'.
gaceta_id_externo is the numeric id from /normas/descargarNrms/{id}.
pdf_url is the absolute URL for that same link.
Cards without a /normas/descargarNrms/ link are skipped (no id = cannot dedup).
pais is always 'BO'.

Date format: YYYY-MM-DD (the site changed from DD/MM/YYYY; old format is rejected).
"""
import re
from datetime import date
from typing import Optional

from bs4 import BeautifulSoup

# ── constants ────────────────────────────────────────────────────────────────

_TIPO_NORMA_FILTER = "Decreto Presidencial"

# Matches "Decreto Presidencial N° 5637" (degree sign U+00B0 or ordinal º U+00BA)
_RE_NUMERO = re.compile(r"Decreto Presidencial\s+N[°º]\s*(\d+)", re.IGNORECASE)

# Matches "Fecha de Publicación: 2026-06-20"
_RE_FECHA = re.compile(r"Fecha\s+de\s+Publicaci[oó]n[:\s]+(\d{4}-\d{2}-\d{2})")

# Matches /normas/descargarNrms/{id} or /normas/verGratis_gob/{id} or verGratis_gob1/{id}
_RE_NORMA_ID = re.compile(
    r"/normas/(?:descargarNrms|verGratis_gob1?)/(\d+)",
    re.IGNORECASE,
)

_BASE_URL = "http://www.gacetaoficialdebolivia.gob.bo"


class BoliviaParser:
    """
    Parser for the Bolivia Gaceta Oficial norma listing.

    Country-specific; returns dicts ready for GacetaRepository.upsert_norma().
    """

    PAIS = "BO"

    def parse_listing(self, html: str) -> list:
        """
        Parse HTML from the norma listing page.

        Returns a list of norma dicts — only Decreto Presidencial cards.
        Each dict contains:
          pais, gaceta_id_externo, numero_decreto, tipo_norma,
          sumario, pdf_url, fecha_publicacion, edicion, estado_extraccion
        """
        soup = BeautifulSoup(html, "lxml")
        rows = []
        for card_body in soup.find_all("div", class_="card-body"):
            norma = self._parse_card(card_body)
            if norma is not None:
                rows.append(norma)
        return rows

    # ── private ──────────────────────────────────────────────────────────────

    def _parse_card(self, card_body) -> Optional[dict]:
        """Parse a single card-body div and return a norma dict, or None to skip."""

        # 1. Find the title in <h6>
        h6 = card_body.find("h6")
        if h6 is None:
            return None
        title = h6.get_text(" ", strip=True)

        # 2. Filter: keep only Decreto Presidencial
        if not title.startswith(_TIPO_NORMA_FILTER):
            return None

        # 3. Extract numero_decreto (digits after "N°")
        m_numero = _RE_NUMERO.search(title)
        if m_numero is None:
            return None
        numero_decreto = m_numero.group(1)

        # 4. Extract edicion and fecha_publicacion from <p class="card-text">
        card_text_p = card_body.find("p", class_="card-text")
        edicion: Optional[str] = None
        fecha_publicacion: Optional[date] = None
        if card_text_p is not None:
            edicion_anchor = card_text_p.find("a")
            if edicion_anchor is not None:
                edicion = edicion_anchor.get_text(strip=True) or None
            card_text_str = card_text_p.get_text(" ", strip=True)
            m_fecha = _RE_FECHA.search(card_text_str)
            if m_fecha is not None:
                fecha_publicacion = _parse_date(m_fecha.group(1))

        # 5. Extract sumario from <div class="contentpaneopen">
        content_div = card_body.find("div", class_="contentpaneopen")
        sumario: Optional[str] = None
        if content_div is not None:
            raw = content_div.get_text(" ", strip=True)
            collapsed = re.sub(r"\s+", " ", raw).strip()
            sumario = collapsed if collapsed else None

        # 6. Extract gaceta_id_externo and pdf_url from the card footer
        parent_card = card_body.find_parent("div", class_="card")
        if parent_card is None:
            return None
        footer = parent_card.find("div", class_="card-footer")
        if footer is None:
            return None

        gaceta_id_externo: Optional[int] = None
        pdf_url: Optional[str] = None
        for anchor in footer.find_all("a"):
            href = anchor.get("href", "")
            m_id = _RE_NORMA_ID.search(href)
            if m_id is None:
                continue
            extracted_id = int(m_id.group(1))
            if gaceta_id_externo is None:
                gaceta_id_externo = extracted_id
            if "descargarNrms" in href and pdf_url is None:
                pdf_url = _BASE_URL + href

        # Skip cards without a parseable id (cannot dedup without gaceta_id_externo)
        if gaceta_id_externo is None:
            return None

        return {
            "pais": self.PAIS,
            "gaceta_id_externo": gaceta_id_externo,
            "numero_decreto": numero_decreto,
            "tipo_norma": _TIPO_NORMA_FILTER,
            "edicion": edicion,
            "fecha_publicacion": fecha_publicacion,
            "sumario": sumario,
            "pdf_url": pdf_url,
            "estado_extraccion": "pendiente",
        }


def _parse_date(date_str: str) -> Optional[date]:
    """Parse 'YYYY-MM-DD' into a Python date, or None if unparseable.

    The Bolivia Gaceta Oficial site uses YYYY-MM-DD format.
    The old DD/MM/YYYY format (used by the fabricated fixture) is NOT accepted.
    """
    if not date_str:
        return None
    try:
        year, month, day = date_str.strip().split("-")
        return date(int(year), int(month), int(day))
    except (ValueError, AttributeError):
        return None
