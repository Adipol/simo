"""
Tests for drivers/bolivia/parser.py — Strict TDD RED written first.

BoliviaParser must:
- Parse the Bootstrap card-based listing HTML (Drupal 10 site — no table, no tNormas)
- Filter to ONLY Decreto Presidencial cards (tipo_norma check in <h6>)
- Extract gaceta_id_externo from /normas/descargarNrms/{id} footer link
- Extract numero_decreto (digits after "N°" in the h6 title)
- Extract fecha_publicacion from "Fecha de Publicación: YYYY-MM-DD" (not DD/MM/YYYY)
- Extract edicion from the <a href="/edicions/view/..."> anchor text
- Extract sumario from <div class="contentpaneopen"> (whitespace collapsed)
- Extract pdf_url as absolute URL of /normas/descargarNrms/{id}
- Skip cards with no descargarNrms link (no gaceta_id → cannot dedup)
- pais is always 'BO'

Uses saved HTML fixture from REAL site — no network calls.
Fixture origin: http://www.gacetaoficialdebolivia.gob.bo/normas/listadonor/11?page=1
Fetched: 2026-06-20  (50 normas: 21 Decreto Presidencial + 29 Decreto Supremo)
"""
from pathlib import Path
from datetime import date
from typing import Optional

import pytest

FIXTURE_PATH = Path(__file__).parent / "fixtures" / "bolivia_listadonor_page1.html"

BASE_URL = "http://www.gacetaoficialdebolivia.gob.bo"

# Known values from the REAL fixture (page 1, fetched 2026-06-20)
# First DP on the page:
DP1_ID = 281251
DP1_NUMERO = "5637"
DP1_DATE = date(2026, 6, 20)
DP1_EDICION = "2061NEC"
DP1_PDF_URL = f"{BASE_URL}/normas/descargarNrms/{DP1_ID}"
DP1_SUMARIO_FRAGMENT = "MINISTRO INTERINO DE RELACIONES EXTERIORES"

# Second DP on the page (different date, edition, id)
DP2_ID = 281244
DP2_NUMERO = "5632"
DP2_DATE = date(2026, 6, 18)
DP2_EDICION = "2059NEC"

# Real page stats
REAL_PAGE_DP_COUNT = 21   # Decree Presidencial rows on page 1
REAL_PAGE_DS_PRESENT = True  # Page also contains Decreto Supremo (must be filtered out)


def _load_fixture() -> str:
    return FIXTURE_PATH.read_text(encoding="utf-8")


# ---------------------------------------------------------------------------
# Helpers for inline card HTML (used in edge-case tests)
# ---------------------------------------------------------------------------

def _make_card_html(
    titulo: str,
    edicion: str,
    fecha: str,
    sumario: str,
    gaceta_id: Optional[int] = 99999,
    include_descarga: bool = True,
) -> str:
    """Build minimal card HTML matching the real site DOM structure."""
    footer_links = ""
    if include_descarga and gaceta_id is not None:
        footer_links = (
            f'<a href="/normas/verGratis_gob/{gaceta_id}">Ver Norma</a> | '
            f'<a href="/normas/verGratis_gob1/{gaceta_id}" target="_blank">Descargar Word</a> | '
            f'<a title="Descargar Documento en PDF" href="/normas/descargarNrms/{gaceta_id}">Descargar PDF</a>'
        )
    return f"""
    <html><body>
    <div class="row">
      <div class="col-12 m-2">
        <div class="card h-100 p-2 fondo-paper">
          <div class="card-body">
            <p class="card-text texto-default">
              Publicado en edición: <strong><a href="/edicions/view/{edicion}">{edicion}</a></strong>
              | Fecha de Publicación: {fecha}
            </p>
            <h6><b>{titulo}</b></h6>
            <div class="contentpaneopen">
              <p align="justify">{sumario}</p>
            </div>
          </div>
          <div class="card-footer bg-transparent text-end" style="border: none;">
            {footer_links}
          </div>
        </div>
      </div>
    </div>
    </body></html>
    """


class TestBoliviaParserInit:
    """BoliviaParser can be instantiated."""

    def test_parser_can_be_instantiated(self) -> None:
        """BoliviaParser() creates without error."""
        from drivers.bolivia.parser import BoliviaParser
        parser = BoliviaParser()
        assert parser is not None


class TestBoliviaParserFiltersTipoNorma:
    """Parser returns ONLY Decreto Presidencial rows from the real fixture."""

    def test_returns_only_decreto_presidencial_rows(self) -> None:
        """All returned rows have tipo_norma == 'Decreto Presidencial'."""
        from drivers.bolivia.parser import BoliviaParser
        parser = BoliviaParser()
        html = _load_fixture()

        rows = parser.parse_listing(html)

        assert len(rows) > 0, "Parser returned no rows from real fixture"
        for row in rows:
            assert row["tipo_norma"] == "Decreto Presidencial", (
                f"Non-Decreto-Presidencial row leaked through: {row['tipo_norma']}"
            )

    def test_fixture_has_expected_dp_count(self) -> None:
        """Real fixture page has exactly 21 Decreto Presidencial rows (50 total, 29 DS filtered)."""
        from drivers.bolivia.parser import BoliviaParser
        parser = BoliviaParser()
        html = _load_fixture()

        rows = parser.parse_listing(html)

        assert len(rows) == REAL_PAGE_DP_COUNT, (
            f"Expected {REAL_PAGE_DP_COUNT} Decreto Presidencial rows, got {len(rows)}"
        )

    def test_decreto_supremo_excluded_from_results(self) -> None:
        """Decreto Supremo cards on the same page are completely excluded."""
        from drivers.bolivia.parser import BoliviaParser
        parser = BoliviaParser()
        html = _load_fixture()

        rows = parser.parse_listing(html)

        ds_leaked = [r for r in rows if "Supremo" in r.get("tipo_norma", "")]
        assert ds_leaked == [], (
            f"{len(ds_leaked)} Decreto Supremo rows leaked through: "
            f"{[r['tipo_norma'] for r in ds_leaked]}"
        )


class TestBoliviaParserFieldExtraction:
    """Parser correctly extracts all fields from Decreto Presidencial cards."""

    def test_gaceta_id_externo_extracted_from_descarga_url(self) -> None:
        """gaceta_id_externo is the numeric id from /normas/descargarNrms/{id}."""
        from drivers.bolivia.parser import BoliviaParser
        parser = BoliviaParser()
        rows = parser.parse_listing(_load_fixture())

        ids = [r["gaceta_id_externo"] for r in rows]
        assert DP1_ID in ids, f"Expected id {DP1_ID} (DP N° {DP1_NUMERO}) in {ids[:5]}"
        assert DP2_ID in ids, f"Expected id {DP2_ID} (DP N° {DP2_NUMERO}) in {ids[:5]}"

    def test_gaceta_id_externo_is_int(self) -> None:
        """gaceta_id_externo is an integer (not a string)."""
        from drivers.bolivia.parser import BoliviaParser
        parser = BoliviaParser()
        rows = parser.parse_listing(_load_fixture())

        for row in rows:
            assert isinstance(row["gaceta_id_externo"], int), (
                f"gaceta_id_externo is {type(row['gaceta_id_externo'])}, expected int"
            )

    def test_numero_decreto_is_digits_from_h6(self) -> None:
        """numero_decreto is the digit string after 'N°' in the h6 title."""
        from drivers.bolivia.parser import BoliviaParser
        parser = BoliviaParser()
        rows = parser.parse_listing(_load_fixture())

        numeros = [r["numero_decreto"] for r in rows]
        assert DP1_NUMERO in numeros, f"Expected numero '{DP1_NUMERO}' in {numeros[:5]}"
        assert DP2_NUMERO in numeros, f"Expected numero '{DP2_NUMERO}' in {numeros[:5]}"

    def test_fecha_publicacion_parsed_from_yyyy_mm_dd(self) -> None:
        """fecha_publicacion is a Python date parsed from 'YYYY-MM-DD' format."""
        from drivers.bolivia.parser import BoliviaParser
        parser = BoliviaParser()
        rows = parser.parse_listing(_load_fixture())

        dates = [r["fecha_publicacion"] for r in rows]
        assert DP1_DATE in dates, f"Expected {DP1_DATE} in {dates[:5]}"
        assert DP2_DATE in dates, f"Expected {DP2_DATE} in {dates[:5]}"

    def test_fecha_publicacion_is_date_object(self) -> None:
        """fecha_publicacion is a datetime.date, not a string."""
        from drivers.bolivia.parser import BoliviaParser
        parser = BoliviaParser()
        rows = parser.parse_listing(_load_fixture())

        for row in rows:
            if row["fecha_publicacion"] is not None:
                assert isinstance(row["fecha_publicacion"], date), (
                    f"fecha_publicacion type: {type(row['fecha_publicacion'])}"
                )

    def test_edicion_extracted_from_anchor(self) -> None:
        """edicion is extracted from the /edicions/view/ anchor text."""
        from drivers.bolivia.parser import BoliviaParser
        parser = BoliviaParser()
        rows = parser.parse_listing(_load_fixture())

        ediciones = [r["edicion"] for r in rows]
        assert DP1_EDICION in ediciones, f"Expected edicion '{DP1_EDICION}' in {ediciones[:5]}"
        assert DP2_EDICION in ediciones, f"Expected edicion '{DP2_EDICION}' in {ediciones[:5]}"

    def test_sumario_contains_real_text(self) -> None:
        """sumario is extracted from contentpaneopen div (real text, not empty)."""
        from drivers.bolivia.parser import BoliviaParser
        parser = BoliviaParser()
        rows = parser.parse_listing(_load_fixture())

        summaries = [r["sumario"] for r in rows]
        assert any(DP1_SUMARIO_FRAGMENT in (s or "") for s in summaries), (
            f"Expected sumario containing '{DP1_SUMARIO_FRAGMENT}' but got: {summaries[:3]}"
        )

    def test_sumario_whitespace_collapsed(self) -> None:
        """sumario has no double spaces or leading/trailing whitespace."""
        from drivers.bolivia.parser import BoliviaParser
        parser = BoliviaParser()
        rows = parser.parse_listing(_load_fixture())

        for row in rows:
            s = row.get("sumario") or ""
            assert "  " not in s, f"Double space in sumario: {s!r}"
            assert s == s.strip(), f"Leading/trailing whitespace in sumario: {s!r}"

    def test_pdf_url_is_absolute_descarga_url(self) -> None:
        """pdf_url is the absolute URL pointing to /normas/descargarNrms/{id}."""
        from drivers.bolivia.parser import BoliviaParser
        parser = BoliviaParser()
        rows = parser.parse_listing(_load_fixture())

        # Find the row for DP1
        dp1_rows = [r for r in rows if r["gaceta_id_externo"] == DP1_ID]
        assert len(dp1_rows) == 1, f"Expected exactly 1 row with id {DP1_ID}"
        assert dp1_rows[0]["pdf_url"] == DP1_PDF_URL, (
            f"Expected pdf_url={DP1_PDF_URL!r}, got {dp1_rows[0]['pdf_url']!r}"
        )

    def test_pdf_url_starts_with_http(self) -> None:
        """pdf_url is an absolute URL (starts with http)."""
        from drivers.bolivia.parser import BoliviaParser
        parser = BoliviaParser()
        rows = parser.parse_listing(_load_fixture())

        for row in rows:
            if row["pdf_url"] is not None:
                assert row["pdf_url"].startswith("http"), (
                    f"pdf_url is not absolute: {row['pdf_url']!r}"
                )

    def test_pais_is_bo(self) -> None:
        """pais is always 'BO' for the Bolivia driver."""
        from drivers.bolivia.parser import BoliviaParser
        parser = BoliviaParser()
        rows = parser.parse_listing(_load_fixture())

        for row in rows:
            assert row["pais"] == "BO"

    def test_utf8_characters_in_sumario(self) -> None:
        """Sumario preserves UTF-8 accented characters (e.g. 'Desígnese', 'José')."""
        from drivers.bolivia.parser import BoliviaParser
        parser = BoliviaParser()
        rows = parser.parse_listing(_load_fixture())

        # DP1 sumario contains accented text
        dp1 = next((r for r in rows if r["gaceta_id_externo"] == DP1_ID), None)
        assert dp1 is not None
        sumario = dp1["sumario"] or ""
        assert "Desígnese" in sumario or "José" in sumario or "é" in sumario, (
            f"Sumario missing accented chars: {sumario[:80]!r}"
        )


class TestBoliviaParserEdgeCases:
    """Parser handles edge cases gracefully — no crash, no wrong data."""

    def test_empty_html_returns_empty_list(self) -> None:
        """Parsing empty HTML returns an empty list."""
        from drivers.bolivia.parser import BoliviaParser
        parser = BoliviaParser()
        rows = parser.parse_listing("<html><body></body></html>")
        assert rows == []

    def test_html_with_no_cards_returns_empty_list(self) -> None:
        """HTML with no card-body divs returns an empty list."""
        from drivers.bolivia.parser import BoliviaParser
        parser = BoliviaParser()
        rows = parser.parse_listing("<html><body><p>No data</p></body></html>")
        assert rows == []

    def test_decreto_supremo_card_excluded(self) -> None:
        """A single Decreto Supremo card is excluded (empty result)."""
        from drivers.bolivia.parser import BoliviaParser
        parser = BoliviaParser()
        html = _make_card_html(
            titulo="Decreto Supremo N° 5636",
            edicion="2061NEC",
            fecha="2026-06-20",
            sumario="Declara estado de excepción.",
            gaceta_id=281250,
        )
        rows = parser.parse_listing(html)
        assert rows == [], "Decreto Supremo card should be excluded"

    def test_card_without_descarga_link_is_skipped(self) -> None:
        """DP card with no /normas/descargarNrms/ link is skipped (can't dedup without id)."""
        from drivers.bolivia.parser import BoliviaParser
        parser = BoliviaParser()
        html = _make_card_html(
            titulo="Decreto Presidencial N° 5599",
            edicion="2050NEC",
            fecha="2026-05-15",
            sumario="Designa al ciudadano JUAN PEREZ.",
            gaceta_id=None,
            include_descarga=False,
        )
        rows = parser.parse_listing(html)
        assert rows == [], "Card without descargarNrms link must be skipped (no id = can't dedup)"

    def test_valid_dp_card_after_excluded_ds_card(self) -> None:
        """A valid DP card following a DS card is still parsed correctly."""
        from drivers.bolivia.parser import BoliviaParser
        parser = BoliviaParser()
        ds_card = _make_card_html(
            titulo="Decreto Supremo N° 5636",
            edicion="2061NEC",
            fecha="2026-06-20",
            sumario="Declara estado de excepción.",
            gaceta_id=281250,
        )
        dp_card = _make_card_html(
            titulo="Decreto Presidencial N° 5637",
            edicion="2061NEC",
            fecha="2026-06-20",
            sumario="Desígnese MINISTRO INTERINO.",
            gaceta_id=281251,
        )
        # Combine both into a single HTML body
        combined = f"<html><body>{ds_card}<br/>{dp_card}</body></html>"
        rows = parser.parse_listing(combined)
        assert len(rows) == 1, f"Expected 1 DP row, got {len(rows)}"
        assert rows[0]["gaceta_id_externo"] == 281251

    def test_unparseable_date_sets_fecha_to_none(self) -> None:
        """DP card with invalid date string → fecha_publicacion=None; row still returned."""
        from drivers.bolivia.parser import BoliviaParser
        parser = BoliviaParser()
        html = _make_card_html(
            titulo="Decreto Presidencial N° 5600",
            edicion="2050NEC",
            fecha="FECHA-INVALIDA",
            sumario="Designa al ciudadano JUAN PEREZ.",
            gaceta_id=280000,
        )
        rows = parser.parse_listing(html)
        assert len(rows) == 1, "Row with invalid date should still be returned"
        assert rows[0]["fecha_publicacion"] is None

    def test_gaceta_id_externo_from_inline_card(self) -> None:
        """gaceta_id_externo is correctly parsed as int from a known inline card."""
        from drivers.bolivia.parser import BoliviaParser
        parser = BoliviaParser()
        html = _make_card_html(
            titulo="Decreto Presidencial N° 5637",
            edicion="2061NEC",
            fecha="2026-06-20",
            sumario="Desígnese MINISTRO INTERINO.",
            gaceta_id=281251,
        )
        rows = parser.parse_listing(html)
        assert len(rows) == 1
        assert rows[0]["gaceta_id_externo"] == 281251
        assert isinstance(rows[0]["gaceta_id_externo"], int)


class TestBoliviaDateParsing:
    """_parse_date correctly handles YYYY-MM-DD format (real site format)."""

    def test_parse_valid_yyyy_mm_dd(self) -> None:
        """YYYY-MM-DD string is parsed correctly."""
        from drivers.bolivia.parser import _parse_date
        result = _parse_date("2026-06-20")
        assert result == date(2026, 6, 20)

    def test_parse_another_yyyy_mm_dd(self) -> None:
        """Second valid date parses correctly (triangulation)."""
        from drivers.bolivia.parser import _parse_date
        result = _parse_date("2021-01-15")
        assert result == date(2021, 1, 15)

    def test_parse_empty_string_returns_none(self) -> None:
        """Empty string → None (no crash)."""
        from drivers.bolivia.parser import _parse_date
        result = _parse_date("")
        assert result is None

    def test_parse_invalid_string_returns_none(self) -> None:
        """Unparseable string → None (no crash)."""
        from drivers.bolivia.parser import _parse_date
        result = _parse_date("INVALID-DATE")
        assert result is None

    def test_parse_old_dd_mm_yyyy_format_returns_none(self) -> None:
        """Old DD/MM/YYYY format (from the old fabricated fixture) must NOT be accepted."""
        from drivers.bolivia.parser import _parse_date
        # The old parser accepted this format. The new one must not (real site uses YYYY-MM-DD).
        result = _parse_date("14/06/2026")
        assert result is None, (
            "DD/MM/YYYY format must not be accepted — real site uses YYYY-MM-DD"
        )
