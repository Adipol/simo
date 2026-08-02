from __future__ import annotations

import os
import sys
from pathlib import Path
from unittest.mock import MagicMock, patch

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from authorities import Authority, compare_authorities, extract_authorities
from pep_monitor import DatabaseManager, PEPMonitor


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
         patch.object(PEPMonitor, "_obtener_html_raw", return_value=(ASUSS_HTML, "html_estatico")), \
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
    alert.assert_called_once()
    assert call_order == ["alert", "snapshot"]
    assert db.guardar_snapshot.call_args.args[4][0].persona == "Lic. Ana Pérez"


def test_monitor_persists_removals_for_explicitly_empty_roster() -> None:
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
    assert db.guardar_snapshot.call_args.args[4] == []


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
        "hash": "old-hash", "texto": "contenido anterior", "autoridades_json": previous,
    }
    db.guardar_cambio.return_value = 940
    partial_html = COMPLETE_ASUSS_HTML.replace("<p>María Quispe</p>", "")

    with patch.object(DatabaseManager, "__init__", return_value=None), \
         patch("pep_monitor.create_http_session", return_value=MagicMock()), \
         patch.object(PEPMonitor, "_obtener_html_raw", return_value=(partial_html, "html_estatico")), \
         patch("pep_monitor.limpiar_html", return_value=(["contenido nuevo"], "html_estatico")), \
         patch("pep_monitor.mostrar_alerta"), \
         patch("pep_monitor.hashlib.sha256") as sha:
        sha.return_value.hexdigest.return_value = "new-hash"
        monitor = PEPMonitor()
        monitor.db = db
        monitor.procesar_fuente(fuente)

    assert db.guardar_cambio.call_args.kwargs["autoridades_eventos"] == []
    payload = db.guardar_snapshot.call_args.args[4]
    assert [item.to_dict() for item in payload[:2]] == previous
    assert payload[2]["_authority_roster"] == {
        "version": 2,
        "pending": [{"cargo": "Director Ejecutivo", "persona": "Dr. José Álvarez"}],
    }


def test_monitor_confirms_only_same_reduced_roster_on_next_run() -> None:
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
    db.guardar_cambio.return_value = 941

    with patch.object(DatabaseManager, "__init__", return_value=None), \
         patch("pep_monitor.create_http_session", return_value=MagicMock()), \
         patch.object(PEPMonitor, "_obtener_html_raw", return_value=(reduced_html, "html_estatico")), \
         patch("pep_monitor.limpiar_html", return_value=(["contenido sin cambios"], "html_estatico")), \
         patch("pep_monitor.hashlib.sha256") as sha:
        sha.return_value.hexdigest.return_value = "same-text-hash"
        monitor = PEPMonitor()
        monitor.db = db
        monitor.procesar_fuente(fuente)

    assert [event["type"] for event in db.guardar_cambio.call_args.kwargs["autoridades_eventos"]] == ["remocion"]
    assert [item.to_dict() for item in db.guardar_snapshot.call_args.args[4]] == pending


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

    expanded = COMPLETE_ASUSS_HTML.replace(
        "</div>", '<div class="et_pb_blurb_container"><h4>Gerente</h4><p>Ana Pérez</p></div></div>', 1
    )
    run(expanded)
    assert db.guardar_cambio.call_args.kwargs["autoridades_eventos"][0]["type"] == "designacion"
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
