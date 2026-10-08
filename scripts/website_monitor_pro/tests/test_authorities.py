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
