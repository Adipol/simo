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


# ══════════════════════════════════════════════════════════════════════════════
# FIX A1 — Completeness guard (JD Round 4)
# ══════════════════════════════════════════════════════════════════════════════

class TestExtractorCompletenessGuard:
    """FIX A1: Incomplete extraction routes to requiere_revision, not procesado.

    Contract: when the sumario has more ciudadano/ciudadana singular references
    than extracted eventos, return eventos=[], estado=requiere_revision so that
    PR3 (human review queue) can re-extract from scratch.

    This closes the silent-drop class: a partial extraction that marks procesado
    makes the dropped appointment invisible to operators.
    """

    def test_partial_name_breaks_regex_routes_to_requiere_revision(self) -> None:
        """
        JUAN P. PEREZ has a middle initial ('P.') that breaks _RE_APPOINTMENT.
        Only ANA ROCHA extracts → 1 evento, but 2 ciudadano/ciudadana refs.
        Incomplete → requiere_revision, eventos=[].
        RED: currently returns procesado with 1 evento (silent drop).
        """
        from core.extractor import extract_eventos, ESTADO_REQUIERE_REVISION

        sumario = (
            "Designa al ciudadano JUAN P. PEREZ como Ministro de Salud, "
            "y a la ciudadana ANA ROCHA como Ministra de Economía."
        )
        result = extract_eventos(sumario)

        assert result.estado_extraccion == ESTADO_REQUIERE_REVISION
        assert result.eventos == []

    def test_two_clean_appointments_both_parse_returns_procesado(self) -> None:
        """
        Both names parse cleanly (no middle initial). 2 refs, 2 extracted → procesado.
        Regression guard: the completeness check must NOT break valid multi-extraction.
        """
        from core.extractor import extract_eventos, ESTADO_PROCESADO

        sumario = (
            "Designa al ciudadano JUAN PEREZ como Ministro de Salud, "
            "y a la ciudadana ANA ROCHA como Ministra de Economía."
        )
        result = extract_eventos(sumario)

        assert result.estado_extraccion == ESTADO_PROCESADO
        assert len(result.eventos) == 2
        names = {ev["persona_nombre"] for ev in result.eventos}
        assert any("JUAN PEREZ" in n for n in names)
        assert any("ANA ROCHA" in n for n in names)

    def test_single_clean_appointment_returns_procesado_no_regression(self) -> None:
        """Single clean appointment: 1 ref, 1 extracted → procesado (no regression)."""
        from core.extractor import extract_eventos, ESTADO_PROCESADO

        result = extract_eventos(
            "Designa al ciudadano PEDRO QUISPE como Ministro de Educación."
        )

        assert result.estado_extraccion == ESTADO_PROCESADO
        assert len(result.eventos) == 1
        assert "PEDRO QUISPE" in result.eventos[0]["persona_nombre"]


# ══════════════════════════════════════════════════════════════════════════════
# FIX A2 — Per-appointment INTERINO (JD Round 4)
# ══════════════════════════════════════════════════════════════════════════════

class TestExtractorPerAppointmentInterino:
    """FIX A2: INTERINO must be scoped to the appointment that carries it.

    Root cause: _build_evento_from_match computes interino from the full sumario
    via _RE_INTERINO, so in a multi-appointment decree where only one person is
    INTERINO, ALL appointments incorrectly receive interino=True.

    Fix: capture INTERINO via a named group in _RE_APPOINTMENT; resolve per match.
    """

    def test_mixed_interino_inciso_first_interino_second_not(self) -> None:
        """
        I. JUAN PEREZ is INTERINO Ministro. II. ANA ROCHA is plain Ministra.
        After fix: JUAN interino=True, ANA interino=False.
        RED: currently BOTH get interino=True because full-sumario scan hits INTERINO.
        """
        from core.extractor import extract_eventos, ESTADO_PROCESADO

        sumario = (
            "Designa: "
            "I. Al ciudadano JUAN PEREZ como INTERINO Ministro de Salud. "
            "II. A la ciudadana ANA ROCHA como Ministra de Economía."
        )
        result = extract_eventos(sumario)

        assert result.estado_extraccion == ESTADO_PROCESADO
        assert len(result.eventos) == 2

        by_name = {ev["persona_nombre"]: ev for ev in result.eventos}
        assert by_name["JUAN PEREZ"]["interino"] is True
        assert by_name["ANA ROCHA"]["interino"] is False

    def test_single_interino_appointment_still_true(self) -> None:
        """Single INTERINO appointment → interino=True (no regression)."""
        from core.extractor import extract_eventos

        result = extract_eventos(
            "Designa al ciudadano LUIS MAMANI como INTERINO Viceministro de Obras."
        )

        assert result.estado_extraccion == "procesado"
        assert result.eventos[0]["interino"] is True

    def test_both_appointments_non_interino_both_false(self) -> None:
        """Two clean appointments, neither INTERINO → both interino=False."""
        from core.extractor import extract_eventos

        sumario = (
            "Designa: "
            "I. Al ciudadano CARLOS MENDEZ como Ministro de Economía. "
            "II. A la ciudadana ROSA VEGA como Ministra de Salud."
        )
        result = extract_eventos(sumario)

        assert result.estado_extraccion == "procesado"
        assert all(ev["interino"] is False for ev in result.eventos)


# ══════════════════════════════════════════════════════════════════════════════
# FIX A3 — finditer over-extraction safety (JD Round 4)
# ══════════════════════════════════════════════════════════════════════════════

class TestExtractorFinditerNoOverExtraction:
    """FIX A3: finditer must not produce duplicates or phantom/partial names.

    Verifies that the finditer-based extraction in _extract_appointments emits
    exactly the correct distinct eventos for a multi-appointment same-segment
    sumario — no duplicates, no phantom names from partial regex matches.
    """

    def test_two_appointments_same_segment_no_duplicates_no_phantoms(self) -> None:
        """
        Two appointments in the same segment (no incisos), joined by conjunction.
        Must produce exactly 2 distinct eventos with the correct full names.
        """
        from core.extractor import extract_eventos, ESTADO_PROCESADO

        sumario = (
            "Designa al ciudadano ABEL MAMANI QUISPE como Director Regional, "
            "y al ciudadano CARLOS VEGA PEREZ como Subdirector Nacional."
        )
        result = extract_eventos(sumario)

        assert result.estado_extraccion == ESTADO_PROCESADO
        assert len(result.eventos) == 2

        names = [ev["persona_nombre"] for ev in result.eventos]
        # No duplicates
        assert len(set(names)) == 2
        # Correct full names, no partial captures
        assert any("ABEL MAMANI QUISPE" in n for n in names)
        assert any("CARLOS VEGA PEREZ" in n for n in names)


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


# ══════════════════════════════════════════════════════════════════════════════
# FIX Round 5 — Completeness guard counts appointment clauses (JD Round 5)
# ══════════════════════════════════════════════════════════════════════════════

class TestExtractorRound5ClauseGuard:
    """JD Round 5: completeness guard counts 'como <Cargo>' clauses, not ciudadano tokens.

    Root cause (Round 4 residual): a co-appointment phrased WITHOUT 'ciudadano/ciudadana'
    escapes BOTH _RE_APPOINTMENT (extractor) AND _count_appointment_refs (counter),
    so the guard sees len(eventos)==ref_count and wrongly marks it procesado while
    silently dropping the un-prefixed appointee.

    Fix: replace the ciudadano counter with a 'como <uppercase-cargo-start>' clause
    counter that is independent of the appointee's prefix token.

    Guard contract (unchanged for existing behaviour):
    - no designa                                   → requiere_revision
    - designa, zero parseable names (bulk/raw)     → requiere_detalle
    - designa, some extracted but fewer than clauses → requiere_revision (never silent drop)
    - designa, all clauses extracted               → procesado
    """

    def test_co_appointment_no_ciudadana_prefix_routes_to_requiere_revision(self) -> None:
        """
        'y a MARIA ROCHA como Ministra' has no 'ciudadana' prefix.
        _RE_APPOINTMENT cannot extract her; but the clause counter sees 2 clauses.
        1 evento extracted < 2 clauses → requiere_revision, eventos=[].

        RED: currently returns procesado with [JUAN PEREZ] (silent drop of MARIA ROCHA).
        """
        from core.extractor import extract_eventos, ESTADO_REQUIERE_REVISION

        result = extract_eventos(
            "Designa al ciudadano JUAN PEREZ como Ministro de Salud, "
            "y a MARIA ROCHA como Ministra de Economía."
        )

        assert result.estado_extraccion == ESTADO_REQUIERE_REVISION
        assert result.eventos == []

    def test_round4_initial_case_still_requiere_revision(self) -> None:
        """
        Round-4 regression guard: middle-initial JUAN P. PEREZ + ciudadana ANA ROCHA.
        2 clauses, 1 extracted → requiere_revision (must keep passing after Round-5 fix).
        """
        from core.extractor import extract_eventos, ESTADO_REQUIERE_REVISION

        result = extract_eventos(
            "Designa al ciudadano JUAN P. PEREZ como Ministro de Salud, "
            "y a la ciudadana ANA ROCHA como Ministra de Economía."
        )

        assert result.estado_extraccion == ESTADO_REQUIERE_REVISION
        assert result.eventos == []

    def test_single_clean_appointment_procesado(self) -> None:
        """Single clean appointment: 1 clause, 1 extracted → procesado (no regression)."""
        from core.extractor import extract_eventos, ESTADO_PROCESADO

        result = extract_eventos(
            "Designa al ciudadano PEDRO QUISPE como Ministro de Educación."
        )

        assert result.estado_extraccion == ESTADO_PROCESADO
        assert len(result.eventos) == 1
        assert "PEDRO QUISPE" in result.eventos[0]["persona_nombre"]

    def test_clean_multi_both_ciudadana_prefix_procesado(self) -> None:
        """
        Both appointees have 'ciudadana/ciudadano' prefix.
        2 clauses, 2 extracted → procesado (regression guard: ciudadano-counter used to handle this).
        """
        from core.extractor import extract_eventos, ESTADO_PROCESADO

        result = extract_eventos(
            "Designa al ciudadano JUAN PEREZ como Ministro de Salud, "
            "y a la ciudadana ANA ROCHA como Ministra de Economía."
        )

        assert result.estado_extraccion == ESTADO_PROCESADO
        assert len(result.eventos) == 2
        names = {ev["persona_nombre"] for ev in result.eventos}
        assert any("JUAN PEREZ" in n for n in names)
        assert any("ANA ROCHA" in n for n in names)

    def test_bulk_sumario_still_requiere_detalle(self) -> None:
        """Bulk sumario without individual names: no clauses, no eventos → requiere_detalle (no regression)."""
        from core.extractor import extract_eventos, ESTADO_REQUIERE_DETALLE

        result = extract_eventos("Designación de Ministros de Estado y Alto Mando Militar.")

        assert result.estado_extraccion == ESTADO_REQUIERE_DETALLE
        assert result.eventos == []

    def test_interino_clause_counted_correctly(self) -> None:
        """'como INTERINO Viceministro' is still counted as one clause."""
        from core.extractor import extract_eventos, ESTADO_PROCESADO

        result = extract_eventos(
            "Designa al ciudadano LUIS MAMANI como INTERINO Viceministro de Obras."
        )

        assert result.estado_extraccion == ESTADO_PROCESADO
        assert len(result.eventos) == 1
        assert result.eventos[0]["interino"] is True
