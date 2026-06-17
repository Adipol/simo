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


class TestBoliviaParserMalformedRows:
    """Parser skips malformed <tr> rows silently (no crash, no partial data)."""

    def _table(self, row_html: str) -> str:
        """Wrap row HTML in the expected table structure."""
        return (
            '<table id="tNormas"><tbody>'
            + row_html
            + "</tbody></table>"
        )

    def _valid_row(self, gid: int = 99999, date_str: str = "14/06/2026") -> str:
        """Helper: build a well-formed Decreto Presidencial row."""
        return (
            f"<tr>"
            f"<td>1</td>"
            f'<td><a href="/normas/textonormaRE/{gid}">D-{gid}</a></td>'
            f"<td>Decreto Presidencial</td>"
            f"<td>{date_str}</td>"
            f"<td>3500</td>"
            f"<td>Designa al ciudadano JUAN PEREZ como Ministro.</td>"
            f"<td></td>"
            f"</tr>"
        )

    def test_tr_with_fewer_than_7_cells_is_skipped(self) -> None:
        """<tr> with < 7 <td> cells is silently skipped (returns empty list)."""
        from drivers.bolivia.parser import BoliviaParser
        parser = BoliviaParser()
        html = self._table(
            "<tr>"
            '<td><a href="/normas/textonormaRE/999">D-999</a></td>'
            "<td>Decreto Presidencial</td>"
            "<td>14/06/2026</td>"
            "</tr>"
        )
        rows = parser.parse_listing(html)
        assert rows == []

    def test_numero_cell_without_anchor_is_skipped(self) -> None:
        """Numero <td> without <a> → row skipped."""
        from drivers.bolivia.parser import BoliviaParser
        parser = BoliviaParser()
        html = self._table(
            "<tr>"
            "<td>1</td>"
            "<td>Plain text — no link</td>"
            "<td>Decreto Presidencial</td>"
            "<td>14/06/2026</td>"
            "<td>3500</td>"
            "<td>Some sumario.</td>"
            "<td></td>"
            "</tr>"
        )
        rows = parser.parse_listing(html)
        assert rows == []

    def test_href_failing_id_regex_is_skipped(self) -> None:
        """href that doesn't match /normas/textonormaRE/<digits> → row skipped."""
        from drivers.bolivia.parser import BoliviaParser
        parser = BoliviaParser()
        html = self._table(
            "<tr>"
            "<td>1</td>"
            '<td><a href="/other/path/no-id">D-999</a></td>'
            "<td>Decreto Presidencial</td>"
            "<td>14/06/2026</td>"
            "<td>3500</td>"
            "<td>Some sumario.</td>"
            "<td></td>"
            "</tr>"
        )
        rows = parser.parse_listing(html)
        assert rows == []

    def test_unparseable_date_sets_fecha_publicacion_to_none(self) -> None:
        """Invalid date string → fecha_publicacion is None; row is still returned."""
        from drivers.bolivia.parser import BoliviaParser
        parser = BoliviaParser()
        html = self._table(self._valid_row(date_str="INVALID-DATE"))
        rows = parser.parse_listing(html)
        assert len(rows) == 1
        assert rows[0]["fecha_publicacion"] is None

    def test_valid_row_after_malformed_is_returned(self) -> None:
        """A valid row following a malformed one is still parsed correctly."""
        from drivers.bolivia.parser import BoliviaParser
        parser = BoliviaParser()
        malformed = (
            "<tr><td>bad</td></tr>"  # < 7 cells
        )
        html = self._table(malformed + self._valid_row(gid=88888))
        rows = parser.parse_listing(html)
        assert len(rows) == 1
        assert rows[0]["gaceta_id_externo"] == 88888
