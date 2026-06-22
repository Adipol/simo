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


# ══════════════════════════════════════════════════════════════════════════════
# FIX Round 6 — Clause counter charclass aligned with extractor (JD Round 6)
# ══════════════════════════════════════════════════════════════════════════════

class TestExtractorRound6LowercaseCargoClause:
    """JD Round 6: _RE_APPOINTMENT_CLAUSE must count lowercase-start cargo clauses.

    Root cause (Round 5 residual): _RE_APPOINTMENT_CLAUSE required an uppercase
    cargo-start character ([A-ZÁÉÍÓÚÑÜ]).  But _RE_APPOINTMENT accepts lowercase-start
    cargo too ([A-ZÁÉÍÓÚÑÜA-Za-záéíóúñ], re.IGNORECASE).  So a co-appointee whose
    'como <cargo>' clause starts with a lowercase letter AND who lacks the
    'ciudadano/ciudadana' prefix is:
      - NOT extracted by _RE_APPOINTMENT (no prefix)
      - NOT counted by _RE_APPOINTMENT_CLAUSE (lowercase cargo-start)
    → clause_count under-counts → 1 evento == 1 clause → procesado (silent drop).

    Fix: include lowercase in the cargo-start charclass of _RE_APPOINTMENT_CLAUSE
    so it mirrors _RE_APPOINTMENT.  Over-count remains the safe direction
    (false positive → requiere_revision; false negative → silent procesado, unsafe).
    """

    def test_lowercase_cargo_no_ciudadana_routes_to_requiere_revision(self) -> None:
        """
        RED: 'y a MARIA ROCHA como ministra de Economía' — lowercase cargo, no prefix.
        _RE_APPOINTMENT_CLAUSE (before fix) only counts uppercase cargo-start:
          sees 1 clause ('como Ministro'), extracts 1 evento (JUAN PEREZ).
          1 == 1 → procesado  ← BUG: MARIA ROCHA silently dropped.
        After fix: lowercase counted → sees 2 clauses.
          1 evento < 2 clauses → requiere_revision, eventos=[].
        """
        from core.extractor import extract_eventos, ESTADO_REQUIERE_REVISION

        result = extract_eventos(
            "Designa al ciudadano JUAN PEREZ como Ministro de Salud, "
            "y a MARIA ROCHA como ministra de Economía."
        )

        assert result.estado_extraccion == ESTADO_REQUIERE_REVISION
        assert result.eventos == []

    def test_both_ciudadana_one_lowercase_cargo_still_procesado(self) -> None:
        """
        Triangulation: both appointees have 'ciudadano/ciudadana' prefix; second cargo
        is lowercase ('ministra').  _RE_APPOINTMENT (IGNORECASE) extracts both.
        After fix, _RE_APPOINTMENT_CLAUSE also counts both.
        2 extracted == 2 clauses → procesado.
        """
        from core.extractor import extract_eventos, ESTADO_PROCESADO

        result = extract_eventos(
            "Designa al ciudadano JUAN PEREZ como Ministro de Salud, "
            "y a la ciudadana ANA ROCHA como ministra de Economía."
        )

        assert result.estado_extraccion == ESTADO_PROCESADO
        assert len(result.eventos) == 2
        names = {ev["persona_nombre"] for ev in result.eventos}
        assert any("JUAN PEREZ" in n for n in names)
        assert any("ANA ROCHA" in n for n in names)

    def test_uppercase_cargo_no_ciudadana_still_requiere_revision(self) -> None:
        """
        Regression guard: uppercase cargo + no prefix case (Round 5 base case)
        must remain requiere_revision after the lowercase extension.
        """
        from core.extractor import extract_eventos, ESTADO_REQUIERE_REVISION

        result = extract_eventos(
            "Designa al ciudadano JUAN PEREZ como Ministro de Salud, "
            "y a MARIA ROCHA como Ministra de Economía."
        )

        assert result.estado_extraccion == ESTADO_REQUIERE_REVISION
        assert result.eventos == []


# ══════════════════════════════════════════════════════════════════════════════
# V1 Accepted Limitations — regression anchors (JD Round 6)
# ══════════════════════════════════════════════════════════════════════════════

class TestExtractorV1AcceptedLimitations:
    """Regression anchors for accepted V1 limitations.

    These tests pin CURRENT, INTENTIONAL behavior for patterns that V1 does not
    handle.  A future refactor changing these outcomes will cause a test failure,
    prompting the developer to confirm the change is deliberate.

    DO NOT modify these assertions silently.  Each one has a docstring that
    explains the limitation and what a V2 fix would require.
    """

    def test_comma_style_co_appointment_v1_accepted_silent_drop(self) -> None:
        """
        ACCEPTED V1 LIMITATION: comma-style co-appointment with NEITHER
        'ciudadano/ciudadana' prefix NOR a 'como <Cargo>' clause is silently
        dropped.  The decree returns procesado with only the first appointee.

        Pattern: "Designa al ciudadano JUAN PEREZ como Ministro de Salud,
                  y a MARIA ROCHA, Ministra de Economía."

        Why this is accepted in V1:
        - _RE_APPOINTMENT cannot extract MARIA ROCHA: no 'ciudadano/ciudadana' prefix.
        - _RE_APPOINTMENT_CLAUSE cannot count her: no 'como <Cargo>' clause —
          the comma style uses 'ROCHA, Ministra', not 'ROCHA como Ministra'.
        - Completeness guard: 1 clause counted, 1 evento extracted → 1 == 1 → procesado.
          MARIA ROCHA is invisible to both the extractor and the counter.

        V2 requirement to fix: add a comma-style clause counter that recognises
        '<NAME>, <Cargo>' as an appointment structure, then route through the guard.

        DO NOT change this assertion without a V2 design decision for comma-style
        parsing.  If a future change causes this test to return requiere_revision,
        that is the desired new behavior — update this test and the docstring.
        """
        from core.extractor import extract_eventos, ESTADO_PROCESADO

        sumario = (
            "Designa al ciudadano JUAN PEREZ como Ministro de Salud, "
            "y a MARIA ROCHA, Ministra de Economía."
        )
        result = extract_eventos(sumario)

        # V1 accepted: MARIA ROCHA is dropped because no 'ciudadano' prefix
        # and no 'como <Cargo>' clause — the counter sees only 1 clause,
        # which matches the 1 extracted evento, so the guard passes as procesado.
        assert result.estado_extraccion == ESTADO_PROCESADO
        assert len(result.eventos) == 1
        assert "JUAN PEREZ" in result.eventos[0]["persona_nombre"]


# ══════════════════════════════════════════════════════════════════════════════
# FIX Round 7 — Dual completeness signal: max(clause_count, ciudadano_count)
#                + re.IGNORECASE on _RE_APPOINTMENT_CLAUSE (JD Round 7)
# ══════════════════════════════════════════════════════════════════════════════

class TestExtractorRound7DualCompletenessSignal:
    """JD Round 7 structural close: dual completeness guard.

    Two independent evadable paths are closed simultaneously:

    PATH 1 — Non-'como' connector with 'ciudadano' prefix:
        e.g. 'al ciudadano LUIS GOMEZ en calidad de Viceministro'
        - _RE_APPOINTMENT cannot extract (requires 'como' connector).
        - _RE_APPOINTMENT_CLAUSE cannot count (requires 'como' clause).
        - Old guard: clause_count == eventos → procesado (silent drop).
        - Fix: ciudadano token counter runs alongside clause counter;
          threshold = max(clause_count, ciudadano_count).
          ciudadano_count=2 > clause_count=1 → threshold=2 > eventos=1 → requiere_revision.

    PATH 2 — Case asymmetry: _RE_APPOINTMENT_CLAUSE lacked re.IGNORECASE:
        'Como'/'COMO' clauses were NOT counted by the clause counter, while
        _RE_APPOINTMENT (IGNORECASE) could still extract via them.
        - Clause counter under-counts → procesado (silent drop of broken-name appointee).
        - Fix: add re.IGNORECASE to _RE_APPOINTMENT_CLAUSE.

    Invariant after this fix:
        The ONLY path to 'procesado' with a silently-dropped appointee is when
        that appointee has NEITHER a 'ciudadano/ciudadana' prefix token NOR a
        'como <cargo>' clause in ANY casing — the accepted V1 comma-style
        residual ('y a MARIA ROCHA, Ministra de Economía'), already pinned in
        TestExtractorV1AcceptedLimitations.
    """

    def test_en_calidad_de_connector_ciudadano_prefix_routes_to_requiere_revision(self) -> None:
        """
        RED: 'al ciudadano LUIS GOMEZ en calidad de Viceministro' — ciudadano prefix
        present but non-'como' connector ('en calidad de').
        - _RE_APPOINTMENT cannot extract LUIS GOMEZ (no 'como' connector).
        - _RE_APPOINTMENT_CLAUSE cannot count him (no 'como' clause).
        - Today: clause_count=1, no ciudadano counter → threshold=1, eventos=1 → procesado.
        - After fix: ciudadano_count=2, threshold=max(1,2)=2, eventos=1 < 2 → requiere_revision.

        This is the exact case from the root-insight report.
        FAILS today (returns procesado with LUIS GOMEZ silently dropped).
        """
        from core.extractor import extract_eventos, ESTADO_REQUIERE_REVISION

        sumario = (
            "Designa al ciudadano JUAN PEREZ como Ministro de Salud, "
            "y al ciudadano LUIS GOMEZ en calidad de Viceministro de Salud."
        )
        result = extract_eventos(sumario)

        assert result.estado_extraccion == ESTADO_REQUIERE_REVISION
        assert result.eventos == []

    def test_allcaps_como_co_appointment_routes_to_requiere_revision(self) -> None:
        """
        RED: both appointees have 'ciudadano' prefix; both use all-caps 'COMO' connector.
        JUAN P. PEREZ has a middle initial so _RE_APPOINTMENT fails on him.
        - Without IGNORECASE on clause counter: 'COMO' not counted → clause_count=0.
        - LUIS GOMEZ IS extracted by _RE_APPOINTMENT (IGNORECASE matches 'COMO').
        - Today: eventos=1 >= clause_count=0 → procesado (JUAN P. PEREZ dropped).
        - After fix (IGNORECASE + ciudadano counter):
            clause_count=2 (COMO matched), ciudadano_count=2 → threshold=2 > eventos=1
            → requiere_revision, eventos=[].

        This is the case-asymmetry gap reported in the root insight.
        FAILS today (returns procesado with JUAN P. PEREZ silently dropped).
        """
        from core.extractor import extract_eventos, ESTADO_REQUIERE_REVISION

        sumario = (
            "Designa al ciudadano JUAN P. PEREZ COMO Ministro de Salud, "
            "y al ciudadano LUIS GOMEZ COMO Viceministro de Salud."
        )
        result = extract_eventos(sumario)

        assert result.estado_extraccion == ESTADO_REQUIERE_REVISION
        assert result.eventos == []

    def test_single_allcaps_como_clean_appointment_still_procesado(self) -> None:
        """
        Triangulation (GREEN regression): single clean appointment using 'COMO' (all-caps).
        After fix: IGNORECASE counts 'COMO Viceministro' (clause_count=1) AND
        ciudadano_count=1 → threshold=1, eventos=1 → procesado.
        Must NOT be broken by the dual-signal change.
        """
        from core.extractor import extract_eventos, ESTADO_PROCESADO

        result = extract_eventos(
            "Designa al ciudadano LUIS GOMEZ COMO Viceministro de Salud."
        )

        assert result.estado_extraccion == ESTADO_PROCESADO
        assert len(result.eventos) == 1
        assert "LUIS GOMEZ" in result.eventos[0]["persona_nombre"]

    def test_comma_style_residual_pin_intact_after_dual_signal(self) -> None:
        """
        Regression anchor: the accepted V1 comma-style residual MUST remain procesado.
        MARIA ROCHA has NEITHER 'ciudadano' prefix NOR 'como <cargo>' clause:
          - ciudadano_count=1 (JUAN PEREZ only), clause_count=1 ('como Ministro')
          - threshold = max(1, 1) = 1
          - eventos=1 (JUAN PEREZ) → 1 >= 1 → procesado.
        Verifies the dual-signal threshold does NOT break the accepted pin.
        """
        from core.extractor import extract_eventos, ESTADO_PROCESADO

        sumario = (
            "Designa al ciudadano JUAN PEREZ como Ministro de Salud, "
            "y a MARIA ROCHA, Ministra de Economía."
        )
        result = extract_eventos(sumario)

        assert result.estado_extraccion == ESTADO_PROCESADO
        assert len(result.eventos) == 1
        assert "JUAN PEREZ" in result.eventos[0]["persona_nombre"]


# ══════════════════════════════════════════════════════════════════════════════
# REAL BOLIVIAN GAZETTE LANGUAGE — rewrite for real appointment lexicon
# Source: verified 109-decree corpus from gaceta.diputados.bo
# All sumarios in this section are REAL (not fabricated).
# ══════════════════════════════════════════════════════════════════════════════


class TestRealDatePrefixStripping:
    """Real sumarios start with 'DD DE MES DE YYYY .- ' that must be stripped.

    Current extractor anchors trigger to '^' (start of string).  A date prefix
    blocks trigger detection, returning requiere_revision for ALL real decrees.

    Fix: _preprocess() strips the date prefix before trigger/extraction.
    """

    def test_pattern_a_with_date_prefix_returns_procesado(self) -> None:
        """Verified real Pattern A sumario with date prefix.

        Source: corpus page -- decreto de designacion ordinaria (2026-06-10).
        RED: currently returns requiere_revision (date prefix blocks trigger).
        """
        from core.extractor import extract_eventos, ESTADO_PROCESADO

        sumario = (
            "10 DE JUNIO DE 2026 .- Designa al ciudadano RICARDO ERICK SANJINES CHAVEZ, "
            "como MINISTRO DE EDUCACION, quien tomara posesion del cargo en el dia, "
            "en acto especial."
        )
        result = extract_eventos(sumario)

        assert result.estado_extraccion == ESTADO_PROCESADO
        assert len(result.eventos) == 1
        ev = result.eventos[0]
        assert "SANJINES CHAVEZ" in ev["persona_nombre"]
        assert "MINISTRO" in ev["cargo"]
        assert ev["interino"] is False
        assert ev["tipo_evento"] == "designacion"

    def test_bulk_alto_mando_with_date_prefix_returns_requiere_detalle(self) -> None:
        """Verified real bulk sumario with date prefix.

        Source: corpus -- Designacion del Alto Mando Militar (2026-06-18).
        RED: currently returns requiere_revision (date prefix blocks trigger).
        After fix: trigger detects 'Designacion del', no name found -> requiere_detalle.
        """
        from core.extractor import extract_eventos, ESTADO_REQUIERE_DETALLE

        sumario = "18 DE JUNIO DE 2026 .- Designacion del Alto Mando Militar."

        result = extract_eventos(sumario)

        assert result.estado_extraccion == ESTADO_REQUIERE_DETALLE
        assert result.eventos == []


class TestRealPatternACommaSeparator:
    """Pattern A real structure has ', como' (comma then space) not ' como'.

    Current _RE_APPOINTMENT uses 'space+como' which requires whitespace before 'como'.
    In real Bolivian gazette text, the name is followed by ', como' (comma separator).

    Fix: change 'space+como' to '[,space]+como' in _RE_APPOINTMENT.
    """

    def test_pattern_a_comma_before_como_procesado(self) -> None:
        """Pattern A with ', como' separator (real gazette style).

        RED: currently returns requiere_detalle -- name stops at ',' but then
        space+como can't match ', como' (comma is not whitespace).
        """
        from core.extractor import extract_eventos, ESTADO_PROCESADO

        sumario = (
            "Designa al ciudadano RICARDO ERICK SANJINES CHAVEZ, "
            "como MINISTRO DE EDUCACION, quien tomara posesion del cargo en el dia."
        )
        result = extract_eventos(sumario)

        assert result.estado_extraccion == ESTADO_PROCESADO
        assert len(result.eventos) == 1
        ev = result.eventos[0]
        assert "SANJINES CHAVEZ" in ev["persona_nombre"]
        assert "MINISTRO" in ev["cargo"]
        assert ev["interino"] is False

    def test_pattern_a_space_before_como_still_works_no_regression(self) -> None:
        """Regression: existing style 'NAME como CARGO' (space, no comma) must still work."""
        from core.extractor import extract_eventos, ESTADO_PROCESADO

        result = extract_eventos(
            "Designa al ciudadano PEDRO QUISPE MAMANI como Ministro de Salud."
        )

        assert result.estado_extraccion == ESTADO_PROCESADO
        assert len(result.eventos) == 1
        assert "QUISPE MAMANI" in result.eventos[0]["persona_nombre"]


class TestRealPatternBDesignese:
    """Pattern B (cargo-first): 'Designese <CARGO>, al ciudadano <Name>'.

    Dominant pattern (~63%) in the real corpus.  Trigger verb 'Designese'
    (with 'e' variant) is NOT matched by current trigger ('Des[ii]gnase' only).

    The structure is cargo-first: role before name; names are often Title Case.

    Fix: (1) add 'Designese' to trigger; (2) add Pattern B regex; (3) detect
    interino from cargo text or 'mientras dure la ausencia'.
    """

    def test_designese_cargo_first_title_case_name(self) -> None:
        """Verified real Pattern B sumario (no date prefix).

        Source: corpus page -- decreto de designacion interina (2026-06-20).
        RED: 'Designese' not in trigger -> requiere_revision.
        """
        from core.extractor import extract_eventos, ESTADO_PROCESADO

        sumario = (
            "Designese MINISTRO INTERINO DE RELACIONES EXTERIORES, "
            "al ciudadano Jose Luis Lupo Flores, Ministro de la Presidencia, "
            "mientras dure la ausencia del titular."
        )
        result = extract_eventos(sumario)

        assert result.estado_extraccion == ESTADO_PROCESADO
        assert len(result.eventos) == 1
        ev = result.eventos[0]
        assert "Lupo Flores" in ev["persona_nombre"]
        assert "RELACIONES EXTERIORES" in ev["cargo"]
        assert ev["interino"] is True

    def test_real_pattern_b_with_date_prefix(self) -> None:
        """Verified real Pattern B with date prefix (full real sumario from corpus).

        Source: corpus -- 2026-06-20.
        RED: date prefix blocks trigger AND 'Designese' not recognized.
        """
        from core.extractor import extract_eventos, ESTADO_PROCESADO

        sumario = (
            "20 DE JUNIO DE 2026 .- Designese MINISTRO INTERINO DE RELACIONES EXTERIORES, "
            "al ciudadano Jose Luis Lupo Flores, Ministro de la Presidencia, "
            "mientras dure la ausencia del titular."
        )
        result = extract_eventos(sumario)

        assert result.estado_extraccion == ESTADO_PROCESADO
        assert len(result.eventos) == 1
        ev = result.eventos[0]
        assert "Lupo Flores" in ev["persona_nombre"]
        assert "RELACIONES EXTERIORES" in ev["cargo"]
        assert ev["interino"] is True

    def test_designese_interino_flag_from_mientras_dure(self) -> None:
        """'mientras dure la ausencia' -> interino=True even without INTERINO in cargo."""
        from core.extractor import extract_eventos

        sumario = (
            "Designese DIRECTOR GENERAL DE TELECOMUNICACIONES, "
            "al ciudadano Carlos Pinto Rios, Director de Servicios, "
            "mientras dure la ausencia del titular."
        )
        result = extract_eventos(sumario)

        assert result.estado_extraccion == "procesado"
        assert len(result.eventos) == 1
        assert result.eventos[0]["interino"] is True

    def test_designese_no_interino_keyword_no_ausencia_interino_false(self) -> None:
        """Pattern B without 'INTERINO' in cargo and without 'ausencia' -> interino=False."""
        from core.extractor import extract_eventos

        sumario = (
            "Designese DIRECTOR EJECUTIVO DE ADUANA NACIONAL, "
            "al ciudadano Pablo Rodrigo Flores Quispe."
        )
        result = extract_eventos(sumario)

        assert result.estado_extraccion == "procesado"
        assert len(result.eventos) == 1
        assert result.eventos[0]["interino"] is False

    def test_title_case_name_normalizado_lowercase_no_accents(self) -> None:
        """Pattern B name 'Jose Luis' -> normalizado is lowercase without accents."""
        from core.extractor import extract_eventos

        sumario = (
            "Designese MINISTRO INTERINO DE HACIENDA, "
            "al ciudadano Jose Luis Rios Mamani, Ministro de Obras, "
            "mientras dure la ausencia del titular."
        )
        result = extract_eventos(sumario)

        assert result.estado_extraccion == "procesado"
        ev = result.eventos[0]
        norm = ev["persona_nombre_normalizado"]
        assert norm == norm.lower()
        assert "jose" in norm
        assert "rios" in norm


class TestRealNewTriggerVerbs:
    """'Designar' (infinitive) and 'Se designa' are real gazette trigger verbs.

    Current trigger has 'Designa' with word boundary requiring non-word after 'a'.
    In 'Designar', 'r' follows 'a' (word char) -> boundary does NOT fire -> no match.
    'Se designa' is also not in the current trigger.

    Fix: add 'Designar' and 'Se designa' to _RE_DESIGNA_TRIGGER.
    """

    def test_designar_infinitive_pattern_a(self) -> None:
        """'Designar al ciudadano NAME, como CARGO' -> procesado.

        RED: 'Designar' not in trigger -> requiere_revision.
        """
        from core.extractor import extract_eventos, ESTADO_PROCESADO

        result = extract_eventos(
            "Designar al ciudadano MARIO ANTONIO SILVA PEREZ, como VICEMINISTRO DE TRANSPORTE."
        )

        assert result.estado_extraccion == ESTADO_PROCESADO
        assert len(result.eventos) == 1
        ev = result.eventos[0]
        assert "SILVA PEREZ" in ev["persona_nombre"]
        assert "VICEMINISTRO" in ev["cargo"]
        assert ev["interino"] is False

    def test_se_designa_pattern_a(self) -> None:
        """'Se designa al ciudadano NAME, como CARGO' -> procesado.

        RED: 'Se designa' not in trigger -> requiere_revision.
        """
        from core.extractor import extract_eventos, ESTADO_PROCESADO

        result = extract_eventos(
            "Se designa al ciudadano PABLO QUISPE MAMANI, como DIRECTOR GENERAL DE ADUANAS."
        )

        assert result.estado_extraccion == ESTADO_PROCESADO
        assert len(result.eventos) == 1
        assert "QUISPE MAMANI" in result.eventos[0]["persona_nombre"]


class TestRealPatternBCargoFirstDesigna:
    """Variant: 'Designa CARGO, al ciudadano NAME' (Designa + cargo-first).

    Real gazette variant where 'Designa' is followed directly by a CARGO (not
    by 'al ciudadano').  Current extractor only has Pattern A ('al ciudadano
    NAME como CARGO'), so a cargo-first 'Designa CARGO, al ciudadano NAME'
    returns requiere_detalle (trigger fires but no Pattern A match found).

    Fix: Pattern B regex also matches 'Designa <UPPERCASE-CARGO>, al ciudadano NAME'.
    """

    def test_designa_cargo_first_interino(self) -> None:
        """'Designa MINISTRO INTERINO DE X, al ciudadano NAME' -> procesado, interino=True.

        RED: trigger fires ('Designa') but Pattern A finds no match (no 'como') ->
        requiere_detalle.
        """
        from core.extractor import extract_eventos, ESTADO_PROCESADO

        sumario = (
            "Designa MINISTRO INTERINO DE ECONOMIA Y FINANZAS PUBLICAS, "
            "al ciudadano Roberto Arce Villegas, Ministro de Planificacion, "
            "mientras dure la ausencia del titular."
        )
        result = extract_eventos(sumario)

        assert result.estado_extraccion == ESTADO_PROCESADO
        assert len(result.eventos) == 1
        ev = result.eventos[0]
        assert "Arce Villegas" in ev["persona_nombre"]
        assert "ECONOMIA" in ev["cargo"]
        assert ev["interino"] is True


class TestRealRatificaRegression:
    """Regression anchors: non-appointment real sumarios must remain requiere_revision.

    These already pass but are pinned here to guard against regressions.
    """

    def test_ratifica_designacion_real_sumario_requiere_revision(self) -> None:
        """Real 'Ratifica la designacion...' -> requiere_revision (already correct).

        Source: real corpus example -- ratification is flagged for human review.
        """
        from core.extractor import extract_eventos, ESTADO_REQUIERE_REVISION

        sumario = (
            "Ratifica la designacion del ciudadano GUSTAVO ANTONIO AVILA MERCADO, "
            "como VOCAL DEL TRIBUNAL ELECTORAL DEPARTAMENTAL DE SANTA CRUZ."
        )
        result = extract_eventos(sumario)

        assert result.estado_extraccion == ESTADO_REQUIERE_REVISION
        assert result.eventos == []


class TestRealFeminineArticleForm:
    """Pattern B: 'a la ciudadana' (feminine article) must be extracted.

    Real corpus shows ~30-40% of Pattern B appointments use 'a la ciudadana'
    (feminine) instead of 'al ciudadano' (masculine).  The original Pattern B
    regex had 'al?' which matches 'a' or 'al' but NOT 'a la' (the article 'la'
    was not in the pattern).

    Fix: extend the Pattern B article group to '(?:la\\s+|el\\s+)?' AFTER 'al?',
    so 'al ciudadano', 'a la ciudadana', and 'a el ciudadano' all match.

    These tests FAIL before the fix (returns requiere_detalle -- cargo found,
    Pattern B trigger fires, but name not extracted because article not matched).
    """

    def test_designese_a_la_ciudadana_feminine_extracts(self) -> None:
        """'Designese CARGO, a la ciudadana Name' -> procesado (feminine pattern).

        Source: real corpus -- MINISTRA INTERINA DE EDUCACION decree (2026-04-14).
        """
        from core.extractor import extract_eventos, ESTADO_PROCESADO

        sumario = (
            "Designese MINISTRA INTERINA DE EDUCACION, "
            "a la ciudadana Marcela Tatiana Flores Zambrana, "
            "Ministra de Salud, mientras dure la ausencia del titular."
        )
        result = extract_eventos(sumario)

        assert result.estado_extraccion == ESTADO_PROCESADO
        assert len(result.eventos) == 1
        ev = result.eventos[0]
        assert "Flores Zambrana" in ev["persona_nombre"]
        assert "MINISTRA INTERINA" in ev["cargo"] or "EDUCACION" in ev["cargo"]
        assert ev["interino"] is True

    def test_designese_a_la_ciudadana_with_date_prefix(self) -> None:
        """Full real sumario: date prefix + 'a la ciudadana' -> procesado."""
        from core.extractor import extract_eventos, ESTADO_PROCESADO

        sumario = (
            "14 DE ABRIL DE 2026 .- Designese MINISTRA INTERINA DE LA PRESIDENCIA, "
            "a la ciudadana Cinthya Martha Yanez Eid, Ministra de Educacion, "
            "mientras dure la ausencia del titular."
        )
        result = extract_eventos(sumario)

        assert result.estado_extraccion == ESTADO_PROCESADO
        assert len(result.eventos) == 1
        ev = result.eventos[0]
        assert "Yanez Eid" in ev["persona_nombre"]
        assert ev["interino"] is True

    def test_pattern_b_al_ciudadano_still_works_no_regression(self) -> None:
        """Regression: 'al ciudadano' (masculine) must still work after feminine fix."""
        from core.extractor import extract_eventos, ESTADO_PROCESADO

        result = extract_eventos(
            "Designese MINISTRO INTERINO DE HACIENDA, "
            "al ciudadano Carlos Pinto Rios, mientras dure la ausencia del titular."
        )

        assert result.estado_extraccion == ESTADO_PROCESADO
        assert len(result.eventos) == 1
        assert "Pinto Rios" in result.eventos[0]["persona_nombre"]


class TestRealMultiCommaCargo:
    """Pattern B: cargo names with internal commas must be fully extracted.

    Real Bolivian ministry names contain comma-separated sub-parts:
      'MINISTRO INTERINO DE OBRAS PUBLICAS, SERVICIOS Y VIVIENDA'
      'MINISTRO INTERINO DE TURISMO SOSTENIBLE, CULTURAS, FOLKLORE Y GASTRONOMIA'

    The previous Pattern B cargo charclass '[A-ZÁÉÍÓÚÑÜ\\s]+?' stopped at the
    FIRST comma in the name, so the ','  could never reach 'al ciudadano' and
    Pattern B extraction failed (returned requiere_detalle).

    Fix: add ',' to the cargo charclass: '[A-ZÁÉÍÓÚÑÜ\\s,]+?'.
    The non-greedy '+?' ensures it stops at the comma-before-'al ciudadano'.
    """

    def test_multi_comma_cargo_obras_publicas(self) -> None:
        """Cargo 'OBRAS PUBLICAS, SERVICIOS Y VIVIENDA' with internal comma -> procesado.

        Source: real corpus pattern -- ministry with comma-separated departments.
        RED before fix: cargo stops at first comma, Pattern B fails -> requiere_detalle.
        """
        from core.extractor import extract_eventos, ESTADO_PROCESADO

        sumario = (
            "Designese MINISTRO INTERINO DE OBRAS PUBLICAS, SERVICIOS Y VIVIENDA, "
            "al ciudadano Oscar Mario Justiniano Urenda, Ministro de Planificacion, "
            "mientras dure la ausencia del titular."
        )
        result = extract_eventos(sumario)

        assert result.estado_extraccion == ESTADO_PROCESADO
        assert len(result.eventos) == 1
        ev = result.eventos[0]
        assert "Justiniano Urenda" in ev["persona_nombre"]
        assert "OBRAS PUBLICAS" in ev["cargo"]
        assert ev["interino"] is True

    def test_multi_comma_cargo_turismo_folklore(self) -> None:
        """Cargo 'TURISMO SOSTENIBLE, CULTURAS, FOLKLORE Y GASTRONOMIA' -> procesado.

        Source: real corpus -- TURISMO ministry has three comma-separated sub-areas.
        """
        from core.extractor import extract_eventos, ESTADO_PROCESADO

        sumario = (
            "Designese MINISTRO INTERINO DE TURISMO SOSTENIBLE, CULTURAS, FOLKLORE Y GASTRONOMIA, "
            "al ciudadano Roberto Perez Quispe, Ministro de Economia, "
            "mientras dure la ausencia del titular."
        )
        result = extract_eventos(sumario)

        assert result.estado_extraccion == ESTADO_PROCESADO
        assert len(result.eventos) == 1
        ev = result.eventos[0]
        assert "Perez Quispe" in ev["persona_nombre"]
        assert "TURISMO" in ev["cargo"]
        assert ev["interino"] is True

    def test_single_part_cargo_still_works_no_regression(self) -> None:
        """Regression: cargo without internal commas still extracts correctly."""
        from core.extractor import extract_eventos, ESTADO_PROCESADO

        result = extract_eventos(
            "Designese MINISTRO INTERINO DE RELACIONES EXTERIORES, "
            "al ciudadano Jose Luis Lupo Flores, mientras dure la ausencia del titular."
        )

        assert result.estado_extraccion == ESTADO_PROCESADO
        assert len(result.eventos) == 1
        assert "RELACIONES EXTERIORES" in result.eventos[0]["cargo"]


# ══════════════════════════════════════════════════════════════════════════════
# FIX Round 8 — Pattern A full cargo (no "de <entidad>" split)
# Source: real Bolivian gazette sumarios — verified corpus
# ══════════════════════════════════════════════════════════════════════════════


class TestPatternAFullCargo:
    """Pattern A (name-first) must capture the FULL cargo title up to the
    terminating comma/period — the "de <entidad>" portion is INTRINSIC to
    Bolivian ministerial titles, not a separable entity qualifier.

    Bug: the non-greedy cargo group + optional entidad split in _RE_APPOINTMENT
    causes "MINISTRO DE EDUCACIÓN" → cargo="MINISTRO", entidad="EDUCACIÓN".

    Fix: make cargo greedy ([^,\\.\\n;]+, no entidad split), set entidad=None
    for all Pattern A matches.

    These tests FAIL before the fix because the current regex truncates cargo.
    """

    def test_ministro_de_educacion_full_cargo(self) -> None:
        """Real sumario: cargo must be 'MINISTRO DE EDUCACIÓN', not 'MINISTRO'.

        Source: corpus 2026-06-10 — decreto de designacion ordinaria.
        RED: currently cargo='MINISTRO' (DE EDUCACION lands in entidad group).
        """
        from core.extractor import extract_eventos, ESTADO_PROCESADO

        sumario = (
            "10 DE JUNIO DE 2026 .- Designa al ciudadano RICARDO ERICK SANJINES CHAVEZ, "
            "como MINISTRO DE EDUCACION, quien tomara posesion del cargo en el dia, "
            "en acto especial."
        )
        result = extract_eventos(sumario)

        assert result.estado_extraccion == ESTADO_PROCESADO
        assert len(result.eventos) == 1
        ev = result.eventos[0]
        assert ev["cargo"] == "MINISTRO DE EDUCACION"
        assert ev["interino"] is False

    def test_vocal_tribunal_electoral_full_cargo(self) -> None:
        """Real sumario: multi-word cargo with 'DEL ... DE ...' must come through whole.

        Source: corpus 2026-03-20 — decreto VOCAL TEP SANTA CRUZ.
        RED: current regex stops at first 'de', leaving only 'VOCAL'.
        """
        from core.extractor import extract_eventos, ESTADO_PROCESADO

        sumario = (
            "20 DE MARZO DE 2026 .- Designa a la ciudadana YAJAIRA SAN MARTIN CRESPO, "
            "como VOCAL DEL TRIBUNAL ELECTORAL DEPARTAMENTAL DE SANTA CRUZ, "
            "en representacion del Organo Ejecutivo, quien tomara posesion del cargo."
        )
        result = extract_eventos(sumario)

        assert result.estado_extraccion == ESTADO_PROCESADO
        assert len(result.eventos) == 1
        ev = result.eventos[0]
        assert ev["cargo"] == "VOCAL DEL TRIBUNAL ELECTORAL DEPARTAMENTAL DE SANTA CRUZ"
        assert ev["interino"] is False

    def test_ministro_de_defensa_full_cargo(self) -> None:
        """Real sumario: two-word cargo 'MINISTRO DE DEFENSA' must not split.

        Source: corpus 2026-06-03 — decreto designacion DEFENSA.
        RED: current regex sets cargo='MINISTRO', entidad='DEFENSA'.
        """
        from core.extractor import extract_eventos, ESTADO_PROCESADO

        sumario = (
            "03 DE JUNIO DE 2026 .- Designa al ciudadano ERNESTO JUSTINIANO URENDA, "
            "como MINISTRO DE DEFENSA, quien tomara posesion del cargo en el dia, "
            "en acto especial."
        )
        result = extract_eventos(sumario)

        assert result.estado_extraccion == ESTADO_PROCESADO
        assert len(result.eventos) == 1
        ev = result.eventos[0]
        assert ev["cargo"] == "MINISTRO DE DEFENSA"
        assert ev["interino"] is False

    def test_interino_cargo_full_not_truncated(self) -> None:
        """Pattern A INTERINO: cargo must be full 'INTERINO Viceministro de Energías',
        and interino flag must remain True after the cargo-group change.

        Triangulation: different cargo that also contains 'de' — must not split.
        """
        from core.extractor import extract_eventos, ESTADO_PROCESADO

        result = extract_eventos(
            "Designa al ciudadano JUAN CARLOS MAMANI QUISPE "
            "como INTERINO Viceministro de Energias."
        )

        assert result.estado_extraccion == ESTADO_PROCESADO
        assert len(result.eventos) == 1
        ev = result.eventos[0]
        assert "Viceministro de Energias" in ev["cargo"]
        assert ev["interino"] is True


# ══════════════════════════════════════════════════════════════════════════════
# Pattern B — Referenced titular cargo (cargo_referenciado)
# Source: real Bolivia gazette — Lupo Flores example + corpus triangulation
# ══════════════════════════════════════════════════════════════════════════════


class TestPatternBCargoReferenciado:
    """Pattern B interim decrees carry a 'referenced titular cargo' for the appointee.

    Structure: '...al ciudadano <Name>, <Cargo Titular>, mientras dure la ausencia...'
    The <Cargo Titular> is Title Case, sitting between the name-comma and
    ', mientras dure'. It is the appointee's PERMANENT role mentioned as context.

    Spec:
    - cargo_referenciado is captured for Pattern B when the titular phrase is present.
    - Pattern A events always get cargo_referenciado=None.
    - Pattern B events without the titular phrase also get cargo_referenciado=None.
    """

    def test_pattern_b_lupo_captures_cargo_referenciado(self) -> None:
        """Verified real sumario: 'Ministro de la Presidencia' is Lupo's titular cargo.

        Source: real Bolivia gazette 2026-06-20.
        RED: currently cargo_referenciado key is absent from evento dict.
        """
        from core.extractor import extract_eventos, ESTADO_PROCESADO

        sumario = (
            "Designese MINISTRO INTERINO DE RELACIONES EXTERIORES, "
            "al ciudadano Jose Luis Lupo Flores, Ministro de la Presidencia, "
            "mientras dure la ausencia del titular."
        )
        result = extract_eventos(sumario)

        assert result.estado_extraccion == ESTADO_PROCESADO
        assert len(result.eventos) == 1
        assert result.eventos[0]["cargo_referenciado"] == "Ministro de la Presidencia"

    def test_pattern_b_without_titular_phrase_cargo_referenciado_none(self) -> None:
        """Pattern B with 'mientras dure' but no titular cargo phrase -> None.

        Structure: '...al ciudadano Carlos Pinto Rios, mientras dure...'
        No Title Case cargo between the name and 'mientras dure'.
        """
        from core.extractor import extract_eventos

        sumario = (
            "Designese MINISTRO INTERINO DE HACIENDA, "
            "al ciudadano Carlos Pinto Rios, "
            "mientras dure la ausencia del titular."
        )
        result = extract_eventos(sumario)

        assert result.estado_extraccion == "procesado"
        assert result.eventos[0]["cargo_referenciado"] is None

    def test_pattern_b_no_interino_no_ausencia_cargo_referenciado_none(self) -> None:
        """Pattern B without 'mientras dure' (permanent appointment) -> None.

        No interim phrase means no titular context to extract.
        """
        from core.extractor import extract_eventos

        sumario = (
            "Designese DIRECTOR EJECUTIVO DE ADUANA NACIONAL, "
            "al ciudadano Pablo Rodrigo Flores Quispe."
        )
        result = extract_eventos(sumario)

        assert result.estado_extraccion == "procesado"
        assert result.eventos[0]["cargo_referenciado"] is None

    def test_pattern_a_permanent_cargo_referenciado_none(self) -> None:
        """Pattern A (name-first) always has cargo_referenciado=None.

        Pattern A structure has no ', <Titular>, mientras dure' tail.
        """
        from core.extractor import extract_eventos

        sumario = (
            "Designa al ciudadano RICARDO ERICK SANJINES CHAVEZ, "
            "como MINISTRO DE EDUCACION, quien tomara posesion del cargo en el dia."
        )
        result = extract_eventos(sumario)

        assert result.estado_extraccion == "procesado"
        assert result.eventos[0]["cargo_referenciado"] is None

    def test_pattern_b_with_date_prefix_captures_cargo_referenciado(self) -> None:
        """Full real sumario with date prefix also captures cargo_referenciado.

        Triangulation: ensure preprocessing does not interfere with the extraction.
        """
        from core.extractor import extract_eventos

        sumario = (
            "20 DE JUNIO DE 2026 .- Designese MINISTRO INTERINO DE RELACIONES EXTERIORES, "
            "al ciudadano Jose Luis Lupo Flores, Ministro de la Presidencia, "
            "mientras dure la ausencia del titular."
        )
        result = extract_eventos(sumario)

        assert result.estado_extraccion == "procesado"
        assert result.eventos[0]["cargo_referenciado"] == "Ministro de la Presidencia"


# ══════════════════════════════════════════════════════════════════════════════
# GAP 1 — "en el cargo de" connector (Round 9)
#
# Pattern A accepts 'como <CARGO>' as the connector between name and cargo.
# Real Bolivian gazette military/police appointments also use the connector
# 'en el cargo de' instead of 'como'.  This causes requiere_detalle because
# _RE_APPOINTMENT only recognises 'como'.
#
# Fix: extend the connector alternation in _RE_APPOINTMENT to accept both
# 'como' and 'en el cargo de'.
#
# Source notes:
# - Pure 'en el cargo de' (no rank prefix) — pattern documented in task spec;
#   network was unreachable during live fetch so no live URL available.
# - Combined rank + 'en el cargo de' — task specification example.
# ══════════════════════════════════════════════════════════════════════════════


class TestGap1EnElCargoDe:
    """Gap 1: 'en el cargo de' connector in Pattern A.

    RED: all tests below currently return requiere_detalle because the connector
    'en el cargo de' is not recognised by _RE_APPOINTMENT.
    After fix: procesado, correct name + full cargo extracted.
    """

    def test_en_el_cargo_de_connector_clean_name_procesado(self) -> None:
        """'al ciudadano NAME, en el cargo de CARGO' → procesado.

        Pattern documented in task specification as a confirmed residual gap.
        Currently: requiere_detalle (connector not recognised → no name extracted).
        """
        from core.extractor import extract_eventos, ESTADO_PROCESADO

        sumario = (
            "Designa al ciudadano MARIO LUIS FLORES MAMANI, "
            "en el cargo de DIRECTOR GENERAL DE ADUANAS NACIONALES, "
            "quien tomará posesión del cargo."
        )
        result = extract_eventos(sumario)

        assert result.estado_extraccion == ESTADO_PROCESADO
        assert len(result.eventos) == 1
        ev = result.eventos[0]
        assert "MARIO LUIS FLORES MAMANI" in ev["persona_nombre"]
        assert "DIRECTOR GENERAL DE ADUANAS NACIONALES" in ev["cargo"]
        assert ev["interino"] is False
        assert ev["tipo_evento"] == "designacion"

    def test_en_el_cargo_de_with_date_prefix_procesado(self) -> None:
        """'en el cargo de' with real gazette date prefix → procesado.

        Triangulation: verify _preprocess() + connector fix work together.
        """
        from core.extractor import extract_eventos, ESTADO_PROCESADO

        sumario = (
            "15 DE ENERO DE 2025 .- Designa al ciudadano JOSE ANTONIO MAMANI QUISPE, "
            "en el cargo de VICEMINISTRO DE TRANSPORTES, "
            "quien tomará posesión del cargo en el día."
        )
        result = extract_eventos(sumario)

        assert result.estado_extraccion == ESTADO_PROCESADO
        assert len(result.eventos) == 1
        ev = result.eventos[0]
        assert "MAMANI QUISPE" in ev["persona_nombre"]
        assert "VICEMINISTRO DE TRANSPORTES" in ev["cargo"]

    def test_como_connector_still_works_no_regression(self) -> None:
        """Regression: 'como' connector must remain working after alternation fix."""
        from core.extractor import extract_eventos, ESTADO_PROCESADO

        result = extract_eventos(
            "Designa al ciudadano PEDRO QUISPE MAMANI, "
            "como MINISTRO DE SALUD, quien tomará posesión del cargo."
        )

        assert result.estado_extraccion == ESTADO_PROCESADO
        assert len(result.eventos) == 1
        assert "QUISPE MAMANI" in result.eventos[0]["persona_nombre"]
        assert "MINISTRO DE SALUD" in result.eventos[0]["cargo"]

    def test_en_el_cargo_de_completeness_guard_respects_ciudadano_count(self) -> None:
        """Completeness guard: 'en el cargo de' has no 'como' clause (clause_count=0),
        but ciudadano_count=1 → threshold=max(0,1)=1 → procesado with 1 evento.

        Verifies the dual-signal guard does not break the new connector.
        """
        from core.extractor import extract_eventos, ESTADO_PROCESADO

        result = extract_eventos(
            "Designa al ciudadano ANA PATRICIA ROJAS VEGA, "
            "en el cargo de DIRECTORA EJECUTIVA DEL SERVICIO NACIONAL, "
            "quien tomará posesión del cargo."
        )

        assert result.estado_extraccion == ESTADO_PROCESADO
        assert len(result.eventos) == 1
        assert "ROJAS VEGA" in result.eventos[0]["persona_nombre"]


# ══════════════════════════════════════════════════════════════════════════════
# GAP 2 — Military rank abbreviations in names (Round 9)
#
# Pattern A requires 'ciudadano/ciudadana' as the citizen prefix.  Real
# Bolivian gazette military appointments have two sub-cases:
#
#   Sub-case A: rank replaces 'ciudadano' entirely
#     "Designa al Gral. FAUSTINO MENDOZA, como COMANDANTE GENERAL..."
#     "Designa al Gral. Cmdte. RODOLFO MONTERO, como COMANDANTE GENERAL..."
#     Source: REAL — live gazette pages 30 and 45 (fetched 2026-06-22)
#
#   Sub-case B: rank appears INSIDE the name AFTER 'ciudadano'
#     "Designa al ciudadano GRAL. LIONEL VALENZUELA ROCHA,
#      en el cargo de INSPECTOR GENERAL DEL EJÉRCITO"
#     Source: task specification example (network unreachable during live fetch)
#
# Fix:
#   - Extend the citizen-prefix in _RE_APPOINTMENT to accept rank abbreviations
#     (2+ letter token + period) in place of 'ciudadano', handling sub-case A.
#   - Allow optional rank prefix token(s) at the START of the nombre group,
#     handling sub-case B.
#
# Design decision (documented):
#   Include rank abbreviation IN persona_nombre (e.g. "Gral. RODOLFO MONTERO").
#   persona_nombre_normalizado retains the normalised rank ("gral. rodolfo montero").
#   This is Option (a) — display fidelity over normalised-match purity.
#   Rationale: PEP/OPI matching uses fuzzy search; the clean surname tokens remain
#   present in the normalised field and are sufficient for deduplication.
# ══════════════════════════════════════════════════════════════════════════════


class TestGap2MilitaryRanks:
    """Gap 2: Military rank abbreviations before or replacing 'ciudadano'.

    RED: all tests below currently return requiere_detalle because the rank token
    (Gral., Gral. Cmdte., etc.) stops the name regex (contains period not in
    charclass) or replaces 'ciudadano' which the regex requires.
    After fix: procesado, name extracted.
    """

    def test_gral_replaces_ciudadano_real_page30_procesado(self) -> None:
        """REAL GAZETTE — page 30 (03/02/2020): 'al Gral. Cmdte. NAME, como CARGO'.

        Source: live-fetched from gacetaoficialdebolivia.gob.bo page 30.
        Rank 'Gral. Cmdte.' replaces 'ciudadano'; period in 'Cmdte.' breaks match.
        Currently: requiere_detalle.
        """
        from core.extractor import extract_eventos, ESTADO_PROCESADO

        sumario = (
            "03 DE FEBRERO DE 2020 .- Designa al Gral. Cmdte. "
            "RODOLFO ANTONIO MONTERO TORRICOS, "
            "como COMANDANTE GENERAL DE LA POLICÍA BOLIVIANA, "
            "quien tomará posesión del cargo en el día conforme a disposiciones legales en vigencia."
        )
        result = extract_eventos(sumario)

        assert result.estado_extraccion == ESTADO_PROCESADO
        assert len(result.eventos) == 1
        ev = result.eventos[0]
        assert "MONTERO TORRICOS" in ev["persona_nombre"]
        assert "COMANDANTE GENERAL" in ev["cargo"]
        assert ev["interino"] is False

    def test_gral_single_token_real_page45_procesado(self) -> None:
        """REAL GAZETTE — page 45 (27/12/2017): 'al Gral. NAME, como CARGO'.

        Source: live-fetched from gacetaoficialdebolivia.gob.bo page 45.
        Single rank token 'Gral.' replaces 'ciudadano'.
        Currently: requiere_detalle.
        """
        from core.extractor import extract_eventos, ESTADO_PROCESADO

        sumario = (
            "27 DE DICIEMBRE DE 2017 .- Designa al Gral. FAUSTINO ALFONSO MENDOZA ARCE, "
            "como COMANDANTE GENERAL DE LA POLICÍA BOLIVIANA, "
            "quien tomará posesión del cargo en el día conforme a disposiciones legales en vigencia."
        )
        result = extract_eventos(sumario)

        assert result.estado_extraccion == ESTADO_PROCESADO
        assert len(result.eventos) == 1
        ev = result.eventos[0]
        assert "MENDOZA ARCE" in ev["persona_nombre"]
        assert "COMANDANTE GENERAL" in ev["cargo"]
        assert ev["interino"] is False

    def test_rank_inside_ciudadano_name_procesado(self) -> None:
        """Sub-case B: rank abbreviation INSIDE nombre AFTER 'ciudadano'.

        Source: task specification example ('al ciudadano GRAL. LIONEL VALENZUELA ROCHA').
        Period in 'GRAL.' stops the nombre charclass.
        Combined with 'en el cargo de' connector (both gaps at once).
        Currently: requiere_detalle (name not extracted due to period).
        """
        from core.extractor import extract_eventos, ESTADO_PROCESADO

        sumario = (
            "Designa al ciudadano GRAL. LIONEL VALENZUELA ROCHA, "
            "en el cargo de INSPECTOR GENERAL DEL EJÉRCITO, "
            "quien tomará posesión del cargo."
        )
        result = extract_eventos(sumario)

        assert result.estado_extraccion == ESTADO_PROCESADO
        assert len(result.eventos) == 1
        ev = result.eventos[0]
        assert "LIONEL VALENZUELA ROCHA" in ev["persona_nombre"]
        assert "INSPECTOR GENERAL" in ev["cargo"]
        assert ev["interino"] is False

    def test_rank_normalizado_lowercase(self) -> None:
        """Rank prefix in persona_nombre is normalized to lowercase (no accents).

        Triangulation: verify _normalize_name handles ranks correctly.
        """
        from core.extractor import extract_eventos, ESTADO_PROCESADO

        sumario = (
            "27 DE DICIEMBRE DE 2017 .- Designa al Gral. FAUSTINO ALFONSO MENDOZA ARCE, "
            "como COMANDANTE GENERAL DE LA POLICÍA BOLIVIANA, "
            "quien tomará posesión del cargo en el día."
        )
        result = extract_eventos(sumario)

        assert result.estado_extraccion == ESTADO_PROCESADO
        norm = result.eventos[0]["persona_nombre_normalizado"]
        assert norm == norm.lower()
        assert "mendoza arce" in norm

    def test_normal_ciudadano_unaffected_no_regression(self) -> None:
        """Regression: normal 'al ciudadano NAME, como CARGO' must still work."""
        from core.extractor import extract_eventos, ESTADO_PROCESADO

        result = extract_eventos(
            "Designa al ciudadano RICARDO ERICK SANJINES CHAVEZ, "
            "como MINISTRO DE EDUCACION, quien tomará posesión del cargo."
        )

        assert result.estado_extraccion == ESTADO_PROCESADO
        assert len(result.eventos) == 1
        assert "SANJINES CHAVEZ" in result.eventos[0]["persona_nombre"]

    def test_middle_initial_still_fails_completeness_guard(self) -> None:
        """Regression: 'JUAN P. PEREZ' middle initial must NOT be treated as rank prefix.

        The rank-prefix in nombre only matches {2,}-letter tokens (like 'Gral.').
        A single-letter initial 'P.' must NOT match, so the name still fails
        to extract and the completeness guard catches it → requiere_revision.
        """
        from core.extractor import extract_eventos, ESTADO_REQUIERE_REVISION

        result = extract_eventos(
            "Designa al ciudadano JUAN P. PEREZ, como Ministro de Salud, "
            "y a la ciudadana ANA ROCHA, como Ministra de Economía."
        )

        assert result.estado_extraccion == ESTADO_REQUIERE_REVISION
        assert result.eventos == []
