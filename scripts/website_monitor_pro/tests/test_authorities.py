from __future__ import annotations

import copy
import hashlib
import json
import os
import sys
from pathlib import Path
from unittest.mock import MagicMock, patch

import pytest
import requests

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from authorities import (
    Authority,
    classify_authority_event_payload,
    compare_authorities,
    extract_authorities,
    is_valid_authority_event_payload,
)
from pep_monitor import DatabaseManager, PEPMonitor, authority_extraction_complete
from structured_authorities import extract_structured_authorities


ASUSS_HTML = (Path(__file__).parent / "fixtures" / "asuss_autoridades.html").read_text(encoding="utf-8")
COMPLETE_ASUSS_HTML = ASUSS_HTML.replace(
    '  <div class="et_pb_blurb_container">\n    <h4>Sin persona</h4>\n  </div>\n',
    "",
)
UMSA_HTML = (Path(__file__).parent / "fixtures" / "umsa_rectorado_profile.html").read_text(encoding="utf-8")
UMSA_SOURCE = {
    "id": 61,
    "url": "https://www.umsa.bo/web/guest/rectorado",
    "nombre": "UMSA Rectorado",
    "tipo": "html",
    "selector_css": None,
    "autoridades_extractor": "umsa_rectorado_profile",
    "analizar_imagenes": False,
}


def _run_umsa_monitor(db: MagicMock, html: str) -> None:
    with patch.object(DatabaseManager, "__init__", return_value=None), \
         patch("pep_monitor.create_http_session", return_value=MagicMock()), \
         patch.object(PEPMonitor, "_obtener_html_raw", return_value=(html, "html_estatico")), \
         patch("pep_monitor.mostrar_alerta"), \
         patch("pep_monitor.hashlib.sha256") as sha:
        sha.side_effect = lambda value: MagicMock(hexdigest=lambda: f"hash-{value.decode('utf-8')}")
        monitor = PEPMonitor()
        monitor.db = db
        monitor.procesar_fuente(UMSA_SOURCE)


def test_umsa_rectorado_extracts_only_the_explicit_profile_header() -> None:
    authorities = extract_authorities(UMSA_HTML, "umsa_rectorado_profile")

    assert [authority.to_dict() for authority in authorities] == [{
        "cargo": "Rectora UMSA",
        "persona": "María Eugenia García Moreno Ph.D.",
    }]


def test_umsa_rectorado_rejects_missing_duplicate_or_incomplete_profile_components() -> None:
    invalid_pages = [
        UMSA_HTML.replace("> Rectorado </h2>", "> Autoridades </h2>"),
        UMSA_HTML + UMSA_HTML,
        UMSA_HTML.replace('</h2>', '</h2><h2 class="portlet-title-text">Rectorado</h2>', 1),
        UMSA_HTML.replace('<div class="parte2">', '<div class="parte2"></div><div class="parte2">', 1),
        UMSA_HTML.replace('Rectora UMSA</b>', 'Rectora UMSA</b><b>Extra</b>'),
        UMSA_HTML.replace('Rectora UMSA</b>', '</b>'),
        UMSA_HTML.replace('María Eugenia García Moreno&nbsp;Ph.D.&nbsp;</b>', '</b>'),
        UMSA_HTML.replace('<div class="journal-content-article">', '<div class="journal-content-article"><div class="journal-content-article">', 1),
    ]

    assert all(extract_authorities(page, "umsa_rectorado_profile") == [] for page in invalid_pages)


def test_umsa_monitor_establishes_complete_baseline_silently() -> None:
    db = MagicMock(spec=DatabaseManager)
    db.get_ultimo_snapshot.return_value = None

    _run_umsa_monitor(db, UMSA_HTML)

    db.guardar_cambio.assert_not_called()
    assert db.guardar_snapshot.call_args.args[4][0].cargo == "Rectora UMSA"
    assert db.registrar_fuente_run.call_args.kwargs["estado"] == "first_snapshot"


def test_umsa_monitor_rebases_legacy_baseline_coherently_and_next_run_is_a_no_op() -> None:
    db = MagicMock(spec=DatabaseManager)
    snapshots = [{"hash": "legacy", "texto": "old whole page", "autoridades_json": None}]
    db.get_ultimo_snapshot.side_effect = lambda _fuente_id: snapshots[-1]

    def save_snapshot(_fuente_id: int, hash_: str, texto: str, metodo: str, authorities: list[Authority]) -> None:
        snapshots.append({
            "hash": hash_, "texto": texto, "metodo": metodo,
            "autoridades_json": [authority.to_dict() for authority in authorities],
        })

    db.guardar_snapshot.side_effect = save_snapshot

    _run_umsa_monitor(db, UMSA_HTML)
    _run_umsa_monitor(db, UMSA_HTML)

    assert len(snapshots) == 2
    assert snapshots[-1]["texto"] == "rectora umsa\nmaria eugenia garcia moreno"
    assert snapshots[-1]["metodo"] == "umsa_rectorado_profile"
    db.guardar_cambio.assert_not_called()
    assert db.registrar_fuente_run.call_args_list[0].kwargs["estado"] == "first_snapshot"
    assert db.registrar_fuente_run.call_args_list[-1].kwargs["estado"] == "no_change"


def test_umsa_monitor_rebases_unusable_structured_baselines_without_designations() -> None:
    unusable_baselines = [
        [], [{}], [{"cargo": "", "persona": "Ana"}], [{"cargo": "Rectora", "persona": "  "}],
        [{"cargo": "Rectora", "persona": 3}], [{"cargo": "Rectora", "persona": "Ana"}, {"cargo": "Vicerrector", "persona": "Luis"}],
        {"cargo": "Rectora", "persona": "Ana"}, "invalid",
    ]
    for baseline in unusable_baselines:
        db = MagicMock(spec=DatabaseManager)
        db.get_ultimo_snapshot.return_value = {
            "hash": "old", "texto": "old", "autoridades_json": baseline,
        }

        _run_umsa_monitor(db, UMSA_HTML)

        db.guardar_cambio.assert_not_called()
        db.guardar_snapshot.assert_called_once()
        assert db.registrar_fuente_run.call_args.kwargs["estado"] == "first_snapshot"


def test_umsa_monitor_preserves_baseline_and_records_non_success_for_partial_profile() -> None:
    db = MagicMock(spec=DatabaseManager)
    previous = [{"cargo": "Rectora UMSA", "persona": "María Eugenia García Moreno Ph.D."}]
    db.get_ultimo_snapshot.return_value = {"hash": "old", "texto": "old", "autoridades_json": previous}

    _run_umsa_monitor(db, UMSA_HTML.replace('Rectora UMSA</b>', '</b>'))

    db.guardar_cambio.assert_not_called()
    db.guardar_snapshot.assert_not_called()
    db.actualizar_autoridades_ultimo_snapshot.assert_not_called()
    assert db.registrar_fuente_run.call_args.kwargs["estado"] == "parse_error"
    assert db.registrar_fuente_run.call_args.kwargs["error_mensaje"]


def test_umsa_monitor_ignores_menu_biography_and_terminal_credentials_without_candidates() -> None:
    db = MagicMock(spec=DatabaseManager)
    db.get_ultimo_snapshot.return_value = {
        "hash": "hash-rectora umsa\nmaria eugenia garcia moreno",
        "texto": "rectora umsa\nmaria eugenia garcia moreno",
        "autoridades_json": [{"cargo": "Rectora UMSA", "persona": "María Eugenia García Moreno PhD"}],
    }
    noisy = UMSA_HTML.replace("María Menú Cambiante", "Otra Persona") .replace("En 2024 fue elegida Rectora.", "Biografía actualizada.")

    _run_umsa_monitor(db, noisy)

    db.guardar_cambio.assert_not_called()
    db.guardar_snapshot.assert_not_called()


def test_umsa_monitor_emits_canonical_header_events_without_text_candidates() -> None:
    db = MagicMock(spec=DatabaseManager)
    db.get_ultimo_snapshot.return_value = {
        "hash": "old", "texto": "rectora umsa\nmaria eugenia garcia moreno",
        "autoridades_json": [{"cargo": "Rectora UMSA", "persona": "María Eugenia García Moreno Ph.D."}],
    }
    db.guardar_cambio.return_value = 991
    replacement = UMSA_HTML.replace("María Eugenia García Moreno", "Ana Pérez", 2)

    _run_umsa_monitor(db, replacement)

    kwargs = db.guardar_cambio.call_args.kwargs
    assert kwargs["autoridades_eventos"][0]["type"] == "reemplazo"
    assert kwargs["posibles_peps"] == ""
    assert kwargs["feed_status"] == "primary"


def test_umsa_monitor_emits_cargo_change_using_existing_comparator() -> None:
    db = MagicMock(spec=DatabaseManager)
    db.get_ultimo_snapshot.return_value = {
        "hash": "old", "texto": "rectora umsa\nmaria eugenia garcia moreno",
        "autoridades_json": [{"cargo": "Rectora UMSA", "persona": "María Eugenia García Moreno Ph.D."}],
    }
    db.guardar_cambio.return_value = 992

    _run_umsa_monitor(db, UMSA_HTML.replace("Rectora UMSA", "Presidenta UMSA"))

    assert db.guardar_cambio.call_args.kwargs["autoridades_eventos"][0]["type"] == "cambio_cargo"


def test_divi_blurb_extracts_display_pairs_and_ignores_malformed_blocks() -> None:
    authorities = extract_authorities(ASUSS_HTML, "divi_blurb")

    assert [item.to_dict() for item in authorities] == [
        {"cargo": "Director Ejecutivo", "persona": "Dr. José Álvarez"},
        {"cargo": "Jefa de Auditoría", "persona": "María Quispe"},
    ]


def test_unconfigured_extractor_falls_back_to_no_structured_authorities() -> None:
    assert extract_authorities(ASUSS_HTML, None) == []


def test_monitor_initial_baseline_accepts_real_fixture_valid_roster() -> None:
    db = MagicMock(spec=DatabaseManager)
    db.get_ultimo_snapshot.return_value = None
    fuente = {
        "id": 13, "url": "https://www.asuss.gob.bo/recursos-humanos/#autoridades",
        "nombre": "ASUSS", "tipo": "html", "selector_css": ".entry-content",
        "autoridades_extractor": "divi_blurb", "analizar_imagenes": False,
    }

    with patch.object(DatabaseManager, "__init__", return_value=None), \
         patch("pep_monitor.create_http_session", return_value=MagicMock()), \
         patch.object(PEPMonitor, "_obtener_html_raw", return_value=(COMPLETE_ASUSS_HTML, "html_estatico")), \
         patch("pep_monitor.limpiar_html", return_value=(["contenido"], "html_estatico")):
        monitor = PEPMonitor()
        monitor.db = db
        monitor.procesar_fuente(fuente)

    assert [item.to_dict() for item in db.guardar_snapshot.call_args.args[4]] == [
        {"cargo": "Director Ejecutivo", "persona": "Dr. José Álvarez"},
        {"cargo": "Jefa de Auditoría", "persona": "María Quispe"},
    ]


def test_comparator_detects_replacement_with_normalized_titles_and_accents() -> None:
    events = compare_authorities(
        [Authority("Director Ejecutivo", "Dr. José Álvarez")],
        [Authority("director ejecutivo", "Lic. Ana Perez")],
    )

    assert events == [{
        "type": "reemplazo",
        "old": {"cargo": "Director Ejecutivo", "persona": "Dr. José Álvarez"},
        "new": {"cargo": "director ejecutivo", "persona": "Lic. Ana Perez"},
    }]


def test_comparator_detects_removal_and_designation() -> None:
    removal = compare_authorities([Authority("Gerente", "Ana Pérez")], [])
    designation = compare_authorities([], [Authority("Gerente", "Ana Pérez")])

    assert removal[0]["type"] == "remocion"
    assert designation[0]["type"] == "designacion"


def test_comparator_detects_job_change_without_double_counting() -> None:
    events = compare_authorities(
        [Authority("Jefa de Auditoría", "María Quispe")],
        [Authority("Directora Jurídica", "Maria Quispe")],
    )

    assert [event["type"] for event in events] == ["cambio_cargo"]


def test_feed_validator_admits_all_four_canonical_event_types() -> None:
    authority = {"cargo": "Directora", "persona": "Ana Pérez"}
    payload = {
        "version": 1,
        "events": [
            {"type": "designacion", "old": None, "new": authority},
            {"type": "remocion", "old": authority, "new": None},
            {"type": "reemplazo", "old": authority, "new": authority},
            {"type": "cambio_cargo", "old": authority, "new": authority},
        ],
    }

    assert is_valid_authority_event_payload(payload) is True
    assert classify_authority_event_payload(payload) == "primary"


def test_feed_validator_rejects_empty_malformed_and_unknown_events() -> None:
    invalid_payloads = [
        None,
        {"version": 1, "events": []},
        {"version": 2, "events": [{"type": "designacion"}]},
        {"version": 1, "events": [{"type": "editorial", "old": None, "new": None}]},
        {"version": 1, "events": [{"type": "designacion", "old": None, "new": {"cargo": "", "persona": "Ana"}}]},
        {"version": 1, "events": [{"type": "remocion", "old": None, "new": None}]},
    ]

    for payload in invalid_payloads:
        assert is_valid_authority_event_payload(payload) is False
        assert classify_authority_event_payload(payload) == "review"


def test_feed_validator_enforces_version_and_unicode_whitespace_boundary() -> None:
    def payload(version: object, field: str = "cargo", value: str = "Directora") -> dict[str, object]:
        authority = {"cargo": "Directora", "persona": "Ana Pérez"}
        authority[field] = value
        return {
            "version": version,
            "events": [{"type": "designacion", "old": None, "new": authority}],
        }

    assert is_valid_authority_event_payload(payload(1)) is True
    assert is_valid_authority_event_payload(payload(1.0)) is False

    values = [
        ("Directora", True),
        ("", False),
        ("   ", False),
        ("\t\n", False),
        ("\u00a0", False),
    ]
    for value, expected in values:
        for field in ("cargo", "persona"):
            assert is_valid_authority_event_payload(payload(1, field, value)) is expected


def test_database_manager_downgrades_invalid_primary_and_defaults_unstructured_to_review() -> None:
    db = object.__new__(DatabaseManager)
    db.connection = MagicMock(closed=False)
    db.cursor = MagicMock()
    db.cursor.fetchone.side_effect = [{"id": 1}, {"id": 2}]

    common = {
        "fuente_id": 13,
        "hash_anterior": "a" * 64,
        "hash_nuevo": "b" * 64,
        "lineas_quitadas": 1,
        "lineas_nuevas": 1,
        "diff_texto": "changed",
        "posibles_peps": "Ana",
    }
    db.guardar_cambio(
        **common,
        autoridades_eventos=[{"type": "remocion", "old": None, "new": None}],
        feed_status="primary",
    )
    db.guardar_cambio(**common)

    first_params = db.cursor.execute.call_args_list[0].args[1]
    second_params = db.cursor.execute.call_args_list[1].args[1]
    assert first_params[-1] == "review"
    assert second_params[-1] == "review"


def test_review_handoff_commits_atomically_and_returns_existing_terminal_status() -> None:
    db = object.__new__(DatabaseManager)
    db.connection = MagicMock(autocommit=True, closed=False)
    db.cursor = MagicMock()
    db.cursor.fetchone.side_effect = [None, {"estado": "rejected"}]

    status = db.registrar_revision_remocion_autoridades(
        fuente_id=13,
        snapshot_base_id=22,
        baseline=[{"cargo": "Director", "persona": "Ana"}, {"cargo": "Auditor", "persona": "Luis"}],
        candidate=[{"cargo": "Director", "persona": "Ana"}],
        proposed_events=[{"type": "remocion", "old": {"cargo": "Auditor", "persona": "Luis"}, "new": None}],
        evidence={"version": 1},
    )

    assert status == "rejected"
    db.connection.commit.assert_called_once()
    db.connection.rollback.assert_not_called()
    assert db.connection.autocommit is True


def test_restored_roster_releases_terminal_fingerprint_for_later_recurrence() -> None:
    args = (
        13,
        22,
        [{"cargo": "Director", "persona": "Ana"}, {"cargo": "Auditor", "persona": "Luis"}],
        [{"cargo": "Director", "persona": "Ana"}],
        [{"type": "remocion"}],
        {"version": 1},
    )
    for status in ("rejected", "confirmed", "superseded"):
        db = object.__new__(DatabaseManager)
        db.connection = MagicMock(autocommit=True, closed=False)
        db.cursor = MagicMock()
        db.cursor.fetchone.side_effect = [None, {"estado": status}, {"estado": "pending"}]

        assert db.registrar_revision_remocion_autoridades(*args) == status
        db.superseder_revisiones_remocion_autoridades(13)
        assert db.registrar_revision_remocion_autoridades(*args) == "pending"

        restoration_sql = db.cursor.execute.call_args_list[3].args[0]
        assert "lifecycle_key = id" in restoration_sql
        assert "lifecycle_key = 0" in db.cursor.execute.call_args_list[-2].args[0]


def test_recurring_reduction_opens_new_pending_review_and_preserves_history() -> None:
    db = object.__new__(DatabaseManager)
    db.connection = MagicMock(autocommit=True, closed=False)
    db.cursor = MagicMock()
    rows: list[dict[str, object]] = []
    selected: dict[str, str] | None = None

    def execute(sql: str, params: tuple[object, ...]) -> None:
        nonlocal selected
        statement = " ".join(sql.split())
        selected = None

        if statement.startswith("UPDATE revisiones_remocion_autoridades"):
            fuente_id, fingerprint = params
            for row in rows:
                is_replaced_pending = (
                    row["estado"] == "pending"
                    and row["fingerprint"] != fingerprint
                )
                if (
                    row["fuente_id"] == fuente_id
                    and row["lifecycle_key"] == 0
                    and is_replaced_pending
                ):
                    row["estado"] = "superseded"
                    if "lifecycle_key = id" in statement:
                        row["lifecycle_key"] = row["id"]
            return

        if statement.startswith("INSERT INTO revisiones_remocion_autoridades"):
            fuente_id = params[0]
            fingerprint = params[-1]
            conflict = next((
                row for row in rows
                if row["fuente_id"] == fuente_id
                and row["fingerprint"] == fingerprint
                and row["lifecycle_key"] == 0
            ), None)
            if conflict is None:
                rows.append({
                    "id": len(rows) + 1,
                    "fuente_id": fuente_id,
                    "fingerprint": fingerprint,
                    "lifecycle_key": 0,
                    "estado": "pending",
                })
                selected = {"estado": "pending"}
            elif conflict["estado"] == "pending":
                selected = {"estado": "pending"}
            return

        if statement.startswith("SELECT estado"):
            fuente_id, fingerprint = params
            conflict = next(
                row for row in rows
                if row["fuente_id"] == fuente_id
                and row["fingerprint"] == fingerprint
                and row["lifecycle_key"] == 0
            )
            selected = {"estado": str(conflict["estado"])}
            return

        raise AssertionError(f"Unexpected SQL: {statement}")

    db.cursor.execute.side_effect = execute
    db.cursor.fetchone.side_effect = lambda: selected
    baseline = [
        {"cargo": "Director", "persona": "Ana"},
        {"cargo": "Auditor", "persona": "Luis"},
    ]
    reduction_a = [baseline[0]]
    reduction_b = [baseline[1]]

    def register(candidate: list[dict]) -> str:
        return db.registrar_revision_remocion_autoridades(
            13,
            22,
            baseline,
            candidate,
            [{"type": "remocion"}],
            {"version": 1},
        )

    statuses = [register(reduction_a), register(reduction_b), register(reduction_a)]

    assert statuses == [
        "pending",
        "pending",
        "pending",
    ], statuses
    assert [row["estado"] for row in rows] == [
        "superseded",
        "superseded",
        "pending",
    ]
    assert rows[0]["fingerprint"] == rows[2]["fingerprint"]
    assert [row["lifecycle_key"] for row in rows] == [1, 2, 0]


def test_review_handoff_rolls_back_as_one_transaction() -> None:
    db = object.__new__(DatabaseManager)
    db.connection = MagicMock(autocommit=True, closed=False)
    db.cursor = MagicMock()
    db.cursor.execute.side_effect = RuntimeError("database unavailable")

    try:
        db.registrar_revision_remocion_autoridades(13, 22, [], [], [], {})
        raise AssertionError("expected transaction failure")
    except RuntimeError:
        pass

    db.connection.rollback.assert_called_once()
    db.connection.commit.assert_not_called()
    assert db.connection.autocommit is True


def test_monitor_persists_structured_event_when_flat_text_is_unchanged() -> None:
    db = MagicMock(spec=DatabaseManager)
    previous_html = COMPLETE_ASUSS_HTML
    current_html = COMPLETE_ASUSS_HTML.replace("Dr. José Álvarez", "Lic. Ana Pérez")
    fuente = {
        "id": 13,
        "url": "https://www.asuss.gob.bo/recursos-humanos/#autoridades",
        "nombre": "ASUSS",
        "tipo": "html",
        "selector_css": ".entry-content",
        "autoridades_extractor": "divi_blurb",
        "analizar_imagenes": False,
    }
    db.get_ultimo_snapshot.return_value = {
        "hash": "same-text-hash",
        "texto": "contenido sin cambios",
        "autoridades_json": [item.to_dict() for item in extract_authorities(previous_html, "divi_blurb")],
    }
    db.guardar_cambio.return_value = 936

    call_order = []
    db.guardar_snapshot.side_effect = lambda *args: call_order.append("snapshot")

    with patch.object(DatabaseManager, "__init__", return_value=None), \
         patch("pep_monitor.create_http_session", return_value=MagicMock()), \
         patch.object(PEPMonitor, "_obtener_html_raw", return_value=(current_html, "html_estatico")), \
         patch("pep_monitor.limpiar_html", return_value=(["contenido sin cambios"], "html_estatico")), \
         patch("pep_monitor.mostrar_alerta", side_effect=lambda *args: call_order.append("alert")) as alert, \
         patch("pep_monitor.hashlib.sha256") as sha:
        sha.return_value.hexdigest.return_value = "same-text-hash"
        monitor = PEPMonitor()
        monitor.db = db
        monitor.procesar_fuente(fuente)

    assert db.guardar_cambio.call_count == 1
    kwargs = db.guardar_cambio.call_args.kwargs
    assert kwargs["lineas_quitadas"] == 0
    assert kwargs["lineas_nuevas"] == 0
    assert kwargs["autoridades_eventos"][0]["type"] == "reemplazo"
    assert kwargs["autoridades_eventos"][0]["new"]["persona"] == "Lic. Ana Pérez"
    assert kwargs["feed_status"] == "primary"
    alert.assert_called_once()
    assert call_order == ["alert", "snapshot"]
    assert db.guardar_snapshot.call_args.args[4][0].persona == "Lic. Ana Pérez"


def test_monitor_ignores_divi_authorities_outside_entry_content() -> None:
    db = MagicMock(spec=DatabaseManager)
    html = """
    <main class="entry-content">
      <div class="et_pb_blurb_container">
        <h4>Presidenta</h4>
        <p>Ana Interna</p>
      </div>
    </main>
    <footer>
      <div class="et_pb_blurb_container">
        <h4>Director Institucional</h4>
        <p>Bruno Footer</p>
      </div>
    </footer>
    """
    fuente = {
        "id": 13, "url": "https://example.test/authorities", "nombre": "Example",
        "tipo": "html", "selector_css": ".entry-content",
        "autoridades_extractor": "divi_blurb", "analizar_imagenes": False,
    }
    db.get_ultimo_snapshot.return_value = {
        "hash": "same-text-hash",
        "texto": "contenido sin cambios",
        "autoridades_json": [{"cargo": "Presidenta", "persona": "Ana Interna"}],
    }
    db.guardar_cambio.return_value = 942
    current = extract_authorities(html, "divi_blurb")
    previous = [Authority("Presidenta", "Ana Interna")]

    assert authority_extraction_complete(html, "divi_blurb", current) is True
    events = compare_authorities(previous, current)
    assert events == []
    assert classify_authority_event_payload({"version": 1, "events": events}) == "review"

    with patch.object(DatabaseManager, "__init__", return_value=None), \
         patch("pep_monitor.create_http_session", return_value=MagicMock()), \
         patch.object(PEPMonitor, "_obtener_html_raw", return_value=(html, "html_estatico")), \
         patch("pep_monitor.limpiar_html", return_value=(["contenido sin cambios"], "html_estatico")), \
         patch("pep_monitor.mostrar_alerta"), \
         patch("pep_monitor.hashlib.sha256") as sha:
        sha.return_value.hexdigest.return_value = "same-text-hash"
        monitor = PEPMonitor()
        monitor.db = db
        monitor.procesar_fuente(fuente)

    db.guardar_cambio.assert_not_called()
    db.guardar_snapshot.assert_not_called()
    stored = db.actualizar_autoridades_ultimo_snapshot.call_args.args[1]
    assert [item.to_dict() for item in current] == [
        {"cargo": "Presidenta", "persona": "Ana Interna"},
    ]
    assert stored == current


def test_monitor_rejects_equal_size_partial_divi_roster() -> None:
    db = MagicMock(spec=DatabaseManager)
    html = """
    <main class="entry-content">
      <div class="et_pb_blurb_container">
        <h4>Presidenta</h4>
        <p>Ana Interna</p>
      </div>
      <div class="et_pb_blurb_container">
        <h4>Director Jurídico</h4>
      </div>
      <div class="et_pb_blurb_container">
        <h4>Director Institucional</h4>
        <p>Carla Nueva</p>
      </div>
    </main>
    """
    fuente = {
        "id": 13, "url": "https://example.test/authorities", "nombre": "Example",
        "tipo": "html", "selector_css": ".entry-content",
        "autoridades_extractor": "divi_blurb", "analizar_imagenes": False,
    }
    previous = [
        {"cargo": "Presidenta", "persona": "Ana Interna"},
        {"cargo": "Director Jurídico", "persona": "Bruno Anterior"},
    ]
    db.get_ultimo_snapshot.return_value = {
        "hash": "same-text-hash",
        "texto": "contenido sin cambios",
        "autoridades_json": previous,
    }
    current = extract_authorities(html, "divi_blurb")
    events = compare_authorities(
        [Authority(**item) for item in previous],
        current,
    )

    assert [item.to_dict() for item in current] == [
        {"cargo": "Presidenta", "persona": "Ana Interna"},
        {"cargo": "Director Institucional", "persona": "Carla Nueva"},
    ]
    assert authority_extraction_complete(html, "divi_blurb", current) is False
    assert [event["type"] for event in events] == ["remocion", "designacion"]
    assert classify_authority_event_payload({"version": 1, "events": events}) == "primary"

    with patch.object(DatabaseManager, "__init__", return_value=None), \
         patch("pep_monitor.create_http_session", return_value=MagicMock()), \
         patch.object(PEPMonitor, "_obtener_html_raw", return_value=(html, "html_estatico")), \
         patch("pep_monitor.limpiar_html", return_value=(["contenido sin cambios"], "html_estatico")), \
         patch("pep_monitor.hashlib.sha256") as sha:
        sha.return_value.hexdigest.return_value = "same-text-hash"
        monitor = PEPMonitor()
        monitor.db = db
        monitor.procesar_fuente(fuente)

    db.guardar_cambio.assert_not_called()
    db.guardar_snapshot.assert_not_called()
    stored = db.actualizar_autoridades_ultimo_snapshot.call_args.args[1]
    assert [item.to_dict() for item in stored] == previous


def test_monitor_automatically_persists_removals_for_explicitly_empty_roster() -> None:
    db = MagicMock(spec=DatabaseManager)
    fuente = {
        "id": 13,
        "url": "https://www.asuss.gob.bo/recursos-humanos/#autoridades",
        "nombre": "ASUSS",
        "tipo": "html",
        "selector_css": ".entry-content",
        "autoridades_extractor": "divi_blurb",
        "analizar_imagenes": False,
    }
    db.get_ultimo_snapshot.return_value = {
        "hash": "same-text-hash",
        "texto": "contenido sin cambios",
        "autoridades_json": [{"cargo": "Director Ejecutivo", "persona": "Dr. José Álvarez"}],
    }
    db.guardar_cambio.return_value = 937

    with patch.object(DatabaseManager, "__init__", return_value=None), \
         patch("pep_monitor.create_http_session", return_value=MagicMock()), \
         patch.object(PEPMonitor, "_obtener_html_raw", return_value=(
             '<div class="entry-content"><p>Sin autoridades</p></div>', "html_estatico"
         )), \
         patch("pep_monitor.limpiar_html", return_value=(["contenido sin cambios"], "html_estatico")), \
         patch("pep_monitor.hashlib.sha256") as sha:
        sha.return_value.hexdigest.return_value = "same-text-hash"
        monitor = PEPMonitor()
        monitor.db = db
        monitor.procesar_fuente(fuente)

    assert db.guardar_cambio.call_args.kwargs["autoridades_eventos"][0]["type"] == "remocion"
    assert db.guardar_cambio.call_args.kwargs["feed_status"] == "primary"
    assert db.guardar_snapshot.call_args.args[4] == []
    db.registrar_revision_remocion_autoridades.assert_not_called()


def test_monitor_preserves_baseline_when_configured_markup_no_longer_matches() -> None:
    db = MagicMock(spec=DatabaseManager)
    fuente = {
        "id": 13, "url": "https://www.asuss.gob.bo/recursos-humanos/#autoridades",
        "nombre": "ASUSS", "tipo": "html", "selector_css": ".entry-content",
        "autoridades_extractor": "divi_blurb", "analizar_imagenes": False,
    }
    previous = [{"cargo": "Director Ejecutivo", "persona": "Dr. José Álvarez"}]
    db.get_ultimo_snapshot.return_value = {
        "hash": "old-hash", "texto": "contenido anterior", "autoridades_json": previous,
    }
    db.guardar_cambio.return_value = 939
    changed_markup = '<div class="entry-content"><article>Nuevo diseño</article></div>'

    with patch.object(DatabaseManager, "__init__", return_value=None), \
         patch("pep_monitor.create_http_session", return_value=MagicMock()), \
         patch.object(PEPMonitor, "_obtener_html_raw", return_value=(changed_markup, "html_estatico")), \
         patch("pep_monitor.limpiar_html", return_value=(["contenido nuevo"], "html_estatico")), \
         patch("pep_monitor.mostrar_alerta"), \
         patch("pep_monitor.hashlib.sha256") as sha:
        sha.return_value.hexdigest.return_value = "new-hash"
        monitor = PEPMonitor()
        monitor.db = db
        monitor.procesar_fuente(fuente)

    assert db.guardar_cambio.call_args.kwargs["autoridades_eventos"] == []
    assert db.guardar_cambio.call_args.kwargs["feed_status"] == "review"
    db.registrar_revision_remocion_autoridades.assert_not_called()
    assert [item.to_dict() for item in db.guardar_snapshot.call_args.args[4]] == previous


def test_monitor_defers_first_nonempty_roster_reduction() -> None:
    db = MagicMock(spec=DatabaseManager)
    fuente = {
        "id": 13, "url": "https://www.asuss.gob.bo/recursos-humanos/#autoridades",
        "nombre": "ASUSS", "tipo": "html", "selector_css": ".entry-content",
        "autoridades_extractor": "divi_blurb", "analizar_imagenes": False,
    }
    previous = [
        {"cargo": "Director Ejecutivo", "persona": "Dr. José Álvarez"},
        {"cargo": "Jefa de Auditoría", "persona": "María Quispe"},
    ]
    db.get_ultimo_snapshot.return_value = {
        "hash": "same-hash", "texto": "contenido sin cambios", "autoridades_json": previous,
    }
    db.registrar_revision_remocion_autoridades.return_value = "pending"
    db.guardar_cambio.return_value = 940
    partial_html = COMPLETE_ASUSS_HTML.replace(
        '  <div class="et_pb_blurb_container">\n    <h4>Jefa de Auditoría</h4>\n    <p>María Quispe</p>\n  </div>\n', ""
    )

    with patch.object(DatabaseManager, "__init__", return_value=None), \
         patch("pep_monitor.create_http_session", return_value=MagicMock()), \
         patch.object(PEPMonitor, "_obtener_html_raw", return_value=(partial_html, "html_estatico")), \
         patch("pep_monitor.limpiar_html", return_value=(["contenido sin cambios"], "html_estatico")), \
         patch("pep_monitor.mostrar_alerta"), \
         patch("pep_monitor.hashlib.sha256") as sha:
        sha.return_value.hexdigest.return_value = "same-hash"
        monitor = PEPMonitor()
        monitor.db = db
        monitor.procesar_fuente(fuente)

    db.guardar_cambio.assert_not_called()
    db.registrar_revision_remocion_autoridades.assert_called_once()
    assert db.registrar_revision_remocion_autoridades.call_args.kwargs["proposed_events"][0]["type"] == "remocion"
    payload = db.actualizar_autoridades_ultimo_snapshot.call_args.args[1]
    assert [item.to_dict() for item in payload[:2]] == previous
    assert payload[2]["_authority_roster"] == {
        "version": 2,
        "pending": [{"cargo": "Director Ejecutivo", "persona": "Dr. José Álvarez"}],
    }


def test_monitor_persists_simultaneous_text_change_while_reduction_is_pending() -> None:
    db = MagicMock(spec=DatabaseManager)
    fuente = {
        "id": 13, "url": "https://example.test/authorities", "nombre": "Example",
        "tipo": "html", "selector_css": ".entry-content",
        "autoridades_extractor": "divi_blurb", "analizar_imagenes": False,
    }
    previous = [
        {"cargo": "Director Ejecutivo", "persona": "Dr. José Álvarez"},
        {"cargo": "Jefa de Auditoría", "persona": "María Quispe"},
    ]
    db.get_ultimo_snapshot.return_value = {
        "id": 22, "hash": "old-hash", "texto": "contenido anterior",
        "autoridades_json": previous,
    }
    db.registrar_revision_remocion_autoridades.return_value = "pending"
    db.guardar_cambio.return_value = 941
    reduced_html = COMPLETE_ASUSS_HTML.replace(
        '  <div class="et_pb_blurb_container">\n    <h4>Jefa de Auditoría</h4>\n    <p>María Quispe</p>\n  </div>\n', ""
    )

    with patch.object(DatabaseManager, "__init__", return_value=None), \
         patch("pep_monitor.create_http_session", return_value=MagicMock()), \
         patch.object(PEPMonitor, "_obtener_html_raw", return_value=(reduced_html, "html_estatico")), \
         patch("pep_monitor.limpiar_html", return_value=(["contenido nuevo"], "html_estatico")), \
         patch("pep_monitor.calcular_diff", return_value={
             "quitadas": ["contenido anterior"], "nuevas": ["contenido nuevo"],
             "diff_texto": "- contenido anterior\n+ contenido nuevo", "posibles_peps": "",
         }), \
         patch("pep_monitor.mostrar_alerta"), \
         patch("pep_monitor.hashlib.sha256") as sha:
        sha.return_value.hexdigest.return_value = "new-hash"
        monitor = PEPMonitor()
        monitor.db = db
        monitor.procesar_fuente(fuente)

    kwargs = db.guardar_cambio.call_args.kwargs
    assert kwargs["diff_texto"] == "- contenido anterior\n+ contenido nuevo"
    assert kwargs["autoridades_eventos"] == []
    assert kwargs["feed_status"] == "review"
    payload = db.guardar_snapshot.call_args.args[4]
    assert payload[-1]["_authority_roster"]["pending"] == [previous[0]]
    assert db.registrar_fuente_run.call_args.kwargs["estado"] == "success"


def test_monitor_suppresses_terminal_duplicate_and_removes_pending_marker() -> None:
    for status in ("rejected", "confirmed", "superseded"):
        db = MagicMock(spec=DatabaseManager)
        fuente = {
            "id": 13, "url": "https://example.test/authorities", "nombre": "Example",
            "tipo": "html", "selector_css": ".entry-content",
            "autoridades_extractor": "divi_blurb", "analizar_imagenes": False,
        }
        previous = [
            {"cargo": "Director Ejecutivo", "persona": "Dr. José Álvarez"},
            {"cargo": "Jefa de Auditoría", "persona": "María Quispe"},
        ]
        db.get_ultimo_snapshot.return_value = {
            "id": 22,
            "hash": "same-hash",
            "texto": "same",
            "autoridades_json": previous + [{
                "_authority_roster": {"version": 2, "pending": [previous[0]]}
            }],
        }
        db.registrar_revision_remocion_autoridades.return_value = status
        reduced_html = COMPLETE_ASUSS_HTML.replace(
            '  <div class="et_pb_blurb_container">\n    <h4>Jefa de Auditoría</h4>\n    <p>María Quispe</p>\n  </div>\n', ""
        )

        with patch.object(DatabaseManager, "__init__", return_value=None), \
             patch("pep_monitor.create_http_session", return_value=MagicMock()), \
             patch.object(PEPMonitor, "_obtener_html_raw", return_value=(reduced_html, "html_estatico")), \
             patch("pep_monitor.limpiar_html", return_value=(["same"], "html_estatico")), \
             patch("pep_monitor.hashlib.sha256") as sha:
            sha.return_value.hexdigest.return_value = "same-hash"
            monitor = PEPMonitor()
            monitor.db = db
            monitor.procesar_fuente(fuente)

        db.guardar_cambio.assert_not_called()
        payload = db.actualizar_autoridades_ultimo_snapshot.call_args.args[1]
        assert [item.to_dict() for item in payload] == previous, status
        assert db.registrar_fuente_run.call_args.kwargs["estado"] == "no_change", status


def test_monitor_keeps_repeated_identical_reduction_pending() -> None:
    db = MagicMock(spec=DatabaseManager)
    fuente = {
        "id": 13, "url": "https://www.asuss.gob.bo/recursos-humanos/#autoridades",
        "nombre": "ASUSS", "tipo": "html", "selector_css": ".entry-content",
        "autoridades_extractor": "divi_blurb", "analizar_imagenes": False,
    }
    previous = [
        {"cargo": "Director Ejecutivo", "persona": "Dr. José Álvarez"},
        {"cargo": "Jefa de Auditoría", "persona": "María Quispe"},
    ]
    reduced_html = COMPLETE_ASUSS_HTML.replace(
        '  <div class="et_pb_blurb_container">\n    <h4>Jefa de Auditoría</h4>\n    <p>María Quispe</p>\n  </div>\n', ""
    )
    pending = [{"cargo": "Director Ejecutivo", "persona": "Dr. José Álvarez"}]
    db.get_ultimo_snapshot.return_value = {
        "hash": "same-text-hash", "texto": "contenido sin cambios",
        "autoridades_json": previous + [{"_authority_roster": {"version": 2, "pending": pending}}],
    }
    db.registrar_revision_remocion_autoridades.return_value = "pending"
    for _ in range(2):
        db.reset_mock()
        with patch.object(DatabaseManager, "__init__", return_value=None), \
             patch("pep_monitor.create_http_session", return_value=MagicMock()), \
             patch.object(PEPMonitor, "_obtener_html_raw", return_value=(reduced_html, "html_estatico")), \
             patch("pep_monitor.limpiar_html", return_value=(["contenido sin cambios"], "html_estatico")), \
             patch("pep_monitor.hashlib.sha256") as sha:
            sha.return_value.hexdigest.return_value = "same-text-hash"
            monitor = PEPMonitor()
            monitor.db = db
            monitor.procesar_fuente(fuente)

        db.guardar_cambio.assert_not_called()
        db.registrar_revision_remocion_autoridades.assert_called_once()
        db.guardar_snapshot.assert_not_called()
        payload = db.actualizar_autoridades_ultimo_snapshot.call_args.args[1]
        assert [item.to_dict() for item in payload[:2]] == previous
        assert payload[2] == {"_authority_roster": {"version": 2, "pending": pending}}
        assert db.registrar_fuente_run.call_args.kwargs["estado"] == "authority_review_pending"


def test_monitor_replaces_pending_reduction_and_clears_it_on_full_roster() -> None:
    db = MagicMock(spec=DatabaseManager)
    fuente = {
        "id": 13, "url": "https://www.asuss.gob.bo/recursos-humanos/#autoridades",
        "nombre": "ASUSS", "tipo": "html", "selector_css": ".entry-content",
        "autoridades_extractor": "divi_blurb", "analizar_imagenes": False,
    }
    previous = [item.to_dict() for item in extract_authorities(COMPLETE_ASUSS_HTML, "divi_blurb")]
    db.get_ultimo_snapshot.return_value = {
        "hash": "same-text-hash", "texto": "contenido sin cambios",
        "autoridades_json": previous + [{"_authority_roster": {"version": 2, "pending": [previous[0]]}}],
    }
    db.registrar_revision_remocion_autoridades.return_value = "pending"

    def run(html: str) -> None:
        db.reset_mock()
        with patch.object(DatabaseManager, "__init__", return_value=None), \
             patch("pep_monitor.create_http_session", return_value=MagicMock()), \
             patch.object(PEPMonitor, "_obtener_html_raw", return_value=(html, "html_estatico")), \
             patch("pep_monitor.limpiar_html", return_value=(["contenido sin cambios"], "html_estatico")), \
             patch("pep_monitor.hashlib.sha256") as sha:
            sha.return_value.hexdigest.return_value = "same-text-hash"
            monitor = PEPMonitor()
            monitor.db = db
            monitor.procesar_fuente(fuente)

    different = COMPLETE_ASUSS_HTML.replace(
        '  <div class="et_pb_blurb_container">\n    <h4>Director Ejecutivo</h4>\n    <p>Dr. José Álvarez</p>\n  </div>\n', ""
    )
    run(different)
    db.guardar_cambio.assert_not_called()
    assert db.actualizar_autoridades_ultimo_snapshot.call_args.args[1][-1]["_authority_roster"]["pending"] == [previous[1]]

    run(COMPLETE_ASUSS_HTML)
    db.guardar_cambio.assert_not_called()
    assert [item.to_dict() for item in db.actualizar_autoridades_ultimo_snapshot.call_args.args[1]] == previous
    db.superseder_revisiones_remocion_autoridades.assert_called_once_with(13)

    expanded = COMPLETE_ASUSS_HTML.replace(
        "</div>", '<div class="et_pb_blurb_container"><h4>Gerente</h4><p>Ana Pérez</p></div></div>', 1
    )
    run(expanded)
    assert db.guardar_cambio.call_args.kwargs["autoridades_eventos"][0]["type"] == "designacion"
    assert db.guardar_cambio.call_args.kwargs["feed_status"] == "primary"
    assert all(isinstance(item, Authority) for item in db.guardar_snapshot.call_args.args[4])


def test_monitor_initializes_legacy_null_baseline_without_creating_cambio() -> None:
    db = MagicMock(spec=DatabaseManager)
    fuente = {
        "id": 13, "url": "https://www.asuss.gob.bo/recursos-humanos/#autoridades",
        "nombre": "ASUSS", "tipo": "html", "selector_css": ".entry-content",
        "autoridades_extractor": "divi_blurb", "analizar_imagenes": False,
    }
    db.get_ultimo_snapshot.return_value = {
        "hash": "same-text-hash", "texto": "contenido sin cambios", "autoridades_json": None,
    }

    with patch.object(DatabaseManager, "__init__", return_value=None), \
         patch("pep_monitor.create_http_session", return_value=MagicMock()), \
         patch.object(PEPMonitor, "_obtener_html_raw", return_value=(COMPLETE_ASUSS_HTML, "html_estatico")), \
         patch("pep_monitor.limpiar_html", return_value=(["contenido sin cambios"], "html_estatico")), \
         patch("pep_monitor.hashlib.sha256") as sha:
        sha.return_value.hexdigest.return_value = "same-text-hash"
        monitor = PEPMonitor()
        monitor.db = db
        monitor.procesar_fuente(fuente)

    db.guardar_cambio.assert_not_called()
    db.actualizar_autoridades_ultimo_snapshot.assert_called_once_with(
        13, extract_authorities(COMPLETE_ASUSS_HTML, "divi_blurb")
    )


def test_monitor_treats_known_empty_baseline_as_designations() -> None:
    db = MagicMock(spec=DatabaseManager)
    fuente = {
        "id": 13, "url": "https://www.asuss.gob.bo/recursos-humanos/#autoridades",
        "nombre": "ASUSS", "tipo": "html", "selector_css": ".entry-content",
        "autoridades_extractor": "divi_blurb", "analizar_imagenes": False,
    }
    db.get_ultimo_snapshot.return_value = {
        "hash": "same-text-hash", "texto": "contenido sin cambios", "autoridades_json": [],
    }
    db.guardar_cambio.return_value = 938

    with patch.object(DatabaseManager, "__init__", return_value=None), \
         patch("pep_monitor.create_http_session", return_value=MagicMock()), \
         patch.object(PEPMonitor, "_obtener_html_raw", return_value=(COMPLETE_ASUSS_HTML, "html_estatico")), \
         patch("pep_monitor.limpiar_html", return_value=(["contenido sin cambios"], "html_estatico")), \
         patch("pep_monitor.hashlib.sha256") as sha:
        sha.return_value.hexdigest.return_value = "same-text-hash"
        monitor = PEPMonitor()
        monitor.db = db
        monitor.procesar_fuente(fuente)

    assert db.guardar_cambio.call_args.kwargs["autoridades_eventos"][0]["type"] == "designacion"
    assert db.guardar_cambio.call_args.kwargs["feed_status"] == "primary"


def _run_structured_with_sql_spy(source: dict, html: str, current: dict, *, fetch_error: Exception | None = None, during_fetch: object = None) -> tuple[DatabaseManager, list[str], dict]:
    """Exercise the real process and persistence guard, not a mocked guard."""
    statements: list[str] = []
    state = {"current": current, "fetch_source": None}

    class CursorSpy:
        def execute(self, sql: str, params: tuple[object, ...]) -> None:
            statement = " ".join(sql.split())
            statements.append(statement)
            if statement.startswith(("INSERT", "UPDATE", "DELETE")):
                raise AssertionError(f"Unexpected persistence: {statement}")

        def fetchone(self) -> dict | None:
            return state["current"]

        def fetchall(self) -> list:
            return []

    db = object.__new__(DatabaseManager)
    db.connection = MagicMock(autocommit=True, closed=False)
    db.cursor = CursorSpy()

    def fetch(_monitor: PEPMonitor, fetched_source: dict) -> tuple[str, str]:
        state["fetch_source"] = fetched_source
        if during_fetch is not None:
            during_fetch(current)
        if fetch_error:
            raise fetch_error
        return html, "html_estatico"

    with patch.object(DatabaseManager, "__init__", return_value=None), \
         patch("pep_monitor.create_http_session", return_value=MagicMock()), \
         patch.object(PEPMonitor, "_obtener_html_raw", fetch), \
         patch("pep_monitor.limpiar_html", return_value=(["directory"], "html_estatico")):
        monitor = PEPMonitor()
        monitor.db = db
        monitor.procesar_fuente(source)
    return db, statements, state


def test_structured_fetch_uses_pre_fetch_detached_source_token() -> None:
    source, html, _ = _structured_reduction_source()
    original_url = source["url"]
    current = {**source}
    seen: list[str] = []

    def fetch(_monitor: PEPMonitor, fetched_source: dict) -> tuple[str, str]:
        source["url"] = "https://example.test/new-authorities"
        seen.append(fetched_source["url"])
        return html, "html_estatico"

    with patch.object(DatabaseManager, "__init__", return_value=None), \
         patch("pep_monitor.create_http_session", return_value=MagicMock()), \
         patch.object(PEPMonitor, "_obtener_html_raw", fetch), \
         patch("pep_monitor.limpiar_html", return_value=(["directory"], "html_estatico")):
        monitor = PEPMonitor()
        monitor.db = MagicMock(spec=DatabaseManager)
        monitor.db.begin_source_persistence.return_value = False
        monitor.procesar_fuente(source)

    assert seen == [original_url]
    assert monitor.db.begin_source_persistence.call_args.args[0]["url"] == original_url
    monitor.db.registrar_fuente_run.assert_not_called()


@pytest.mark.parametrize("route,html,error", [
    ("no_content", "", None),
    ("incomplete", "<section class='directory'></section>", None),
    ("unconfigured", "<section class='directory'></section>", None),
    ("fetch_failure", "", requests.exceptions.Timeout("fetch timed out")),
    ("inactive", "<section class='directory'></section>", None),
    ("unconfirmed", "<section class='directory'></section>", None),
])
def test_structured_pre_cas_routes_leave_no_database_writes(route: str, html: str, error: Exception | None) -> None:
    source, _, _ = _structured_reduction_source()
    if route == "unconfigured":
        source["authority_extraction_config"] = None
    if route == "inactive":
        source["activo"] = False
    if route == "unconfirmed":
        source["authority_extraction_confirmed_revision"] = None
    db, statements, _ = _run_structured_with_sql_spy(source, html, source, fetch_error=error)
    assert statements == []
    db.connection.commit.assert_not_called()
    db.connection.rollback.assert_not_called()
    assert db.connection.autocommit is True


@pytest.mark.parametrize("field,value", [
    ("url", "https://example.test/changed"),
    ("tipo", "js"),
    ("autoridades_extractor", "divi_blurb"),
    ("authority_extraction_config", {"version": 1, "container_selector": ".other", "row_selector": ".row", "person_selector": ".person", "role_selector": ".role"}),
    ("activo", False),
    ("authority_extraction_revision", "revision-b"),
    ("authority_extraction_confirmed_revision", None),
])
def test_structured_fetch_race_discards_each_changed_token_field(field: str, value: object) -> None:
    source, html, _ = _structured_reduction_source()
    current = dict(source)
    db, statements, state = _run_structured_with_sql_spy(
        source, html, current, during_fetch=lambda row: row.__setitem__(field, value)
    )
    assert state["fetch_source"]["url"] == source["url"]
    assert len(statements) == 1 and "FROM fuentes" in statements[0] and "FOR UPDATE" in statements[0]
    db.connection.rollback.assert_called_once()
    db.connection.commit.assert_not_called()
    assert db.connection.autocommit is True


def test_structured_equivalent_default_config_passes_token_check() -> None:
    source, _, _ = _structured_reduction_source()
    current = {**source, "authority_extraction_config": {
        **source["authority_extraction_config"], "min_rows": 1, "max_rows": 200,
    }}
    db = object.__new__(DatabaseManager)
    db.connection = MagicMock(autocommit=True, closed=False)
    db.cursor = MagicMock()
    db.cursor.fetchone.return_value = current

    assert db.begin_source_persistence(source) is True
    db.finish_source_persistence(commit=False)
    assert db.connection.autocommit is True


def test_structured_process_sql_locks_source_reviews_snapshot_in_order_and_reads_locked_baseline() -> None:
    source, html, baseline = _structured_reduction_source()
    fingerprint = extract_structured_authorities(html, source["authority_extraction_config"]).config_fingerprint
    calls: list[str] = []
    snapshot = {
        "id": 22, "hash": hashlib.sha256(b"directory").hexdigest(),
        "texto": "directory", "autoridades_json": baseline[:2],
        "authority_extraction_revision": "revision-a",
        "authority_extraction_config_fingerprint": fingerprint,
    }

    class CursorSpy:
        def execute(self, sql: str, params: tuple[object, ...]) -> None:
            calls.append(" ".join(sql.split()))

        def fetchone(self) -> dict | None:
            if "FROM fuentes" in calls[-1]:
                return source
            if "FROM snapshots" in calls[-1]:
                return snapshot
            return None

        def fetchall(self) -> list:
            return []

    db = object.__new__(DatabaseManager)
    db.connection = MagicMock(autocommit=True, closed=False)
    db.cursor = CursorSpy()
    with patch.object(DatabaseManager, "__init__", return_value=None), \
         patch("pep_monitor.create_http_session", return_value=MagicMock()), \
         patch.object(PEPMonitor, "_obtener_html_raw", return_value=(html, "html_estatico")), \
         patch("pep_monitor.limpiar_html", return_value=(["directory"], "html_estatico")):
        monitor = PEPMonitor()
        monitor.db = db
        monitor.procesar_fuente(source)

    assert "FROM fuentes" in calls[0] and "FOR UPDATE" in calls[0]
    assert "FROM revisiones_remocion_autoridades" in calls[1] and "FOR UPDATE" in calls[1]
    assert "FROM snapshots" in calls[2] and "FOR UPDATE" in calls[2]
    assert not any("INSERT INTO snapshots" in sql or "INSERT INTO cambios" in sql for sql in calls)
    db.connection.commit.assert_called_once()
    assert db.connection.autocommit is True


def test_structured_source_guard_locks_current_row_before_any_persistence() -> None:
    class CursorSpy:
        def __init__(self) -> None:
            self.calls: list[tuple[str, tuple[object, ...]]] = []

        def execute(self, sql: str, params: tuple[object, ...]) -> None:
            self.calls.append((" ".join(sql.split()), params))

        def fetchone(self) -> dict[str, object]:
            return {
                "id": 81,
                "url": "https://example.test/authorities",
                "tipo": "html",
                "autoridades_extractor": "structured_css_v1",
                "authority_extraction_config": {
                    "version": 1,
                    "container_selector": ".directory",
                    "row_selector": ".row",
                    "person_selector": ".person",
                    "role_selector": ".role",
                },
                "activo": True,
                "authority_extraction_revision": "revision-a",
                "authority_extraction_confirmed_revision": "revision-a",
            }

    db = object.__new__(DatabaseManager)
    db.connection = MagicMock(autocommit=True, closed=False)
    db.cursor = CursorSpy()
    captured = {
        "id": 81,
        "url": "https://example.test/authorities",
        "tipo": "html",
        "autoridades_extractor": "structured_css_v1",
        "authority_extraction_config": {
            "version": 1,
            "container_selector": ".directory",
            "row_selector": ".row",
            "person_selector": ".person",
            "role_selector": ".role",
        },
        "activo": True,
        "authority_extraction_revision": "revision-a",
        "authority_extraction_confirmed_revision": "revision-a",
    }

    assert db.begin_source_persistence(captured) is True
    assert "FOR UPDATE" in db.cursor.calls[0][0]
    assert db.connection.autocommit is False
    db.finish_source_persistence(commit=False)
    db.connection.rollback.assert_called_once()
    assert db.connection.autocommit is True


def _structured_reduction_source() -> tuple[dict, str, list[dict]]:
    html = """
    <section class="directory">
      <article class="row"><span class="person">Ana Uno</span><span class="role">President</span></article>
      <article class="row"><span class="person">Luis Dos</span><span class="role">Secretary</span></article>
    </section>
    """
    source = {
        "id": 81,
        "url": "https://example.test/authorities",
        "nombre": "Structured example",
        "tipo": "html",
        "selector_css": ".directory",
        "autoridades_extractor": "structured_css_v1",
        "authority_extraction_config": {
            "version": 1,
            "container_selector": ".directory",
            "row_selector": ".row",
            "person_selector": ".person",
            "role_selector": ".role",
        },
        "authority_extraction_revision": "revision-a",
        "authority_extraction_confirmed_revision": "revision-a",
        "activo": True,
        "analizar_imagenes": False,
    }
    baseline = [
        {"cargo": "President", "persona": "Ana Uno"},
        {"cargo": "Secretary", "persona": "Luis Dos"},
        {"cargo": "Treasurer", "persona": "Marta Tres"},
    ]
    return source, html, baseline


def test_structured_reduction_saves_observed_snapshot_before_linked_review() -> None:
    db = MagicMock(spec=DatabaseManager)
    source, html, baseline = _structured_reduction_source()
    fingerprint = extract_structured_authorities(html, source["authority_extraction_config"]).config_fingerprint
    db.begin_source_persistence.return_value = True
    db.get_ultimo_snapshot.return_value = {
        "id": 22,
        "hash": hashlib.sha256(b"same content").hexdigest(),
        "texto": "same content",
        "autoridades_json": baseline,
        "authority_extraction_revision": "revision-a",
        "authority_extraction_config_fingerprint": fingerprint,
    }
    db.guardar_snapshot.return_value = 87
    db.registrar_revision_remocion_autoridades.return_value = "pending"

    with patch.object(DatabaseManager, "__init__", return_value=None), \
         patch("pep_monitor.create_http_session", return_value=MagicMock()), \
         patch.object(PEPMonitor, "_obtener_html_raw", return_value=(html, "html_estatico")), \
         patch("pep_monitor.limpiar_html", return_value=(["same content"], "html_estatico")):
        monitor = PEPMonitor()
        monitor.db = db
        monitor.procesar_fuente(source)

    db.guardar_cambio.assert_not_called()
    snapshot_position = next(index for index, call in enumerate(db.mock_calls) if call[0] == "guardar_snapshot")
    review_position = next(index for index, call in enumerate(db.mock_calls) if call[0] == "registrar_revision_remocion_autoridades")
    assert snapshot_position < review_position
    assert [item.to_dict() for item in db.guardar_snapshot.call_args.args[4]] == baseline
    review = db.registrar_revision_remocion_autoridades.call_args.kwargs
    assert review["snapshot_observado_id"] == 87
    assert review["evidence"] == {
        "version": 1,
        "source_revision": "revision-a",
        "config_fingerprint": fingerprint,
        "snapshot_base_id": 22,
        "snapshot_observado_id": 87,
        "source_url": "https://example.test/authorities",
        "extractor": "structured_css_v1",
        "baseline_hash": hashlib.sha256(b"same content").hexdigest(),
        "candidate_hash": hashlib.sha256(b"same content").hexdigest(),
        "observed_at": review["evidence"]["observed_at"],
    }
    db.actualizar_autoridades_ultimo_snapshot.assert_not_called()
    db.finish_source_persistence.assert_called_once_with(commit=True)


def test_structured_reduction_snapshot_failure_rolls_back_without_review() -> None:
    db = MagicMock(spec=DatabaseManager)
    source, html, baseline = _structured_reduction_source()
    fingerprint = extract_structured_authorities(html, source["authority_extraction_config"]).config_fingerprint
    db.begin_source_persistence.return_value = True
    db.get_ultimo_snapshot.return_value = {
        "id": 22,
        "hash": hashlib.sha256(b"same content").hexdigest(),
        "texto": "same content",
        "autoridades_json": baseline,
        "authority_extraction_revision": "revision-a",
        "authority_extraction_config_fingerprint": fingerprint,
    }
    db.guardar_snapshot.side_effect = RuntimeError("snapshot insert failed")

    with patch.object(DatabaseManager, "__init__", return_value=None), \
         patch("pep_monitor.create_http_session", return_value=MagicMock()), \
         patch.object(PEPMonitor, "_obtener_html_raw", return_value=(html, "html_estatico")), \
         patch("pep_monitor.limpiar_html", return_value=(["same content"], "html_estatico")):
        monitor = PEPMonitor()
        monitor.db = db
        monitor.procesar_fuente(source)

    db.registrar_revision_remocion_autoridades.assert_not_called()
    db.guardar_cambio.assert_not_called()
    db.finish_source_persistence.assert_called_once_with(commit=False)


def test_review_handoff_persists_observed_snapshot_id_in_review_sql() -> None:
    db = object.__new__(DatabaseManager)
    db.connection = MagicMock(autocommit=True, closed=False)
    db.cursor = MagicMock()
    db.cursor.fetchone.return_value = {"estado": "pending"}

    db.registrar_revision_remocion_autoridades(
        fuente_id=13,
        snapshot_base_id=22,
        snapshot_observado_id=87,
        baseline=[{"cargo": "President", "persona": "Ana Uno"}],
        candidate=[{"cargo": "President", "persona": "Ana Uno"}],
        proposed_events=[],
        evidence={"version": 1, "snapshot_observado_id": 87},
    )

    insert_sql, insert_params = db.cursor.execute.call_args_list[1].args
    assert "snapshot_observado_id" in insert_sql
    assert insert_params[2] == 87


def test_pending_review_refresh_keeps_observed_snapshot_column_and_evidence_in_sync() -> None:
    db = object.__new__(DatabaseManager)
    db.connection = MagicMock(autocommit=True, closed=False)
    db.cursor = MagicMock()
    baseline = [
        {"cargo": "President", "persona": "Ana Uno"},
        {"cargo": "Secretary", "persona": "Luis Dos"},
    ]
    candidate = baseline[:1]
    stored: dict[str, object] = {}
    selected: dict[str, str] | None = None
    fingerprints: list[str] = []

    def execute(sql: str, params: tuple[object, ...]) -> None:
        nonlocal selected
        statement = " ".join(sql.split())
        selected = None
        if statement.startswith("UPDATE revisiones_remocion_autoridades"):
            return
        if statement.startswith("INSERT INTO revisiones_remocion_autoridades"):
            fingerprints.append(str(params[-1]))
            if not stored:
                stored.update({
                    "snapshot_base_id": params[1],
                    "snapshot_observado_id": params[2],
                    "linea_base_json": params[3],
                    "evidencia_json": params[6],
                    "estado": "pending",
                })
            elif stored["estado"] == "pending":
                update_clause = statement.split("DO UPDATE SET ", 1)[1].split(" WHERE ", 1)[0]
                if "snapshot_base_id = EXCLUDED.snapshot_base_id" in update_clause:
                    stored["snapshot_base_id"] = params[1]
                if "snapshot_observado_id = EXCLUDED.snapshot_observado_id" in update_clause:
                    stored["snapshot_observado_id"] = params[2]
                if "evidencia_json = EXCLUDED.evidencia_json" in update_clause:
                    stored["evidencia_json"] = params[6]
            selected = {"estado": str(stored["estado"])}
            return
        raise AssertionError(f"Unexpected SQL: {statement}")

    db.cursor.execute.side_effect = execute
    db.cursor.fetchone.side_effect = lambda: selected
    for base_id, snapshot_id in ((22, 87), (87, 88)):
        assert db.registrar_revision_remocion_autoridades(
            fuente_id=13,
            snapshot_base_id=base_id,
            snapshot_observado_id=snapshot_id,
            baseline=baseline,
            candidate=candidate,
            proposed_events=[{"type": "remocion"}],
            evidence={"version": 1, "snapshot_base_id": base_id, "snapshot_observado_id": snapshot_id},
        ) == "pending"

    assert fingerprints[0] == fingerprints[1]
    assert stored["snapshot_base_id"] == 87
    assert json.loads(str(stored["evidencia_json"]))["snapshot_base_id"] == 87
    assert json.loads(str(stored["linea_base_json"])) == baseline
    assert stored["snapshot_observado_id"] == 88
    assert json.loads(str(stored["evidencia_json"]))["snapshot_observado_id"] == 88
    insert_sql = db.cursor.execute.call_args_list[-1].args[0]
    assert "WHERE revisiones_remocion_autoridades.estado = 'pending'" in insert_sql


class _BaselineCursor:
    """SQL spy with a mutable latest snapshot; never supplies a canned baseline."""

    def __init__(self, source: dict, snapshots: list[dict] | None = None) -> None:
        self.source = source
        self.snapshots = list(snapshots or [])
        self.statements: list[str] = []
        self.selected: dict | None = None
        self.reviews: list[dict] = []
        self.changes: list[tuple] = []

    def execute(self, sql: str, params: tuple = ()) -> None:
        statement = " ".join(sql.split())
        self.statements.append(statement)
        self.selected = None
        if statement.startswith("SELECT") and "FROM fuentes" in statement:
            assert "FOR UPDATE" in statement
            self.selected = self.source
        elif statement.startswith("SELECT") and "FROM snapshots" in statement:
            assert "FOR UPDATE" in statement
            assert "ORDER BY fecha DESC, id DESC LIMIT 1" in statement
            self.selected = self.snapshots[-1] if self.snapshots else None
        elif statement.startswith("SELECT") and "FROM revisiones_remocion_autoridades" in statement:
            assert "FOR UPDATE" in statement
        elif statement.startswith("SELECT") and "FROM snapshot_imagenes" in statement:
            pass
        elif statement.startswith("UPDATE snapshots SET autoridades_json"):
            assert self.snapshots
            self.snapshots[-1]["autoridades_json"] = json.loads(params[0])
        elif statement.startswith("INSERT INTO snapshots"):
            assert "authority_extraction_revision" in statement
            assert "authority_extraction_config_fingerprint" in statement
            source_id, hash_, text, method, roster, revision, fingerprint = params
            self.selected = {"id": len(self.snapshots) + 1}
            self.snapshots.append({
                "id": self.selected["id"], "fuente_id": source_id, "hash": hash_,
                "texto": text, "metodo": method,
                "autoridades_json": json.loads(roster) if roster is not None else None,
                "authority_extraction_revision": revision,
                "authority_extraction_config_fingerprint": fingerprint,
            })
        elif statement.startswith("INSERT INTO revisiones_remocion_autoridades"):
            self.reviews.append({"snapshot_base_id": params[1], "snapshot_observado_id": params[2],
                                 "baseline": json.loads(params[3]), "candidate": json.loads(params[4])})
            self.selected = {"estado": "pending"}
        elif statement.startswith("INSERT INTO cambios"):
            self.changes.append(params)
            self.selected = {"id": len(self.changes)}
        elif statement.startswith("UPDATE revisiones_remocion_autoridades"):
            pass
        elif statement.startswith("UPDATE fuentes SET ultimo_check"):
            pass
        elif statement.startswith("INSERT INTO log_fuente_runs"):
            self.run_status = params[3]
        else:
            raise AssertionError(f"Unexpected SQL: {statement}")

    def fetchone(self) -> dict | None:
        return self.selected

    def fetchall(self) -> list:
        return []


class _TiedSnapshotCursor(_BaselineCursor):
    """Evaluate latest-snapshot SQL against equal timestamps in unfavorable physical order."""

    def execute(self, sql: str, params: tuple = ()) -> None:
        statement = " ".join(sql.split())
        if statement.startswith("SELECT") and "FROM snapshots" in statement:
            self.statements.append(statement)
            assert "ORDER BY fecha DESC" in statement
            rows = [row for row in self.snapshots if row["fuente_id"] == params[0]]
            # Model an unfavorable physical scan for equal dates, independent of list[-1].
            rows.reverse()
            rows.sort(key=lambda row: row["fecha"], reverse=True)
            if "fecha DESC, id DESC" in statement:
                rows.sort(key=lambda row: (row["fecha"], row["id"]), reverse=True)
            self.selected = rows[0] if rows else None
            return
        if statement.startswith("UPDATE snapshots SET autoridades_json"):
            self.statements.append(statement)
            assert "ORDER BY fecha DESC, id DESC LIMIT 1" in statement
            subselect = statement.split("SELECT id FROM snapshots", 1)[1]
            self.execute("SELECT id FROM snapshots" + subselect.split(")", 1)[0], (params[1],))
            if self.selected:
                self.selected["autoridades_json"] = json.loads(params[0])
            return
        if statement.startswith("INSERT INTO snapshots"):
            next_id = max((row["id"] for row in self.snapshots), default=0) + 1
            super().execute(sql, params)
            self.snapshots[-1]["id"] = next_id
            self.snapshots[-1]["fecha"] = "2026-09-26 12:00:00"
            self.selected = {"id": next_id}
            return
        if statement.startswith("INSERT INTO revisiones_remocion_autoridades"):
            self.statements.append(statement)
            evidence = json.loads(params[6])
            if not self.reviews:
                self.reviews.append({"snapshot_base_id": params[1], "snapshot_observado_id": params[2],
                                     "baseline": json.loads(params[3]), "candidate": json.loads(params[4]),
                                     "evidence": evidence})
            else:
                updates = statement.split("DO UPDATE SET ", 1)[1].split(" WHERE ", 1)[0]
                for column, value in (("snapshot_base_id", params[1]),
                                      ("snapshot_observado_id", params[2]),
                                      ("evidencia_json", evidence)):
                    if f"{column} = EXCLUDED.{column}" in updates:
                        self.reviews[0]["evidence" if column == "evidencia_json" else column] = value
            self.selected = {"estado": "pending"}
            return
        super().execute(sql, params)


def test_tied_snapshot_latest_drives_repeated_generic_review_without_direct_removal() -> None:
    source, html, roster = _structured_reduction_source()
    fingerprint = extract_structured_authorities(html, source["authority_extraction_config"]).config_fingerprint
    old = {"id": 22, "fuente_id": source["id"], "fecha": "2026-09-26 12:00:00",
           "hash": hashlib.sha256(b"older page").hexdigest(), "texto": "older page",
           "autoridades_json": roster, "authority_extraction_revision": "revision-a",
           "authority_extraction_config_fingerprint": fingerprint}
    latest = {**old, "id": 87, "hash": hashlib.sha256(b"current page").hexdigest(),
              "texto": "current page"}
    db = _baseline_db(source)
    db.cursor = _TiedSnapshotCursor(source, [latest, old])
    assert db.get_ultimo_snapshot(source["id"])["id"] == 87
    for base_id, observed_id in ((87, 88), (88, 89)):
        db.cursor.statements.clear()
        _process_structured_baseline(db, source, html)
        _assert_locked_snapshot_read(db.cursor)
        assert db.cursor.run_status == "authority_review_pending"
        review = db.cursor.reviews[0]
        evidence = review["evidence"]
        assert review["snapshot_base_id"] == evidence["snapshot_base_id"] == base_id
        assert review["snapshot_observado_id"] == evidence["snapshot_observado_id"] == observed_id
        assert evidence["baseline_hash"] == next(s["hash"] for s in db.cursor.snapshots if s["id"] == base_id)
        assert evidence["candidate_hash"] == next(s["hash"] for s in db.cursor.snapshots if s["id"] == observed_id)
        assert review["baseline"] == roster and review["candidate"] == roster[:2]
        assert next(s for s in db.cursor.snapshots if s["id"] == observed_id)["autoridades_json"] == roster
        assert not db.cursor.changes
        db.cursor.snapshots.reverse()
    assert len(db.cursor.reviews) == 1


def test_tied_snapshot_authority_update_targets_highest_id_only() -> None:
    source, html, roster = _structured_reduction_source()
    fingerprint = extract_structured_authorities(html, source["authority_extraction_config"]).config_fingerprint
    old = {"id": 22, "fuente_id": source["id"], "fecha": "2026-09-26 12:00:00", "autoridades_json": roster}
    latest = {**old, "id": 87, "autoridades_json": []}
    db = _baseline_db(source)
    db.cursor = _TiedSnapshotCursor(source, [latest, old])
    db.actualizar_autoridades_ultimo_snapshot(source["id"], roster[:2])
    assert latest["autoridades_json"] == roster[:2]
    assert old["autoridades_json"] == roster


def _process_structured_baseline(db: DatabaseManager, source: dict, html: str) -> None:
    with patch.object(DatabaseManager, "__init__", return_value=None), \
         patch("pep_monitor.create_http_session", return_value=MagicMock()), \
         patch.object(PEPMonitor, "_obtener_html_raw", return_value=(html, "html_estatico")):
        monitor = PEPMonitor()
        monitor.db = db
        monitor.procesar_fuente(source)


def _baseline_db(source: dict, snapshots: list[dict] | None = None) -> DatabaseManager:
    db = object.__new__(DatabaseManager)
    db.connection = MagicMock(autocommit=True, closed=False)
    db.cursor = _BaselineCursor(source, snapshots)
    return db


def _assert_locked_snapshot_read(cursor: _BaselineCursor) -> None:
    statements = cursor.statements
    assert "FROM fuentes" in statements[0] and "FOR UPDATE" in statements[0]
    assert "FROM revisiones_remocion_autoridades" in statements[1]
    assert "FROM snapshots" in statements[2] and "FOR UPDATE" in statements[2]
    assert not any("FROM snapshots" in sql for sql in statements[:2])


@pytest.mark.parametrize("terminal_status", ["confirmed", "rejected"])
def test_structured_repeated_reduction_refreshes_pending_review_base_and_observation(terminal_status: str) -> None:
    class ReviewCursor(_BaselineCursor):
        def execute(self, sql: str, params: tuple = ()) -> None:
            statement = " ".join(sql.split())
            if statement.startswith("INSERT INTO revisiones_remocion_autoridades"):
                self.statements.append(statement)
                evidence = json.loads(params[6])
                fingerprint = params[7]
                existing = next((row for row in self.reviews if row["fingerprint"] == fingerprint), None)
                if existing is None:
                    self.reviews.append({
                        "fuente_id": params[0], "snapshot_base_id": params[1],
                        "snapshot_observado_id": params[2], "baseline": json.loads(params[3]),
                        "candidate": json.loads(params[4]), "evidence": evidence,
                        "fingerprint": fingerprint, "estado": "pending",
                    })
                    self.selected = {"estado": "pending"}
                elif existing["estado"] == "pending":
                    assignments = statement.split("DO UPDATE SET ", 1)[1].split(" WHERE ", 1)[0]
                    for column, value in (("snapshot_base_id", params[1]),
                                          ("snapshot_observado_id", params[2]),
                                          ("evidencia_json", evidence)):
                        if f"{column} = EXCLUDED.{column}" in assignments:
                            existing["evidence" if column == "evidencia_json" else column] = value
                    self.selected = {"estado": "pending"}
                else:
                    assert "WHERE revisiones_remocion_autoridades.estado = 'pending'" in statement
                    self.selected = None
                return
            if statement.startswith("SELECT estado FROM revisiones_remocion_autoridades"):
                self.statements.append(statement)
                self.selected = {"estado": self.reviews[0]["estado"]}
                return
            super().execute(sql, params)

    source, html, old_roster = _structured_reduction_source()
    fingerprint = extract_structured_authorities(html, source["authority_extraction_config"]).config_fingerprint
    baseline = {"id": 22, "fuente_id": source["id"], "hash": hashlib.sha256(b"old page").hexdigest(),
                "texto": "old page", "autoridades_json": old_roster,
                "authority_extraction_revision": "revision-a",
                "authority_extraction_config_fingerprint": fingerprint}
    db = _baseline_db(source, [baseline])
    db.cursor = ReviewCursor(source, [baseline])

    for expected_base, expected_observed in ((22, 2), (2, 3)):
        _process_structured_baseline(db, source, html)
        assert db.cursor.run_status == "authority_review_pending"
        assert len(db.cursor.reviews) == 1
        review = db.cursor.reviews[0]
        evidence = review["evidence"]
        assert review["snapshot_base_id"] == evidence["snapshot_base_id"] == expected_base
        assert review["snapshot_observado_id"] == evidence["snapshot_observado_id"] == expected_observed
        assert db.cursor.snapshots[-1]["id"] == expected_observed
        base = next(snapshot for snapshot in db.cursor.snapshots if snapshot["id"] == expected_base)
        assert base["fuente_id"] == source["id"]
        assert base["hash"] == evidence["baseline_hash"]
        assert review["baseline"] == old_roster
        assert review["candidate"] == old_roster[:2]
        assert db.cursor.snapshots[-1]["autoridades_json"] == old_roster
        assert not db.cursor.changes

    review_before = copy.deepcopy(db.cursor.reviews[0])
    db.cursor.reviews[0]["estado"] = terminal_status
    review_before["estado"] = terminal_status
    _process_structured_baseline(db, source, html)
    assert db.cursor.reviews == [review_before]
    assert not db.cursor.changes


def test_structured_first_baseline_and_identical_retry_use_locked_latest_snapshot() -> None:
    source, html, _ = _structured_reduction_source()
    db = _baseline_db(source)
    expected = [item.to_dict() for item in extract_structured_authorities(html, source["authority_extraction_config"]).authorities]
    fingerprint = extract_structured_authorities(html, source["authority_extraction_config"]).config_fingerprint

    _process_structured_baseline(db, source, html)
    _assert_locked_snapshot_read(db.cursor)
    assert db.cursor.run_status == "first_snapshot"
    assert len(db.cursor.snapshots) == 1
    assert db.cursor.snapshots[0]["autoridades_json"] == expected
    assert db.cursor.snapshots[0]["authority_extraction_revision"] == "revision-a"
    assert db.cursor.snapshots[0]["authority_extraction_config_fingerprint"] == fingerprint
    assert not db.cursor.changes and not db.cursor.reviews
    db.connection.commit.assert_called_once()

    db.cursor.statements.clear()
    _process_structured_baseline(db, source, html)
    _assert_locked_snapshot_read(db.cursor)
    assert db.cursor.run_status == "no_change"
    assert len(db.cursor.snapshots) == 1
    assert not db.cursor.changes and not db.cursor.reviews
    assert db.connection.commit.call_count == 2
    assert db.connection.autocommit is True


@pytest.mark.parametrize("transition", ["revision", "config", "legacy"])
def test_structured_confirmed_version_change_rebases_without_removal(transition: str) -> None:
    source, html, old_roster = _structured_reduction_source()
    db = _baseline_db(source)
    # Establish a genuine accepted baseline using the SQL insert, then simulate a
    # previously accepted larger roster at the same version (or legacy metadata).
    _process_structured_baseline(db, source, html)
    prior = db.cursor.snapshots[-1]
    prior["autoridades_json"] = old_roster
    if transition == "revision":
        source = {**source, "authority_extraction_revision": "revision-b",
                  "authority_extraction_confirmed_revision": "revision-b"}
    elif transition == "config":
        source = {**source, "authority_extraction_config": {
            **source["authority_extraction_config"], "max_rows": 100,
        }}
    else:
        prior["authority_extraction_revision"] = None
        prior["authority_extraction_config_fingerprint"] = None
    db.cursor.source = source
    expected_fingerprint = extract_structured_authorities(html, source["authority_extraction_config"]).config_fingerprint
    old_roster_copy = list(old_roster)
    db.cursor.statements.clear()

    _process_structured_baseline(db, source, html)
    _assert_locked_snapshot_read(db.cursor)
    assert prior["autoridades_json"] == old_roster_copy
    assert db.cursor.run_status == "first_snapshot"
    assert len(db.cursor.snapshots) == 2
    assert db.cursor.snapshots[-1]["autoridades_json"] == old_roster[:2]
    assert db.cursor.snapshots[-1]["authority_extraction_revision"] == source["authority_extraction_revision"]
    assert db.cursor.snapshots[-1]["authority_extraction_config_fingerprint"] == expected_fingerprint
    assert not db.cursor.changes and not db.cursor.reviews
    assert db.connection.autocommit is True


def test_structured_same_version_reduction_reviews_but_incomplete_never_replaces_roster() -> None:
    source, html, old_roster = _structured_reduction_source()
    db = _baseline_db(source)
    _process_structured_baseline(db, source, html)
    db.cursor.snapshots[-1]["autoridades_json"] = old_roster
    db.cursor.statements.clear()
    _process_structured_baseline(db, source, html)
    _assert_locked_snapshot_read(db.cursor)
    assert db.cursor.run_status == "authority_review_pending"
    assert db.cursor.reviews == [{"snapshot_base_id": 1, "snapshot_observado_id": 2,
                                  "baseline": old_roster, "candidate": old_roster[:2]}]
    assert db.cursor.snapshots[-1]["autoridades_json"] == old_roster
    assert not db.cursor.changes

    db.cursor.statements.clear()
    _process_structured_baseline(db, source, "<section class='directory'></section>")
    assert db.cursor.statements == []
    assert db.cursor.snapshots[-1]["autoridades_json"] == old_roster
    assert len(db.cursor.reviews) == 1 and not db.cursor.changes

    unconfigured = {**source, "authority_extraction_config": None}
    db.cursor.source = unconfigured
    _process_structured_baseline(db, unconfigured, html)
    assert db.cursor.statements == []
    assert db.cursor.snapshots[-1]["autoridades_json"] == old_roster
    assert len(db.cursor.reviews) == 1 and not db.cursor.changes


class _FailureCursor(_BaselineCursor):
    """Transactional SQL spy: undo all process writes on rollback, including writes before failure."""

    def __init__(self, source: dict, snapshots: list[dict], fail_at: str, *, return_none: bool = False) -> None:
        super().__init__(source, snapshots)
        self.fail_at = fail_at
        self.return_none = return_none
        self.attempted: list[str] = []
        self.original = copy.deepcopy((self.snapshots, self.reviews, self.changes))

    def execute(self, sql: str, params: tuple = ()) -> None:
        statement = " ".join(sql.split())
        if statement.startswith(("INSERT INTO snapshots", "INSERT INTO revisiones_remocion_autoridades",
                                  "INSERT INTO cambios", "UPDATE fuentes SET ultimo_check")):
            self.attempted.append(statement)
            if self.fail_at in statement:
                if self.return_none:
                    self.statements.append(statement)
                    self.selected = None
                    return
                raise RuntimeError(f"injected {self.fail_at}")
        super().execute(sql, params)

    def undo(self) -> None:
        self.snapshots[:], self.reviews[:], self.changes[:] = copy.deepcopy(self.original)


def _run_transaction_failure(path: str, failure: str, *, return_none: bool = False) -> tuple[DatabaseManager, _FailureCursor]:
    source, html, roster = _structured_reduction_source()
    fingerprint = extract_structured_authorities(html, source["authority_extraction_config"]).config_fingerprint
    baseline = {"id": 22, "hash": hashlib.sha256(b"old directory").hexdigest(),
                "texto": "old directory", "autoridades_json": roster,
                "authority_extraction_revision": "revision-a",
                "authority_extraction_config_fingerprint": fingerprint}
    if path == "event":
        baseline["autoridades_json"] = roster[:1]
    cursor = _FailureCursor(source, [baseline], failure, return_none=return_none)
    db = object.__new__(DatabaseManager)
    db.connection = MagicMock(autocommit=True, closed=False)
    db.connection.rollback.side_effect = cursor.undo
    db.cursor = cursor
    _process_structured_baseline(db, source, html)
    return db, cursor


@pytest.mark.parametrize("path,failure,return_none", [
    ("review", "INSERT INTO snapshots", False),
    ("review", "INSERT INTO snapshots", True),
    ("review", "INSERT INTO revisiones_remocion_autoridades", False),
    ("review", "UPDATE fuentes SET ultimo_check", False),
    ("event", "INSERT INTO cambios", False),
    ("event", "INSERT INTO cambios", True),
    ("event", "INSERT INTO snapshots", False),
    ("event", "UPDATE fuentes SET ultimo_check", False),
])
def test_structured_process_rolls_back_failed_writes(path: str, failure: str, return_none: bool) -> None:
    db, cursor = _run_transaction_failure(path, failure, return_none=return_none)
    assert any(failure in sql for sql in cursor.attempted)
    assert (cursor.snapshots, cursor.reviews, cursor.changes) == cursor.original
    db.connection.rollback.assert_called_once()
    db.connection.commit.assert_not_called()
    assert db.connection.autocommit is True


def test_structured_process_preserves_sql_diagnostic_when_rollback_also_fails(caplog: pytest.LogCaptureFixture) -> None:
    source, html, roster = _structured_reduction_source()
    fingerprint = extract_structured_authorities(html, source["authority_extraction_config"]).config_fingerprint
    baseline = {"id": 22, "hash": hashlib.sha256(b"old directory").hexdigest(),
                "texto": "old directory", "autoridades_json": roster,
                "authority_extraction_revision": "revision-a",
                "authority_extraction_config_fingerprint": fingerprint}
    cursor = _FailureCursor(source, [baseline], "INSERT INTO snapshots")
    db = object.__new__(DatabaseManager)
    db.connection = MagicMock(autocommit=True, closed=False)
    db.connection.rollback.side_effect = RuntimeError("rollback failed")
    db.cursor = cursor

    assert _process_structured_baseline(db, source, html) is None
    assert any("INSERT INTO snapshots" in sql for sql in cursor.attempted)
    assert "injected INSERT INTO snapshots" in caplog.text
    assert "rollback failed" in caplog.text
    db.connection.rollback.assert_called_once()
    db.connection.commit.assert_not_called()
    assert db.connection.autocommit is False
    assert not any("INSERT INTO log_fuente_runs" in sql for sql in cursor.statements)
    assert not cursor.reviews and not cursor.changes
    assert cursor.snapshots == [baseline]  # The failed write never happened; no rollback success is assumed.


def test_review_helper_missing_status_is_failure_not_none_success() -> None:
    db = object.__new__(DatabaseManager)
    db.connection = MagicMock(autocommit=True, closed=False)
    db.cursor = MagicMock()
    db.cursor.fetchone.return_value = None

    with pytest.raises(RuntimeError, match="review status unavailable"):
        db.registrar_revision_remocion_autoridades(
            fuente_id=13, snapshot_base_id=22, snapshot_observado_id=87,
            baseline=[{"cargo": "President", "persona": "Ana Uno"}],
            candidate=[{"cargo": "President", "persona": "Ana Uno"}],
            proposed_events=[], evidence={"version": 1},
        )
    db.connection.rollback.assert_called_once()
    db.connection.commit.assert_not_called()


def test_structured_process_commit_failure_rolls_back_without_partial_state() -> None:
    source, html, roster = _structured_reduction_source()
    fingerprint = extract_structured_authorities(html, source["authority_extraction_config"]).config_fingerprint
    baseline = {"id": 22, "hash": hashlib.sha256(b"old directory").hexdigest(),
                "texto": "old directory", "autoridades_json": roster,
                "authority_extraction_revision": "revision-a", "authority_extraction_config_fingerprint": fingerprint}
    cursor = _FailureCursor(source, [baseline], "never")
    db = object.__new__(DatabaseManager)
    db.connection = MagicMock(autocommit=True, closed=False)
    db.connection.commit.side_effect = RuntimeError("commit failed")
    db.connection.rollback.side_effect = cursor.undo
    db.cursor = cursor
    with pytest.raises(RuntimeError, match="commit failed"):
        _process_structured_baseline(db, source, html)
    assert cursor.attempted
    assert (cursor.snapshots, cursor.reviews, cursor.changes) == cursor.original
    db.connection.commit.assert_called_once()
    db.connection.rollback.assert_called_once()
    assert db.connection.autocommit is True


def test_structured_commit_and_rollback_failures_preserve_commit_error() -> None:
    source, _, _ = _structured_reduction_source()
    db = _baseline_db(source)
    db.connection.commit.side_effect = RuntimeError("commit failed")
    db.connection.rollback.side_effect = RuntimeError("rollback failed")
    assert db.begin_source_persistence(source) is True
    with pytest.raises(RuntimeError, match="commit failed"):
        db.finish_source_persistence(commit=True)
    db.connection.rollback.assert_called_once()
    assert db.connection.autocommit is False  # no restoration promise for an unhealthy connection


def test_structured_process_guard_query_failure_rolls_back_without_writes() -> None:
    source, html, _ = _structured_reduction_source()
    db = _baseline_db(source)
    def fail_query(sql: str, params: tuple = ()) -> None:
        raise RuntimeError("guard query failed")
    db.cursor.execute = fail_query
    _process_structured_baseline(db, source, html)
    db.connection.rollback.assert_called_once()
    db.connection.commit.assert_not_called()
    assert db.connection.autocommit is True


def test_structured_guard_rollback_failure_preserves_primary_query_error() -> None:
    source, _, _ = _structured_reduction_source()
    db = _baseline_db(source)
    db.cursor.execute = MagicMock(side_effect=RuntimeError("guard query failed"))
    db.connection.rollback.side_effect = RuntimeError("rollback failed")
    with pytest.raises(RuntimeError, match="guard query failed"):
        db.begin_source_persistence(source)
    db.connection.rollback.assert_called_once()
    assert db.connection.autocommit is False  # rollback failed: connection health is unknown


def test_monitor_does_not_fabricate_removals_without_configured_extractor() -> None:
    db = MagicMock(spec=DatabaseManager)
    fuente = {
        "id": 13,
        "url": "https://www.asuss.gob.bo/recursos-humanos/#autoridades",
        "nombre": "ASUSS",
        "tipo": "html",
        "selector_css": ".entry-content",
        "autoridades_extractor": None,
        "analizar_imagenes": False,
    }
    db.get_ultimo_snapshot.return_value = {
        "hash": "same-text-hash",
        "texto": "contenido sin cambios",
        "autoridades_json": [{"cargo": "Director Ejecutivo", "persona": "Dr. José Álvarez"}],
    }

    with patch.object(DatabaseManager, "__init__", return_value=None), \
         patch("pep_monitor.create_http_session", return_value=MagicMock()), \
         patch.object(PEPMonitor, "_obtener_html_raw", return_value=("<p>sin autoridades</p>", "html_estatico")), \
         patch("pep_monitor.limpiar_html", return_value=(["contenido sin cambios"], "html_estatico")), \
         patch("pep_monitor.hashlib.sha256") as sha:
        sha.return_value.hexdigest.return_value = "same-text-hash"
        monitor = PEPMonitor()
        monitor.db = db
        monitor.procesar_fuente(fuente)

    db.guardar_cambio.assert_not_called()
    db.guardar_snapshot.assert_not_called()


class _PreviewCursor:
    """SQL-sensitive state spy; not a substitute for concurrent PostgreSQL tests."""

    def __init__(self, source: dict | None, preview: dict) -> None:
        self.source, self.preview = source, preview
        self.calls: list[str] = []
        self.selected: dict | None = None
        self.fail_update = False

    def execute(self, sql: str, params: tuple = ()) -> None:
        statement = " ".join(sql.split())
        self.calls.append(statement)
        self.selected = None
        if statement.startswith("SELECT") and "FROM fuentes" in statement:
            assert "FOR UPDATE" in statement
            self.selected = self.source
        elif statement.startswith("SELECT") and "FROM authority_extraction_previews" in statement:
            assert "FOR UPDATE" in statement
            self.selected = self.preview
        elif statement.startswith("UPDATE authority_extraction_previews"):
            if self.fail_update:
                raise RuntimeError("preview write failed")
            assert "WHERE fuente_id = %s" in statement
            if "status = 'stale'" in statement:
                self.preview.update(status="stale", lease_token=None, lease_expires_at=None,
                                    authorities_json=None, config_fingerprint=None, error_code=None)
            elif "status = 'error'" in statement:
                assert "attempts_exhausted" in statement
                self.preview.update(status="error", lease_token=None, lease_expires_at=None,
                                    authorities_json=None, config_fingerprint=None, error_code="attempts_exhausted")
            else:
                assert "status = 'running'" in statement and "attempts = attempts + 1" in statement
                assert "lease_expires_at" in statement and "lease_token" in statement
                self.preview.update(status="running", attempts=self.preview["attempts"] + 1,
                                    lease_token=params[0], lease_expires_at=params[1])
        else:
            raise AssertionError(f"Unexpected SQL: {statement}")

    def fetchone(self) -> dict | None:
        return self.selected


def _preview_db(source: dict | None = None, preview: dict | None = None) -> tuple[DatabaseManager, _PreviewCursor]:
    template, _, _ = _structured_reduction_source()
    row = {"fuente_id": 81, "source_revision": "revision-a", "source_url": template["url"],
           "status": "pending", "attempts": 0, "lease_token": None, "lease_expires_at": None,
           "authorities_json": None, "config_fingerprint": None}
    cursor = _PreviewCursor(template if source is None else source, row if preview is None else preview)
    db = object.__new__(DatabaseManager)
    db.connection = MagicMock(autocommit=True, closed=False)
    db.cursor = cursor
    return db, cursor


def test_preview_discovery_is_bounded_ordered_and_independent_of_active_sources() -> None:
    db, _ = _preview_db()
    db.cursor = MagicMock()
    db.cursor.fetchall.return_value = [{"fuente_id": 81}, {"fuente_id": 82}]
    assert db.discover_preview_candidates(limit=2) == [81, 82]
    sql, params = db.cursor.execute.call_args.args
    assert "authority_extraction_previews" in sql and "get_fuentes_activas" not in sql
    assert "activo" not in sql and "FOR UPDATE" not in sql
    assert "pending" in sql and "running" in sql and "lease_expires_at" in sql
    assert "ORDER BY requested_at, id" in sql and "LIMIT %s" in sql
    assert params[-1] == 2
    db.cursor.fetchall.return_value = []
    assert db.discover_preview_candidates() == []


def test_preview_claim_inactive_source_locks_source_first_and_is_idempotent() -> None:
    db, cursor = _preview_db()
    cursor.source["activo"] = False
    cursor.source["authority_extraction_confirmed_revision"] = None
    first = db.claim_preview(81)
    assert first is not None
    assert first["source"]["activo"] is False and first["source"]["url"] == cursor.source["url"]
    assert first["source"]["authority_extraction_config"] is not cursor.source["authority_extraction_config"]
    assert first["source_revision"] == "revision-a" and first["fuente_id"] == 81
    assert first["lease_token"] == cursor.preview["lease_token"]
    assert cursor.preview["attempts"] == 1
    assert "FROM fuentes" in cursor.calls[0] and "FOR UPDATE" in cursor.calls[0]
    assert "FROM authority_extraction_previews" in cursor.calls[1] and "FOR UPDATE" in cursor.calls[1]
    assert db.claim_preview(81) is None
    assert cursor.preview["attempts"] == 1
    db.connection.commit.assert_called_once()
    assert db.connection.autocommit is True


def test_preview_expiry_reclaims_with_new_token_and_caps_attempts() -> None:
    from datetime import timedelta
    from datetime import datetime, timezone
    db, cursor = _preview_db()
    first = db.claim_preview(81)
    old_token = first["lease_token"]
    cursor.preview["lease_expires_at"] = datetime.now(timezone.utc) - timedelta(seconds=1)
    second = db.claim_preview(81)
    assert second["lease_token"] != old_token and cursor.preview["attempts"] == 2
    cursor.preview["lease_expires_at"] = datetime.now(timezone.utc) - timedelta(seconds=1)
    db.claim_preview(81)
    cursor.preview["lease_expires_at"] = datetime.now(timezone.utc) - timedelta(seconds=1)
    assert db.claim_preview(81) is None
    assert cursor.preview["status"] == "error" and cursor.preview["error_code"] == "attempts_exhausted"
    assert cursor.preview["lease_token"] is None
    assert db.claim_preview(81) is None


@pytest.mark.parametrize("field,value", [
    ("authority_extraction_revision", "revision-b"),
    ("url", "https://example.test/new"),
    ("tipo", "pdf"),
    ("autoridades_extractor", "divi_blurb"),
    ("authority_extraction_config", None),
])
def test_preview_invalid_identity_stales_without_claim(field: str, value: object) -> None:
    db, cursor = _preview_db()
    cursor.source[field] = value
    cursor.preview.update(lease_token="old", authorities_json=[{"persona": "Ana"}])
    assert db.claim_preview(81) is None
    assert cursor.preview["status"] == "stale" and cursor.preview["lease_token"] is None
    assert cursor.preview["authorities_json"] is None
    db.connection.commit.assert_called_once()


@pytest.mark.parametrize("field,value", [
    ("source_revision", "revision-old"),
    ("source_url", "https://example.test/previous"),
])
def test_preview_row_identity_mismatch_stales(field: str, value: str) -> None:
    db, cursor = _preview_db()
    cursor.preview[field] = value
    assert db.claim_preview(81) is None
    assert cursor.preview["status"] == "stale"


def test_preview_unexpired_running_does_not_claim_or_mutate_attempts() -> None:
    from datetime import datetime, timedelta, timezone
    db, cursor = _preview_db()
    cursor.preview.update(status="running", attempts=1, lease_token="previous",
                          lease_expires_at=datetime.now(timezone.utc) + timedelta(minutes=3))
    assert db.claim_preview(81) is None
    assert cursor.preview["attempts"] == 1 and cursor.preview["lease_token"] == "previous"
    db.connection.commit.assert_not_called()


def test_preview_missing_source_and_pending_attempts_cap() -> None:
    db, cursor = _preview_db()
    cursor.source = None
    assert db.claim_preview(81) is None
    assert cursor.preview["status"] == "stale"
    db, cursor = _preview_db()
    cursor.preview["attempts"] = 3
    assert db.claim_preview(81) is None
    assert cursor.preview["status"] == "error"


def test_preview_claim_commit_failure_rolls_back_without_autocommit_leak() -> None:
    db, _ = _preview_db()
    db.connection.commit.side_effect = RuntimeError("commit failed")
    with pytest.raises(RuntimeError, match="commit failed"):
        db.claim_preview(81)
    db.connection.rollback.assert_called_once()
    assert db.connection.autocommit is True


def test_preview_claim_write_failure_rolls_back_and_restores_autocommit() -> None:
    db, cursor = _preview_db()
    cursor.fail_update = True
    with pytest.raises(RuntimeError, match="preview write failed"):
        db.claim_preview(81)
    db.connection.rollback.assert_called_once()
    db.connection.commit.assert_not_called()
    assert db.connection.autocommit is True


class _CompletionCursor(_PreviewCursor):
    """Check the executed SQL assignments, not merely an invented result state."""

    def execute(self, sql: str, params: tuple = ()) -> None:
        statement = " ".join(sql.split())
        if not statement.startswith("UPDATE authority_extraction_previews") or "status = 'running'" in statement:
            super().execute(sql, params)
            return
        self.calls.append(statement)
        if self.fail_update:
            raise RuntimeError("preview write failed")
        assert "WHERE fuente_id = %s" in statement
        if "status = 'stale'" in statement:
            assert "lease_token = NULL" in statement and "lease_expires_at = NULL" in statement
            assert "authorities_json = NULL" in statement and "config_fingerprint = NULL" in statement
            self.preview.update(status="stale", lease_token=None, lease_expires_at=None,
                                authorities_json=None, config_fingerprint=None, error_code=None)
            return
        assert "status = %s" in statement
        assert "authorities_json = %s" in statement and "config_fingerprint = %s" in statement
        assert "error_code = %s" in statement and "completed_at = NOW()" in statement
        assert "updated_at = NOW()" in statement
        assert "lease_token = NULL" in statement and "lease_expires_at = NULL" in statement
        status, authorities, fingerprint, error_code, fuente_id = params
        assert fuente_id == self.preview["fuente_id"]
        self.preview.update(status=status, authorities_json=authorities,
                            config_fingerprint=fingerprint, error_code=error_code,
                            lease_token=None, lease_expires_at=None)


def _completion_db() -> tuple[DatabaseManager, _CompletionCursor, dict, str]:
    from datetime import datetime, timedelta, timezone
    source, html, _ = _structured_reduction_source()
    cursor = _CompletionCursor(copy.deepcopy(source), {
        "fuente_id": 81, "source_revision": "revision-a", "source_url": source["url"],
        "status": "pending", "attempts": 0, "lease_token": None, "lease_expires_at": None,
        "authorities_json": None, "config_fingerprint": None, "error_code": None,
    })
    db = object.__new__(DatabaseManager)
    db.connection = MagicMock(autocommit=True, closed=False)
    db.cursor = cursor
    claim = db.claim_preview(81)
    assert claim is not None
    cursor.calls.clear()
    cursor.preview["lease_expires_at"] = datetime.now(timezone.utc) + timedelta(minutes=3)
    db.connection.reset_mock()
    return db, cursor, claim, html


class _WorkerCursor(_CompletionCursor):
    def __init__(self, sources: list[dict]) -> None:
        template, _, _ = _structured_reduction_source()
        super().__init__(None, {})
        self.sources = {source["id"]: source for source in sources}
        self.previews = {
            source["id"]: {"fuente_id": source["id"], "source_revision": "revision-a",
                           "source_url": source["url"], "status": "pending", "attempts": 0,
                           "lease_token": None, "lease_expires_at": None,
                           "authorities_json": None, "config_fingerprint": None, "error_code": None}
            for source in sources
        }
        self.active_id = template["id"]
        self.discovered: list[int] = []

    def execute(self, sql: str, params: tuple = ()) -> None:
        statement = " ".join(sql.split())
        if statement.startswith("SELECT fuente_id FROM authority_extraction_previews"):
            self.calls.append(statement)
            assert params == (25,)
            self.discovered = [key for key, row in self.previews.items() if row["status"] == "pending"][:25]
            return
        if "FROM fuentes WHERE id = %s" in statement or "WHERE fuente_id = %s FOR UPDATE" in statement:
            self.active_id = params[0]
            self.source = self.sources[self.active_id]
            self.preview = self.previews[self.active_id]
        super().execute(sql, params)

    def fetchall(self) -> list[dict]:
        return [{"fuente_id": key} for key in self.discovered]


def _worker_monitor(sources: list[dict]) -> tuple[PEPMonitor, DatabaseManager, _WorkerCursor]:
    db = object.__new__(DatabaseManager)
    db.connection = MagicMock(autocommit=True, closed=False)
    cursor = _WorkerCursor(sources)
    db.cursor = cursor
    with patch.object(DatabaseManager, "__init__", return_value=None), \
         patch("pep_monitor.create_http_session", return_value=MagicMock()):
        monitor = PEPMonitor()
    monitor.db = db
    return monitor, db, cursor


def test_preview_worker_inactive_only_cycle_persists_ready_without_monitor_writes(caplog: pytest.LogCaptureFixture) -> None:
    source, html, _ = _structured_reduction_source()
    source.update(activo=False, authority_extraction_confirmed_revision=None)
    monitor, db, cursor = _worker_monitor([source])
    db.get_fuentes_activas = MagicMock(return_value=[])
    db.log_inicio = MagicMock(return_value=17)
    db.log_fin = MagicMock()
    expected = extract_structured_authorities(html, source["authority_extraction_config"])
    with patch.object(monitor, "_obtener_html_raw", return_value=(html, "html_estatico")) as fetch, \
         patch.object(monitor, "procesar_fuente") as process:
        monitor.check_all()
    fetch.assert_called_once()
    assert fetch.call_args.args[0]["id"] == source["id"]
    process.assert_not_called()
    assert db.get_fuentes_activas.call_count == 1
    assert cursor.previews[81]["status"] == "ready"
    assert json.loads(cursor.previews[81]["authorities_json"]) == [item.to_dict() for item in expected.authorities]
    assert cursor.previews[81]["config_fingerprint"] == expected.config_fingerprint
    assert cursor.calls[0].startswith("SELECT fuente_id FROM authority_extraction_previews")
    assert not any(name in " ".join(cursor.calls).lower() for name in
                   ("snapshots", "cambios", "revisiones_remocion", "ultimo_check", "log_fuente_runs"))
    db.log_inicio.assert_not_called()
    db.log_fin.assert_not_called()
    assert "Verificando 0 fuente(s)" not in caplog.text


def test_preview_discovery_failure_keeps_active_monitoring_and_does_not_complete_preview(
    caplog: pytest.LogCaptureFixture,
) -> None:
    source, _, _ = _structured_reduction_source()
    source["activo"] = True
    monitor, db, _ = _worker_monitor([source])
    db.discover_preview_candidates = MagicMock(side_effect=RuntimeError("secret preview SQL detail"))
    db.claim_preview = MagicMock()
    db.complete_preview = MagicMock()
    db.get_fuentes_activas = MagicMock(return_value=[source])
    db.get_ultimo_snapshot = MagicMock(return_value={"hash": "unchanged"})
    db.log_inicio = MagicMock(return_value=17)
    db.log_fin = MagicMock()
    with patch.object(monitor, "_process_preview_candidates") as previews, \
         patch.object(monitor, "procesar_fuente") as process, \
         patch("pep_monitor.hay_internet", return_value=True):
        monitor.check_all()
    db.discover_preview_candidates.assert_called_once_with(limit=25)
    previews.assert_not_called()
    db.claim_preview.assert_not_called()
    db.complete_preview.assert_not_called()
    db.get_fuentes_activas.assert_called_once_with()
    process.assert_called_once_with(source)
    db.log_inicio.assert_called_once_with()
    db.log_fin.assert_called_once_with(
        17, estado="completado", items_procesados=1, items_resultado=0,
        errores=0, mensaje_error=None,
    )
    assert "Preview discovery failed" in caplog.text
    assert "secret preview SQL detail" not in caplog.text


def test_preview_worker_empty_cycle_warns_without_monitor_audit(caplog: pytest.LogCaptureFixture) -> None:
    monitor, db, cursor = _worker_monitor([])
    db.get_fuentes_activas = MagicMock(return_value=[])
    db.log_inicio = MagicMock(return_value=17)
    db.log_fin = MagicMock()
    with patch.object(monitor, "_obtener_html_raw") as fetch, \
         patch.object(monitor, "procesar_fuente") as process:
        monitor.check_all()
    assert cursor.discovered == []
    fetch.assert_not_called()
    process.assert_not_called()
    db.log_inicio.assert_not_called()
    db.log_fin.assert_not_called()
    assert "No hay fuentes registradas" in caplog.text
    assert "Verificando 0 fuente(s)" not in caplog.text


@pytest.mark.parametrize("response,code,fingerprint", [
    (("<section class='directory'></section>", "html_estatico"), "row_count_out_of_bounds", True),
    (("<raw secret>", "error_http"), "fetch_error", False),
    (requests.exceptions.SSLError("secret transport error"), "fetch_error", False),
])
def test_preview_worker_failure_vs_incomplete_never_persists_raw_content(response: object, code: str, fingerprint: bool) -> None:
    source, _, _ = _structured_reduction_source()
    source["activo"] = False
    monitor, db, cursor = _worker_monitor([source])
    db.get_fuentes_activas = MagicMock(return_value=[])
    db.log_inicio = MagicMock(return_value=1)
    db.log_fin = MagicMock()
    with patch.object(monitor, "_obtener_html_raw", side_effect=response if isinstance(response, Exception) else None,
                      return_value=response if not isinstance(response, Exception) else None):
        monitor.check_all()
    row = cursor.previews[81]
    assert row["status"] == ("incomplete" if fingerprint else "error")
    assert row["error_code"] == code and row["authorities_json"] is None
    assert (row["config_fingerprint"] is not None) is fingerprint
    assert "secret" not in repr(row) and "<raw" not in repr(row)


@pytest.mark.parametrize("change", [
    lambda c: c.sources[81].__setitem__("url", "https://example.test/edited"),
    lambda c: c.previews[81].__setitem__("lease_token", "new-lease"),
])
def test_preview_worker_discards_stale_output_after_fetch(change: object) -> None:
    source, html, _ = _structured_reduction_source()
    monitor, db, cursor = _worker_monitor([source])
    db.get_fuentes_activas = MagicMock(return_value=[])
    db.log_inicio = MagicMock(return_value=1)
    db.log_fin = MagicMock()
    def fetch(_source: dict, *, preview: bool = False) -> tuple[str, str]:
        assert preview is True
        change(cursor)
        return html, "html_estatico"
    with patch.object(monitor, "_obtener_html_raw", side_effect=fetch):
        monitor.check_all()
    assert cursor.previews[81]["status"] != "ready"
    assert cursor.previews[81]["authorities_json"] is None


def test_preview_worker_bounds_candidates_and_isolates_one_job_failure_from_active_monitor() -> None:
    source, html, _ = _structured_reduction_source()
    sources = [{**copy.deepcopy(source), "id": index} for index in range(81, 111)]
    monitor, db, cursor = _worker_monitor(sources)
    active = {**UMSA_SOURCE}
    db.get_fuentes_activas = MagicMock(return_value=[active])
    db.log_inicio = MagicMock(return_value=1)
    db.log_fin = MagicMock()
    db.get_ultimo_snapshot = MagicMock(return_value={"hash": "same"})
    seen: list[int] = []
    def fetch(claimed: dict, *, preview: bool = False) -> tuple[str, str]:
        assert preview is True
        seen.append(claimed["id"])
        if claimed["id"] == 81:
            raise RuntimeError("sensitive error")
        return html, "html_estatico"
    with patch.object(monitor, "_obtener_html_raw", side_effect=fetch), \
         patch.object(monitor, "procesar_fuente") as process, \
         patch("pep_monitor.hay_internet", return_value=True):
        monitor.check_all()
    assert seen == list(range(81, 106))
    assert cursor.previews[81]["status"] == "error"
    assert cursor.previews[82]["status"] == "ready"
    assert cursor.previews[106]["status"] == "pending"
    process.assert_called_once_with(active)
    db.log_inicio.assert_called_once_with()
    db.log_fin.assert_called_once_with(
        1, estado="completado", items_procesados=1, items_resultado=0,
        errores=0, mensaje_error=None,
    )


def test_preview_fetch_hides_transport_exception_from_logs(caplog: pytest.LogCaptureFixture) -> None:
    from pep_monitor import OutboundUrlError
    source, _, _ = _structured_reduction_source()
    monitor, _, _ = _worker_monitor([source])
    with patch("pep_monitor._request_public_url", side_effect=OutboundUrlError("secret raw URL")):
        assert monitor._obtener_html_raw(source, preview=True) == ("", "error_http")
    assert "secret raw URL" not in caplog.text


def test_preview_complete_ready_persists_bounded_parser_payload_and_source_first_locks() -> None:
    db, cursor, claim, html = _completion_db()
    parsed = extract_structured_authorities(html, claim["source"]["authority_extraction_config"])
    assert db.complete_preview(claim, parsed) is True
    assert "FROM fuentes" in cursor.calls[0] and "FOR UPDATE" in cursor.calls[0]
    assert "FROM authority_extraction_previews" in cursor.calls[1] and "FOR UPDATE" in cursor.calls[1]
    assert len(cursor.calls) == 3 and cursor.calls[2].startswith("UPDATE authority_extraction_previews")
    assert cursor.preview["status"] == "ready"
    assert json.loads(cursor.preview["authorities_json"]) == [item.to_dict() for item in parsed.authorities]
    assert cursor.preview["config_fingerprint"] == parsed.config_fingerprint
    assert cursor.preview["error_code"] is None and cursor.preview["lease_token"] is None
    db.connection.commit.assert_called_once()
    assert db.connection.autocommit is True
    cursor.calls.clear()
    assert db.complete_preview(claim, parsed) is False  # replay does not refresh completed_at
    assert not any(sql.startswith("UPDATE") for sql in cursor.calls)


@pytest.mark.parametrize("html,status,code", [
    ("<section class='directory'></section>", "incomplete", "row_count_out_of_bounds"),
    ("", "incomplete", "container_scope_invalid"),
])
def test_preview_complete_incomplete_keeps_valid_fingerprint_without_authorities(html: str, status: str, code: str) -> None:
    db, cursor, claim, _ = _completion_db()
    parsed = extract_structured_authorities(html, claim["source"]["authority_extraction_config"])
    assert db.complete_preview(claim, parsed) is True
    assert cursor.preview["status"] == status and cursor.preview["error_code"] == code
    assert cursor.preview["authorities_json"] is None
    assert cursor.preview["config_fingerprint"] == parsed.config_fingerprint


@pytest.mark.parametrize("bad_config,code", [(None, "unconfigured"), ({"version": 7}, "invalid_config")])
def test_preview_complete_never_promotes_unconfigured_or_invalid_result(bad_config: object, code: str) -> None:
    db, cursor, claim, _ = _completion_db()
    parsed = extract_structured_authorities("", bad_config)
    assert db.complete_preview(claim, parsed) is True
    assert cursor.preview["status"] == "incomplete" and cursor.preview["error_code"] == code
    assert cursor.preview["authorities_json"] is None and cursor.preview["config_fingerprint"] is None


def test_preview_complete_transport_error_is_fixed_and_contains_no_exception_text() -> None:
    db, cursor, claim, _ = _completion_db()
    assert db.complete_preview(claim, {"status": "error", "error_code": "fetch_error"}) is True
    assert cursor.preview["status"] == "error" and cursor.preview["error_code"] == "fetch_error"
    assert cursor.preview["authorities_json"] is None
    assert cursor.preview["config_fingerprint"] is None


@pytest.mark.parametrize("change", [
    lambda c: c.source.__setitem__("url", "https://example.test/new"),
    lambda c: c.source.__setitem__("authority_extraction_revision", "revision-b"),
    lambda c: c.source["authority_extraction_config"].__setitem__("row_selector", ".different"),
    lambda c: c.source.__setitem__("tipo", "pdf"),
    lambda c: c.source.__setitem__("autoridades_extractor", "divi_blurb"),
])
def test_preview_complete_stales_only_its_own_running_claim_on_source_change(change: object) -> None:
    db, cursor, claim, html = _completion_db()
    parsed = extract_structured_authorities(html, claim["source"]["authority_extraction_config"])
    change(cursor)
    assert db.complete_preview(claim, parsed) is False
    assert cursor.preview["status"] == "stale" and cursor.preview["authorities_json"] is None
    assert cursor.preview["lease_token"] is None
    assert cursor.calls[2].startswith("UPDATE authority_extraction_previews")
    assert len(cursor.calls) == 3 and db.connection.commit.call_count == 1


@pytest.mark.parametrize("change", [
    lambda c: c.preview.__setitem__("lease_token", "replacement"),
    lambda c: c.preview.__setitem__("status", "pending"),
    lambda c: c.preview.__setitem__("source_revision", "revision-b"),
    lambda c: c.preview.__setitem__("source_url", "https://example.test/new"),
    lambda c: c.preview.__setitem__("lease_expires_at", None),
])
def test_preview_complete_cannot_touch_replacement_claim(change: object) -> None:
    db, cursor, claim, html = _completion_db()
    parsed = extract_structured_authorities(html, claim["source"]["authority_extraction_config"])
    change(cursor)
    previous = copy.deepcopy(cursor.preview)
    assert db.complete_preview(claim, parsed) is False
    assert cursor.preview == previous
    assert len(cursor.calls) == 2 and all("FOR UPDATE" in sql for sql in cursor.calls)
    db.connection.commit.assert_not_called()
    db.connection.rollback.assert_called_once()


def test_preview_complete_rejects_mutated_claim_config_without_rewriting_preview() -> None:
    db, cursor, claim, html = _completion_db()
    parsed = extract_structured_authorities(html, claim["source"]["authority_extraction_config"])
    claim["source"]["authority_extraction_config"]["row_selector"] = ".changed"
    with pytest.raises(ValueError, match="claim"):
        db.complete_preview(claim, parsed)
    assert not any(sql.startswith("UPDATE") for sql in cursor.calls)
    db.connection.commit.assert_not_called()


def test_preview_complete_accepts_postgres_naive_timestamp_before_expiry() -> None:
    db, cursor, claim, html = _completion_db()
    cursor.preview["lease_expires_at"] = cursor.preview["lease_expires_at"].replace(tzinfo=None)
    parsed = extract_structured_authorities(html, claim["source"]["authority_extraction_config"])
    assert db.complete_preview(claim, parsed) is True
    assert cursor.preview["status"] == "ready"


def test_preview_complete_expired_lease_cannot_overwrite_result() -> None:
    from datetime import datetime, timedelta, timezone
    db, cursor, claim, html = _completion_db()
    cursor.preview["lease_expires_at"] = datetime.now(timezone.utc) - timedelta(seconds=1)
    parsed = extract_structured_authorities(html, claim["source"]["authority_extraction_config"])
    assert db.complete_preview(claim, parsed) is False
    assert len(cursor.calls) == 2 and cursor.preview["status"] == "running"
    db.connection.commit.assert_not_called()


@pytest.mark.parametrize("invalid", [
    {"status": "error", "error_code": "private exception: secret"},
    {"status": "error", "error_code": "fetch_error", "html": "raw"},
    {"status": "ready", "authorities": [{"cargo": "Unsafe", "persona": "Fake"}]},
    "raw html", None,
])
def test_preview_complete_rejects_arbitrary_results_without_writes(invalid: object) -> None:
    db, cursor, claim, _ = _completion_db()
    with pytest.raises(ValueError):
        db.complete_preview(claim, invalid)
    assert not any(sql.startswith("UPDATE") for sql in cursor.calls)
    db.connection.commit.assert_not_called()
    assert db.connection.autocommit is True


def test_preview_complete_malformed_result_cannot_even_stale_source_changed_row() -> None:
    db, cursor, claim, _ = _completion_db()
    cursor.source["url"] = "https://example.test/new"
    with pytest.raises(ValueError):
        db.complete_preview(claim, {"status": "error", "error_code": "raw error"})
    assert cursor.preview["status"] == "running" and cursor.preview["lease_token"] == claim["lease_token"]
    assert not any(sql.startswith("UPDATE") for sql in cursor.calls)
    db.connection.commit.assert_not_called()


def test_preview_complete_rejects_oversize_parser_payload_and_forged_fingerprint() -> None:
    from structured_authorities import StructuredAuthoritiesResult
    for result in (
        StructuredAuthoritiesResult("ready", (Authority("x" * 301, "Ana"),), "a" * 64, None),
        StructuredAuthoritiesResult("ready", (Authority("Role", "Ana"),) * 201, "a" * 64, None),
        StructuredAuthoritiesResult("ready", (Authority("Role", "Ana"),), "z" * 64, None),
    ):
        db, cursor, claim, _ = _completion_db()
        with pytest.raises(ValueError):
            db.complete_preview(claim, result)
        assert cursor.calls == [] and db.connection.commit.call_count == 0


@pytest.mark.parametrize("failure", ["update", "commit", "rollback"])
def test_preview_complete_failure_restores_only_after_successful_rollback(failure: str) -> None:
    db, cursor, claim, html = _completion_db()
    parsed = extract_structured_authorities(html, claim["source"]["authority_extraction_config"])
    if failure == "update":
        cursor.fail_update = True
    else:
        db.connection.commit.side_effect = RuntimeError("commit failed")
    if failure == "rollback":
        db.connection.rollback.side_effect = RuntimeError("rollback failed")
    with pytest.raises(RuntimeError, match="preview write failed|commit failed"):
        db.complete_preview(claim, parsed)
    db.connection.rollback.assert_called_once()
    assert db.connection.autocommit is (failure != "rollback")
    if failure == "update":
        db.connection.commit.assert_not_called()
