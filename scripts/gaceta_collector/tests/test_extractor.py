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


class TestExtractorMixedVerbSafetyNet:
    """FIX 1: Mixed-verb decrees (non-V1 verb + designación de) → requiere_revision.

    The natural feminine form 'designación de la ciudadana' matches _RE_DESIGNA_TRIGGER
    (word boundary after 'de' is satisfied by the trailing space). When a non-V1
    verb (Ratifica, Deroga, acepta la renuncia) also appears, has_other is True —
    the decree is ambiguous and must be flagged for human review, NOT auto-extracted.

    These tests FAIL before FIX 1 because has_other is computed but never gated.
    """

    def test_ratifica_designacion_de_ciudadana_returns_requiere_revision(self) -> None:
        """'Ratifica la designación de la ciudadana NAME como CARGO' → requiere_revision."""
        from core.extractor import extract_eventos

        result = extract_eventos(
            "Ratifica la designación de la ciudadana ANA LOPEZ como Ministra."
        )

        assert result.estado_extraccion == "requiere_revision"
        assert result.eventos == []

    def test_deroga_designacion_de_ciudadana_returns_requiere_revision(self) -> None:
        """'Deroga la designación de la ciudadana NAME como CARGO' → requiere_revision."""
        from core.extractor import extract_eventos

        result = extract_eventos(
            "Deroga la designación de la ciudadana ROSA QUISPE como Viceministra."
        )

        assert result.estado_extraccion == "requiere_revision"
        assert result.eventos == []

    def test_acepta_renuncia_y_designa_returns_requiere_revision(self) -> None:
        """'Acepta la renuncia y designa a la ciudadana NAME como CARGO' → requiere_revision."""
        from core.extractor import extract_eventos

        result = extract_eventos(
            "Acepta la renuncia y designa a la ciudadana MARIA PEREZ como Ministra."
        )

        assert result.estado_extraccion == "requiere_revision"
        assert result.eventos == []

    def test_pure_designa_feminine_still_returns_procesado_no_regression(self) -> None:
        """Regression: pure 'Designa a la ciudadana NAME' (no other verb) → procesado."""
        from core.extractor import extract_eventos

        result = extract_eventos(
            "Designa a la ciudadana ANA MARIA GUTIERREZ SORIA como Ministra de Salud."
        )

        assert result.estado_extraccion == "procesado"
        assert len(result.eventos) == 1


class TestExtractorAllowlistGoverningVerb:
    """FIX A: ALLOWLIST — only decrees GOVERNED (LED) by Designa verb auto-extract.

    Decrees where 'designación de' appears as an OBJECT (governed by another verb)
    must return requiere_revision. These tests FAIL before FIX A because the trigger
    matches 'designación de' anywhere in the sumario (broad search), not anchored to
    the leading governing position.

    Root cause: _RE_DESIGNA_TRIGGER uses .search() with no positional anchor, so
    'Aprueba la designación de la ciudadana...' triggers has_designa=True even though
    Aprueba (not Designa) governs the decree. Verbs like Aprueba/Modifica/Complementa/
    Confirma/Reincorpora are not in _RE_OTHER_VERB (the denylist), so has_other=False
    and the decree wrongly auto-extracts as procesado.
    """

    def test_aprueba_designacion_de_ciudadana_returns_requiere_revision(self) -> None:
        """'Aprueba la designación de la ciudadana NAME como CARGO' → requiere_revision."""
        from core.extractor import extract_eventos

        result = extract_eventos(
            "Aprueba la designación de la ciudadana ANA LOPEZ como Ministra."
        )

        assert result.estado_extraccion == "requiere_revision"
        assert result.eventos == []

    def test_modifica_aprueba_designacion_returns_requiere_revision(self) -> None:
        """'Modifica el Decreto y aprueba la designación de...' → requiere_revision."""
        from core.extractor import extract_eventos

        result = extract_eventos(
            "Modifica el Decreto y aprueba la designación de la ciudadana ROSA QUISPE como Viceministra."
        )

        assert result.estado_extraccion == "requiere_revision"
        assert result.eventos == []

    def test_complementa_designacion_returns_requiere_revision(self) -> None:
        """'Complementa la designación de la ciudadana NAME como CARGO' → requiere_revision."""
        from core.extractor import extract_eventos

        result = extract_eventos(
            "Complementa la designación de la ciudadana MARIA PEREZ como Ministra."
        )

        assert result.estado_extraccion == "requiere_revision"
        assert result.eventos == []

    def test_confirma_designacion_returns_requiere_revision(self) -> None:
        """'Confirma la designación de la ciudadana NAME como CARGO' → requiere_revision."""
        from core.extractor import extract_eventos

        result = extract_eventos(
            "Confirma la designación de la ciudadana JUANA VEGA como Ministra."
        )

        assert result.estado_extraccion == "requiere_revision"
        assert result.eventos == []

    def test_reincorpora_designacion_returns_requiere_revision(self) -> None:
        """'Reincorpora la designación de la ciudadana NAME como CARGO' → requiere_revision."""
        from core.extractor import extract_eventos

        result = extract_eventos(
            "Reincorpora la designación de la ciudadana INES SORIA como Ministra."
        )

        assert result.estado_extraccion == "requiere_revision"
        assert result.eventos == []

    def test_pure_designa_at_start_returns_procesado_allowlist_positive(self) -> None:
        """Regression: pure 'Designa a la ciudadana NAME' at start → procesado (allowlist match)."""
        from core.extractor import extract_eventos

        result = extract_eventos(
            "Designa a la ciudadana ANA LOPEZ como Ministra de Salud."
        )

        assert result.estado_extraccion == "procesado"
        assert len(result.eventos) == 1


class TestExtractorIncisoUppercaseOnly:
    """_RE_INCISO must NOT split on lowercase roman tokens embedded mid-sumario.

    Real Gaceta incisos are always uppercase (I., II., III.). A lowercase 'v.'
    that appears as part of a district name (e.g. 'Distrito v. de La Paz') must
    NOT trigger the inciso splitter, which would cut the segment before the
    terminating period and cause the first appointment to be silently dropped.

    These tests FAIL before the fix because _RE_INCISO uses re.IGNORECASE, so
    lowercase 'v.' is treated as a roman numeral separator.
    """

    def test_lowercase_roman_mid_sumario_extracts_both_appointments(self) -> None:
        """
        Lowercase 'v.' embedded in a district name must NOT split the sumario.
        Both ANA PEREZ and LUIS ROCHA must be extracted as two separate eventos.

        RED: currently only LUIS ROCHA is extracted because the spurious split
        on 'v.' leaves ANA PEREZ's segment without a terminating '.', so
        _RE_APPOINTMENT fails to match it.
        """
        from core.extractor import extract_eventos, ESTADO_PROCESADO

        sumario = (
            "Designa a la ciudadana ANA PEREZ como Directora del Distrito v. de La Paz, "
            "y al ciudadano LUIS ROCHA como Director."
        )
        result = extract_eventos(sumario)

        assert result.estado_extraccion == ESTADO_PROCESADO
        assert len(result.eventos) == 2
        nombres = {ev["persona_nombre"] for ev in result.eventos}
        assert "ANA PEREZ" in nombres
        assert "LUIS ROCHA" in nombres

    def test_uppercase_incisos_still_split_correctly_no_regression(self) -> None:
        """
        Regression guard: uppercase I./II. incisos must still produce two eventos
        after the IGNORECASE fix. Verifies the fix does not break the core
        multi-appointment flow.
        """
        from core.extractor import extract_eventos, ESTADO_PROCESADO

        sumario = (
            "Designa a las siguientes personas: "
            "I. A la ciudadana ANA MARIA FLORES VEGA como Ministra de Salud. "
            "II. Al ciudadano CARLOS ANTONIO MENDEZ PEREZ como Ministro de Economía."
        )
        result = extract_eventos(sumario)

        assert result.estado_extraccion == ESTADO_PROCESADO
        assert len(result.eventos) == 2
        names = [ev["persona_nombre"] for ev in result.eventos]
        assert any("ANA MARIA FLORES VEGA" in n for n in names)
        assert any("CARLOS ANTONIO MENDEZ PEREZ" in n for n in names)
