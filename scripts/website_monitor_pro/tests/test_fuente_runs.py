"""
Tests TDD para source health tracking — PR-B Python
====================================================

Cubre:
  - DatabaseManager.registrar_fuente_run  (T30-T34)
  - PEPMonitor.procesar_fuente try/finally (T35-T44)

Ejecutar con:
    python -m pytest scripts/website_monitor_pro/tests/test_fuente_runs.py -v
"""
from __future__ import annotations

import sys
import os
import logging
from datetime import datetime, timezone, timedelta
from unittest.mock import MagicMock, patch, call, PropertyMock
from typing import Optional

import psycopg2
import pytest

# Importable desde cualquier CWD
sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

import pep_monitor
from pep_monitor import DatabaseManager, PEPMonitor


# ════════════════════════════════════════════════════════════════
# HELPERS
# ════════════════════════════════════════════════════════════════

def _make_fuente(
    fuente_id: int = 1,
    url: str = "https://example.com/directorio",
    nombre: str = "Test Fuente",
    tipo: str = "html",
    selector_css: Optional[str] = None,
    analizar_imagenes: bool = False,
) -> dict:
    """Crea un dict con la forma de una fila de la tabla fuentes."""
    return {
        "id": fuente_id,
        "url": url,
        "nombre": nombre,
        "organismo": "Test Org",
        "pais": "Bolivia",
        "nivel": "nacional",
        "tipo": tipo,
        "selector_css": selector_css,
        "analizar_imagenes": analizar_imagenes,
        "ultimo_check": None,
    }


def _make_db_manager_mock() -> MagicMock:
    """
    Crea un mock de DatabaseManager con cursor y connection configurados.
    Evita que __init__ llame a psycopg2.connect o _verify_tables.
    """
    db = MagicMock(spec=DatabaseManager)
    # cursor mock con soporte de context manager
    cursor = MagicMock()
    cursor.__enter__ = MagicMock(return_value=cursor)
    cursor.__exit__ = MagicMock(return_value=False)
    db.cursor = cursor
    # connection mock
    conn = MagicMock()
    conn.closed = False
    db.connection = conn
    return db


def _make_pep_monitor_with_mock_db() -> tuple[PEPMonitor, MagicMock]:
    """
    Construye un PEPMonitor con DatabaseManager y HTTP session mockeados.
    Evita cualquier I/O real.
    """
    db_mock = _make_db_manager_mock()
    http_mock = MagicMock()

    # Parchamos __init__ para no conectar a BD real
    with patch.object(DatabaseManager, "__init__", return_value=None), \
         patch("pep_monitor.create_http_session", return_value=http_mock):
        monitor = PEPMonitor()

    monitor.db = db_mock
    monitor.http = http_mock
    monitor.running = True
    return monitor, db_mock


# ════════════════════════════════════════════════════════════════
# FASE 7 — T30-T34: DatabaseManager.registrar_fuente_run
# ════════════════════════════════════════════════════════════════

class TestRegistrarFuenteRun:
    """Tests para DatabaseManager.registrar_fuente_run"""

    def _make_db(self) -> DatabaseManager:
        """
        Instancia DatabaseManager sin conexión real.
        Reemplaza connection y cursor con mocks.
        """
        with patch.object(DatabaseManager, "__init__", return_value=None):
            db = DatabaseManager()
        db.connection = MagicMock()
        db.connection.closed = False
        db.cursor = MagicMock()
        db._ensure_connection = MagicMock()  # no-op
        return db

    # ── T30: Happy path — INSERT correcto con todos los parámetros ──
    def test_registrar_fuente_run_writes_row_with_all_params(self):
        """
        registrar_fuente_run debe ejecutar INSERT en log_fuente_runs
        con todos los parámetros en el orden correcto.
        """
        db = self._make_db()
        started_at = datetime(2026, 5, 10, 12, 0, 0, tzinfo=timezone.utc)
        finished_at = datetime(2026, 5, 10, 12, 0, 5, tzinfo=timezone.utc)

        db.registrar_fuente_run(
            fuente_id=7,
            started_at=started_at,
            finished_at=finished_at,
            estado="success",
            http_status=200,
            cambios_detectados=3,
            error_mensaje=None,
            duracion_segundos=5.0,
        )

        db._ensure_connection.assert_called_once()
        db.cursor.execute.assert_called_once()
        call_args = db.cursor.execute.call_args
        sql: str = call_args[0][0]
        params: tuple = call_args[0][1]

        # La SQL debe ser un INSERT en log_fuente_runs
        assert "INSERT" in sql.upper()
        assert "LOG_FUENTE_RUNS" in sql.upper()

        # Verificar parámetros en orden
        assert params[0] == 7              # fuente_id
        assert params[1] == started_at    # started_at
        assert params[2] == finished_at   # finished_at
        assert params[3] == "success"     # estado
        assert params[4] == 200           # http_status
        assert params[5] == 3             # cambios_detectados
        assert params[6] is None          # error_mensaje
        assert params[7] == 5.0           # duracion_segundos

    def test_registrar_fuente_run_writes_row_with_error_estado(self):
        """
        Triangulación: estado=http_error con http_status y error_mensaje poblados.
        """
        db = self._make_db()
        started_at = datetime(2026, 5, 10, 12, 0, 0, tzinfo=timezone.utc)
        finished_at = datetime(2026, 5, 10, 12, 0, 2, tzinfo=timezone.utc)

        db.registrar_fuente_run(
            fuente_id=42,
            started_at=started_at,
            finished_at=finished_at,
            estado="http_error",
            http_status=404,
            cambios_detectados=0,
            error_mensaje="Not Found",
            duracion_segundos=2.1,
        )

        call_args = db.cursor.execute.call_args
        params = call_args[0][1]

        assert params[0] == 42
        assert params[3] == "http_error"
        assert params[4] == 404
        assert params[5] == 0
        assert params[6] == "Not Found"
        assert params[7] == pytest.approx(2.1, rel=1e-3)

    # ── T31: DB error graceful — psycopg2.Error → log warning, no raise ──
    def test_registrar_fuente_run_handles_psycopg2_error_gracefully(self, caplog):
        """
        Si cursor.execute lanza psycopg2.Error, debe:
        - loguear WARNING con el fuente_id
        - NO propagar la excepción
        """
        db = self._make_db()
        db.cursor.execute.side_effect = psycopg2.Error("duplicate key value")

        started_at = datetime(2026, 5, 10, 12, 0, 0, tzinfo=timezone.utc)
        finished_at = datetime(2026, 5, 10, 12, 0, 1, tzinfo=timezone.utc)

        with caplog.at_level(logging.WARNING):
            # NO debe lanzar
            db.registrar_fuente_run(
                fuente_id=5,
                started_at=started_at,
                finished_at=finished_at,
                estado="success",
                http_status=200,
                cambios_detectados=0,
                error_mensaje=None,
                duracion_segundos=1.0,
            )

        # Debe haber logueado warning
        assert any("5" in r.message for r in caplog.records), \
            "El warning debe mencionar el fuente_id"
        assert any(r.levelno == logging.WARNING for r in caplog.records)

    # ── T32: _ensure_connection falla → log, no raise ──
    def test_registrar_fuente_run_handles_connection_lost(self, caplog):
        """
        Si _ensure_connection lanza psycopg2.Error (conexión perdida),
        registrar_fuente_run debe:
        - loguear WARNING
        - NO propagar la excepción
        """
        db = self._make_db()
        db._ensure_connection.side_effect = psycopg2.OperationalError("connection lost")

        started_at = datetime(2026, 5, 10, 12, 0, 0, tzinfo=timezone.utc)
        finished_at = datetime(2026, 5, 10, 12, 0, 1, tzinfo=timezone.utc)

        with caplog.at_level(logging.WARNING):
            db.registrar_fuente_run(
                fuente_id=99,
                started_at=started_at,
                finished_at=finished_at,
                estado="other",
                http_status=None,
                cambios_detectados=0,
                error_mensaje=None,
                duracion_segundos=0.5,
            )

        assert any(r.levelno == logging.WARNING for r in caplog.records)
        # cursor.execute no debe haber sido llamado si _ensure_connection falló
        db.cursor.execute.assert_not_called()

    # ── T33: error_mensaje largo → truncado a 500 chars (como log_fin) ──
    def test_registrar_fuente_run_truncates_long_error_mensaje(self):
        """
        error_mensaje largo debe truncarse a 500 caracteres (consistente con log_fin).
        """
        db = self._make_db()
        mensaje_largo = "E" * 600
        started_at = datetime(2026, 5, 10, 12, 0, 0, tzinfo=timezone.utc)
        finished_at = datetime(2026, 5, 10, 12, 0, 1, tzinfo=timezone.utc)

        db.registrar_fuente_run(
            fuente_id=1,
            started_at=started_at,
            finished_at=finished_at,
            estado="other",
            http_status=None,
            cambios_detectados=0,
            error_mensaje=mensaje_largo,
            duracion_segundos=1.0,
        )

        call_args = db.cursor.execute.call_args
        params = call_args[0][1]
        error_en_bd = params[6]
        assert error_en_bd is not None
        assert len(error_en_bd) <= 500

    # ── T34: None error_mensaje → stays None ──
    def test_registrar_fuente_run_none_error_mensaje_stays_none(self):
        """error_mensaje=None debe pasarse como None al INSERT, no como string."""
        db = self._make_db()
        started_at = datetime(2026, 5, 10, 12, 0, 0, tzinfo=timezone.utc)
        finished_at = datetime(2026, 5, 10, 12, 0, 1, tzinfo=timezone.utc)

        db.registrar_fuente_run(
            fuente_id=1,
            started_at=started_at,
            finished_at=finished_at,
            estado="no_change",
            http_status=200,
            cambios_detectados=0,
            error_mensaje=None,
            duracion_segundos=0.8,
        )

        call_args = db.cursor.execute.call_args
        params = call_args[0][1]
        assert params[6] is None


# ════════════════════════════════════════════════════════════════
# FASE 8 — T35-T44: PEPMonitor.procesar_fuente try/finally
# ════════════════════════════════════════════════════════════════

class TestProcesarFuenteTracking:
    """
    Tests para verificar que procesar_fuente instrumenta correctamente
    cada exit path y llama a registrar_fuente_run via try/finally.
    """

    # ── T35: success path ──────────────────────────────────────
    def test_procesar_fuente_logs_success_path(self):
        """
        Cuando procesar_fuente detecta un cambio real y lo guarda,
        registrar_fuente_run debe llamarse con estado='success'
        y cambios_detectados >= 1.
        """
        monitor, db_mock = _make_pep_monitor_with_mock_db()
        fuente = _make_fuente()

        # Snapshot anterior existe con hash diferente → hay cambio
        snapshot_ant = {"hash": "oldhash", "texto": "linea vieja"}
        snapshot_nuevo = {"hash": "newhash", "texto": "linea nueva"}
        db_mock.get_ultimo_snapshot.side_effect = [snapshot_ant, snapshot_nuevo]
        db_mock.guardar_cambio.return_value = 42

        html_con_texto = "<html><body><p>linea nueva</p></body></html>"
        with patch.object(monitor, "_obtener_html_raw", return_value=(html_con_texto, "html_estatico")), \
             patch("pep_monitor.limpiar_html", return_value=(["linea nueva"], "html_estatico")), \
             patch("pep_monitor.calcular_diff", return_value={
                 "quitadas": ["linea vieja"],
                 "nuevas": ["linea nueva"],
                 "diff_texto": "diff",
                 "posibles_peps": "",
             }), \
             patch("pep_monitor.mostrar_alerta"), \
             patch("hashlib.sha256") as mock_sha:
            # Forzar que el hash nuevo difiera del snapshot anterior
            mock_sha.return_value.hexdigest.return_value = "newhash"
            monitor.procesar_fuente(fuente)

        db_mock.registrar_fuente_run.assert_called_once()
        call_kwargs = db_mock.registrar_fuente_run.call_args.kwargs
        assert call_kwargs["fuente_id"] == 1
        assert call_kwargs["estado"] == "success"
        assert call_kwargs["cambios_detectados"] >= 1

    # ── T36: no_content path ───────────────────────────────────
    def test_procesar_fuente_logs_no_content_path(self):
        """
        Cuando limpiar_html retorna lista vacía,
        registrar_fuente_run debe llamarse con estado='no_content'.
        """
        monitor, db_mock = _make_pep_monitor_with_mock_db()
        fuente = _make_fuente()

        html_vacio = "<html><body></body></html>"
        with patch.object(monitor, "_obtener_html_raw", return_value=(html_vacio, "html_estatico")), \
             patch("pep_monitor.limpiar_html", return_value=([], "html_estatico")):
            monitor.procesar_fuente(fuente)

        db_mock.registrar_fuente_run.assert_called_once()
        call_kwargs = db_mock.registrar_fuente_run.call_args.kwargs
        assert call_kwargs["fuente_id"] == 1
        assert call_kwargs["estado"] == "no_content"

    # ── T37: first_snapshot path ────────────────────────────────
    def test_procesar_fuente_logs_first_snapshot_path(self):
        """
        Cuando get_ultimo_snapshot retorna None (primera vez),
        registrar_fuente_run debe llamarse con estado='first_snapshot'.
        """
        monitor, db_mock = _make_pep_monitor_with_mock_db()
        fuente = _make_fuente()

        db_mock.get_ultimo_snapshot.return_value = None  # primera vez

        html = "<html><body><p>contenido inicial</p></body></html>"
        with patch.object(monitor, "_obtener_html_raw", return_value=(html, "html_estatico")), \
             patch("pep_monitor.limpiar_html", return_value=(["contenido inicial"], "html_estatico")):
            monitor.procesar_fuente(fuente)

        db_mock.registrar_fuente_run.assert_called_once()
        call_kwargs = db_mock.registrar_fuente_run.call_args.kwargs
        assert call_kwargs["fuente_id"] == 1
        assert call_kwargs["estado"] == "first_snapshot"

    # ── T38: no_change path ─────────────────────────────────────
    def test_procesar_fuente_logs_no_change_path(self):
        """
        Cuando el hash del contenido nuevo coincide con el snapshot anterior,
        registrar_fuente_run debe llamarse con estado='no_change'.
        """
        monitor, db_mock = _make_pep_monitor_with_mock_db()
        fuente = _make_fuente()

        # Mismo texto → mismo hash
        texto = "contenido sin cambios"
        import hashlib
        hash_igual = hashlib.sha256(texto.encode("utf-8")).hexdigest()
        snapshot_ant = {"hash": hash_igual, "texto": texto}
        db_mock.get_ultimo_snapshot.return_value = snapshot_ant

        html = f"<html><body><p>{texto}</p></body></html>"
        with patch.object(monitor, "_obtener_html_raw", return_value=(html, "html_estatico")), \
             patch("pep_monitor.limpiar_html", return_value=([texto], "html_estatico")):
            monitor.procesar_fuente(fuente)

        db_mock.registrar_fuente_run.assert_called_once()
        call_kwargs = db_mock.registrar_fuente_run.call_args.kwargs
        assert call_kwargs["estado"] == "no_change"

    # ── T39: http_error path ────────────────────────────────────
    def test_procesar_fuente_logs_http_error_with_status_code(self):
        """
        Cuando _obtener_html_raw lanza requests.HTTPError con status 403,
        registrar_fuente_run debe llamarse con estado='http_error'
        y http_status=403.
        """
        import requests
        monitor, db_mock = _make_pep_monitor_with_mock_db()
        fuente = _make_fuente()

        mock_response = MagicMock()
        mock_response.status_code = 403
        http_err = requests.exceptions.HTTPError("403 Forbidden", response=mock_response)

        with patch.object(monitor, "_obtener_html_raw", side_effect=http_err):
            monitor.procesar_fuente(fuente)

        db_mock.registrar_fuente_run.assert_called_once()
        call_kwargs = db_mock.registrar_fuente_run.call_args.kwargs
        assert call_kwargs["estado"] == "http_error"
        assert call_kwargs["http_status"] == 403
        assert call_kwargs["error_mensaje"] is not None

    # ── T40: timeout path ───────────────────────────────────────
    def test_procesar_fuente_logs_timeout_path(self):
        """
        Cuando _obtener_html_raw lanza requests.Timeout,
        registrar_fuente_run debe llamarse con estado='timeout'.
        """
        import requests
        monitor, db_mock = _make_pep_monitor_with_mock_db()
        fuente = _make_fuente()

        with patch.object(monitor, "_obtener_html_raw",
                          side_effect=requests.exceptions.Timeout("timed out")):
            monitor.procesar_fuente(fuente)

        db_mock.registrar_fuente_run.assert_called_once()
        call_kwargs = db_mock.registrar_fuente_run.call_args.kwargs
        assert call_kwargs["estado"] == "timeout"
        assert "timed" in call_kwargs["error_mensaje"].lower()

    # ── T41: parse_error path ───────────────────────────────────
    def test_procesar_fuente_logs_parse_error_path(self):
        """
        Cuando limpiar_html lanza una excepción (parse error),
        registrar_fuente_run debe llamarse con estado='parse_error'.
        """
        monitor, db_mock = _make_pep_monitor_with_mock_db()
        fuente = _make_fuente()

        html = "<html><body>test</body></html>"
        with patch.object(monitor, "_obtener_html_raw", return_value=(html, "html_estatico")), \
             patch("pep_monitor.limpiar_html", side_effect=Exception("BeautifulSoup parse error")):
            monitor.procesar_fuente(fuente)

        db_mock.registrar_fuente_run.assert_called_once()
        call_kwargs = db_mock.registrar_fuente_run.call_args.kwargs
        assert call_kwargs["estado"] == "parse_error"
        assert call_kwargs["error_mensaje"] is not None

    # ── T42: other (unexpected exception) ───────────────────────
    def test_procesar_fuente_finally_fires_on_unexpected_exception(self):
        """
        Cuando se lanza una excepción inesperada (no mapeada),
        registrar_fuente_run debe llamarse con estado='other'
        y el finally debe ejecutarse SIEMPRE.
        """
        monitor, db_mock = _make_pep_monitor_with_mock_db()
        fuente = _make_fuente()

        with patch.object(monitor, "_obtener_html_raw",
                          side_effect=RuntimeError("unexpected failure")):
            # La excepción no debe propagarse fuera de procesar_fuente
            # (el finally la captura y loguea)
            monitor.procesar_fuente(fuente)

        db_mock.registrar_fuente_run.assert_called_once()
        call_kwargs = db_mock.registrar_fuente_run.call_args.kwargs
        assert call_kwargs["estado"] == "other"
        assert "unexpected failure" in call_kwargs["error_mensaje"]

    # ── T43: DB failure in finally does NOT break scraper ───────
    def test_procesar_fuente_db_failure_does_not_break_scraper(self):
        """
        Si registrar_fuente_run lanza una excepción (DB caída),
        procesar_fuente debe SEGUIR FUNCIONANDO normalmente
        y no propagar el error al caller.
        """
        monitor, db_mock = _make_pep_monitor_with_mock_db()
        fuente = _make_fuente()

        # registrar_fuente_run lanza psycopg2.Error
        db_mock.registrar_fuente_run.side_effect = psycopg2.Error("connection lost")
        db_mock.get_ultimo_snapshot.return_value = None  # first_snapshot path — simple

        html = "<html><body><p>contenido</p></body></html>"
        with patch.object(monitor, "_obtener_html_raw", return_value=(html, "html_estatico")), \
             patch("pep_monitor.limpiar_html", return_value=(["contenido"], "html_estatico")):
            # No debe lanzar — la métrica nunca rompe el pipeline
            monitor.procesar_fuente(fuente)

        # registrar_fuente_run fue intentado (la llamada ocurrió)
        db_mock.registrar_fuente_run.assert_called_once()

    # ── T44: timestamps son tz-aware UTC ────────────────────────
    def test_procesar_fuente_uses_utc_timestamps(self):
        """
        started_at y finished_at pasados a registrar_fuente_run
        deben ser tz-aware con tzinfo=UTC.
        """
        monitor, db_mock = _make_pep_monitor_with_mock_db()
        fuente = _make_fuente()

        db_mock.get_ultimo_snapshot.return_value = None  # first_snapshot path

        html = "<html><body><p>contenido</p></body></html>"
        with patch.object(monitor, "_obtener_html_raw", return_value=(html, "html_estatico")), \
             patch("pep_monitor.limpiar_html", return_value=(["contenido"], "html_estatico")):
            monitor.procesar_fuente(fuente)

        db_mock.registrar_fuente_run.assert_called_once()
        call_kwargs = db_mock.registrar_fuente_run.call_args.kwargs
        started_at = call_kwargs["started_at"]
        finished_at = call_kwargs["finished_at"]

        # Ambos deben ser tz-aware
        assert started_at.tzinfo is not None, "started_at debe ser tz-aware"
        assert finished_at.tzinfo is not None, "finished_at debe ser tz-aware"

        # UTC offset debe ser cero
        assert started_at.utcoffset() == timedelta(0), "started_at debe estar en UTC"
        assert finished_at.utcoffset() == timedelta(0), "finished_at debe estar en UTC"

        # finished_at >= started_at (el tiempo avanza)
        assert finished_at >= started_at
