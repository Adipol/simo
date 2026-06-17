"""
Tests for core/extractor.py — Strict TDD RED written first.

The extractor is a pure function that takes a sumario string and returns an
ExtractorResult with:
  - eventos: list of extracted appointment dicts
  - estado_extraccion: 'procesado' | 'requiere_detalle' | 'requiere_revision'

V1 rules:
  1. Sumario with 'Designa' verb + extractable name → evento(s), estado='procesado'
  2. Sumario with 'Designa' + INTERINO flag → interino=True in the evento
  3. Sumario with 'Designa' + incisos I./II./III. → multiple eventos
  4. Bulk sumario (no individual name extractable) → [], estado='requiere_detalle'
  5. Non-'Designa' verb (Ratifica, Deroga) → [], estado='requiere_revision'

No I/O, no database, no network — pure logic only.
"""
import pytest


class TestExtractorSimpleDesigna:
    """Single appointment extracted from a 'Designa' sumario."""

    def test_simple_female_designa_extracts_evento(self) -> None:
        """'Designa a la ciudadana NAME como CARGO' → 1 evento."""
        from core.extractor import extract_eventos

        result = extract_eventos(
            "Designa a la ciudadana MARIA JOSE GARCIA LUNA como Ministra de Educación."
        )

        assert result.estado_extraccion == "procesado"
        assert len(result.eventos) == 1
        ev = result.eventos[0]
        assert "MARIA JOSE GARCIA LUNA" in ev["persona_nombre"]
        assert "Ministra" in ev["cargo"]
        assert ev["interino"] is False
        assert ev["tipo_evento"] == "designacion"

    def test_simple_male_designa_extracts_evento(self) -> None:
        """'Designa al ciudadano NAME como CARGO' → 1 evento."""
        from core.extractor import extract_eventos

        result = extract_eventos(
            "Designa al ciudadano PEDRO MARCOS QUISPE MAMANI como Ministro de Salud."
        )

        assert result.estado_extraccion == "procesado"
        assert len(result.eventos) == 1
        ev = result.eventos[0]
        assert "PEDRO MARCOS QUISPE MAMANI" in ev["persona_nombre"]
        assert "Ministro" in ev["cargo"]
        assert ev["interino"] is False

    def test_persona_nombre_normalizado_is_lowercase_no_accents(self) -> None:
        """persona_nombre_normalizado is unidecoded + lowercased."""
        from core.extractor import extract_eventos

        result = extract_eventos(
            "Designa a la ciudadana MARÍA PÉREZ TORRES como Viceministra de Obras."
        )

        assert result.estado_extraccion == "procesado"
        ev = result.eventos[0]
        # Should not contain uppercase or accented characters
        assert ev["persona_nombre_normalizado"] == ev["persona_nombre_normalizado"].lower()
        assert "á" not in ev["persona_nombre_normalizado"]
        assert "é" not in ev["persona_nombre_normalizado"]


class TestExtractorInterino:
    """INTERINO flag in sumario sets interino=True on the evento."""

    def test_interino_flag_sets_interino_true(self) -> None:
        """Sumario with INTERINO → evento.interino is True."""
        from core.extractor import extract_eventos

        result = extract_eventos(
            "Designa al ciudadano JUAN CARLOS MAMANI QUISPE como INTERINO Viceministro de Energías."
        )

        assert result.estado_extraccion == "procesado"
        assert len(result.eventos) >= 1
        ev = result.eventos[0]
        assert ev["interino"] is True

    def test_without_interino_flag_is_false(self) -> None:
        """Sumario without INTERINO → evento.interino is False."""
        from core.extractor import extract_eventos

        result = extract_eventos(
            "Designa a la ciudadana ROSA ELVIRA COPA CONDORI como Ministra de Justicia."
        )

        assert result.eventos[0]["interino"] is False


class TestExtractorMultipleIncisos:
    """Multiple appointments via I./II./III. incisos."""

    def test_multiple_incisos_extracts_multiple_eventos(self) -> None:
        """Sumario with I./II. incisos → one evento per inciso."""
        from core.extractor import extract_eventos

        sumario = (
            "Designa a las siguientes personas: "
            "I. A la ciudadana ANA MARIA FLORES VEGA como Ministra de Salud. "
            "II. Al ciudadano CARLOS ANTONIO MENDEZ PEREZ como Ministro de Economía."
        )
        result = extract_eventos(sumario)

        assert result.estado_extraccion == "procesado"
        assert len(result.eventos) == 2
        names = [ev["persona_nombre"] for ev in result.eventos]
        assert any("ANA MARIA FLORES VEGA" in n for n in names)
        assert any("CARLOS ANTONIO MENDEZ PEREZ" in n for n in names)


class TestExtractorBulkRequireDetalle:
    """Bulk sumarios without individual names → requiere_detalle."""

    def test_bulk_sumario_no_name_returns_requiere_detalle(self) -> None:
        """'Designación de Ministros de Estado' → eventos=[], requiere_detalle."""
        from core.extractor import extract_eventos

        result = extract_eventos("Designación de Ministros de Estado y Alto Mando Militar.")

        assert result.estado_extraccion == "requiere_detalle"
        assert result.eventos == []

    def test_alto_mando_bulk_returns_requiere_detalle(self) -> None:
        """'Designa al Alto Mando Militar' (no individual name) → requiere_detalle."""
        from core.extractor import extract_eventos

        result = extract_eventos("Designa al Alto Mando de la Fuerza Aérea Boliviana.")

        assert result.estado_extraccion == "requiere_detalle"
        assert result.eventos == []


class TestExtractorNonDesignaVerb:
    """Non-'Designa' verbs → requiere_revision, no eventos extracted."""

    def test_ratifica_verb_returns_requiere_revision(self) -> None:
        """'Ratifica la designación...' → requiere_revision, no eventos."""
        from core.extractor import extract_eventos

        result = extract_eventos(
            "Ratifica la designación del ciudadano PEDRO GARCIA como Ministro."
        )

        assert result.estado_extraccion == "requiere_revision"
        assert result.eventos == []

    def test_unrelated_sumario_returns_requiere_revision(self) -> None:
        """Sumario with no designation verb → requiere_revision."""
        from core.extractor import extract_eventos

        result = extract_eventos(
            "Aprueba el Presupuesto General del Estado para la gestión 2027."
        )

        assert result.estado_extraccion == "requiere_revision"
        assert result.eventos == []

    def test_cese_pattern_returns_requiere_revision(self) -> None:
        """Cese/derogation pattern (derogates a designation) → requiere_revision."""
        from core.extractor import extract_eventos

        result = extract_eventos(
            "Deroga la designación del ciudadano MARCOS QUISPE como Ministro de Estado."
        )

        assert result.estado_extraccion == "requiere_revision"
        assert result.eventos == []
