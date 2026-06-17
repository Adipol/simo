"""
Tests for drivers/bolivia/parser.py — Strict TDD RED written first.

BoliviaParser must:
- Parse the listing HTML fixture and return NormaRow objects
- Filter to ONLY Decreto Presidencial rows (tipo_norma check)
- Extract gaceta_id_externo from the norma URL
- Extract numero_decreto, fecha_publicacion, sumario, pdf_url, edicion

Uses saved HTML fixture — no network calls.
"""
from pathlib import Path
from datetime import date

import pytest

FIXTURE_PATH = Path(__file__).parent / "fixtures" / "bolivia_listadonor_page1.html"


def _load_fixture() -> str:
    return FIXTURE_PATH.read_text(encoding="utf-8")


class TestBoliviaParserInit:
    """BoliviaParser can be instantiated."""

    def test_parser_can_be_instantiated(self) -> None:
        """BoliviaParser() creates without error."""
        from drivers.bolivia.parser import BoliviaParser
        parser = BoliviaParser()
        assert parser is not None


class TestBoliviaParserFiltersTipoNorma:
    """Parser returns ONLY Decreto Presidencial rows."""

    def test_returns_only_decreto_presidencial_rows(self) -> None:
        """Rows with tipo_norma != 'Decreto Presidencial' are excluded."""
        from drivers.bolivia.parser import BoliviaParser
        parser = BoliviaParser()
        html = _load_fixture()

        rows = parser.parse_listing(html)

        for row in rows:
            assert row["tipo_norma"] == "Decreto Presidencial", (
                f"Non-Decreto-Presidencial row found: {row['tipo_norma']}"
            )

    def test_fixture_has_mixed_tipos_but_only_presidencial_returned(self) -> None:
        """Fixture contains Decreto Supremo and Ley rows which must be filtered out."""
        from drivers.bolivia.parser import BoliviaParser
        parser = BoliviaParser()
        html = _load_fixture()

        rows = parser.parse_listing(html)

        # Fixture has 3 Decreto Presidencial, 1 Decreto Supremo, 1 Ley
        assert len(rows) == 3, f"Expected 3 Decreto Presidencial rows, got {len(rows)}"


class TestBoliviaParserFieldExtraction:
    """Parser correctly extracts all fields from each Decreto Presidencial row."""

    def test_gaceta_id_externo_extracted_from_url(self) -> None:
        """gaceta_id_externo is parsed as int from the norma URL path."""
        from drivers.bolivia.parser import BoliviaParser
        parser = BoliviaParser()
        html = _load_fixture()

        rows = parser.parse_listing(html)

        # Fixture row 1: href="/normas/textonormaRE/180125" → gaceta_id_externo=180125
        ids = [r["gaceta_id_externo"] for r in rows]
        assert 180125 in ids
        assert 180123 in ids

    def test_gaceta_id_externo_is_int(self) -> None:
        """gaceta_id_externo is an integer, not a string."""
        from drivers.bolivia.parser import BoliviaParser
        parser = BoliviaParser()
        rows = parser.parse_listing(_load_fixture())
        for row in rows:
            assert isinstance(row["gaceta_id_externo"], int)

    def test_numero_decreto_extracted(self) -> None:
        """numero_decreto is extracted from the link text."""
        from drivers.bolivia.parser import BoliviaParser
        parser = BoliviaParser()
        rows = parser.parse_listing(_load_fixture())

        numeros = [r["numero_decreto"] for r in rows]
        assert "0549/2026" in numeros

    def test_sumario_extracted(self) -> None:
        """sumario text is extracted for each row."""
        from drivers.bolivia.parser import BoliviaParser
        parser = BoliviaParser()
        rows = parser.parse_listing(_load_fixture())

        summaries = [r["sumario"] for r in rows]
        assert any("MARIA JOSE GARCIA LUNA" in s for s in summaries)

    def test_fecha_publicacion_parsed_as_date(self) -> None:
        """fecha_publicacion is parsed as a Python date from 'DD/MM/YYYY' format."""
        from drivers.bolivia.parser import BoliviaParser
        parser = BoliviaParser()
        rows = parser.parse_listing(_load_fixture())

        dates = [r["fecha_publicacion"] for r in rows]
        assert date(2026, 6, 14) in dates

    def test_pdf_url_extracted(self) -> None:
        """pdf_url is extracted when present."""
        from drivers.bolivia.parser import BoliviaParser
        parser = BoliviaParser()
        rows = parser.parse_listing(_load_fixture())

        pdf_urls = [r["pdf_url"] for r in rows]
        assert any("180125.pdf" in (u or "") for u in pdf_urls)

    def test_edicion_extracted(self) -> None:
        """edicion (edition number) is extracted as a string."""
        from drivers.bolivia.parser import BoliviaParser
        parser = BoliviaParser()
        rows = parser.parse_listing(_load_fixture())

        ediciones = [r["edicion"] for r in rows]
        assert "3500" in ediciones

    def test_pais_is_bo(self) -> None:
        """pais is always 'BO' for the Bolivia driver."""
        from drivers.bolivia.parser import BoliviaParser
        parser = BoliviaParser()
        rows = parser.parse_listing(_load_fixture())

        for row in rows:
            assert row["pais"] == "BO"


class TestBoliviaParserEdgeCases:
    """Parser handles edge cases gracefully."""

    def test_empty_html_returns_empty_list(self) -> None:
        """Parsing empty HTML returns an empty list (no crash)."""
        from drivers.bolivia.parser import BoliviaParser
        parser = BoliviaParser()
        rows = parser.parse_listing("<html><body></body></html>")
        assert rows == []

    def test_html_with_no_table_returns_empty_list(self) -> None:
        """HTML without the normas table returns an empty list."""
        from drivers.bolivia.parser import BoliviaParser
        parser = BoliviaParser()
        rows = parser.parse_listing("<html><body><p>No data</p></body></html>")
        assert rows == []
