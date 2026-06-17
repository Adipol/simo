"""
Tests for runner.py — Strict TDD RED written first.

runner.py mirrors website_monitor_pro/runner.py:
- Reads config_scripts WHERE script='gaceta'
- Seeds ultimo_ciclo from log_scripts (survives restarts)
- Locks with gaceta_runner.lock
- Launches main.py --once as subprocess
- Writes wrapper log_scripts row with counts=0
- 30s loop, controlled by DB config

Key test: test_runner_seeds_ultimo_ciclo_from_log_scripts
All DB and subprocess calls are MOCKED.
"""
import os
import sys
from datetime import datetime, timedelta
from pathlib import Path
from unittest.mock import MagicMock, patch

import psycopg2
import pytest

# Add package root to path (handled by conftest.py already)


def _make_db_mock(fetchone_row=None):
    """Create a mock psycopg2 connection with fetchone configured."""
    conn = MagicMock()
    cursor = MagicMock()
    cursor.__enter__ = MagicMock(return_value=cursor)
    cursor.__exit__ = MagicMock(return_value=False)
    cursor.fetchone.return_value = fetchone_row
    conn.cursor.return_value = cursor
    return conn


def _make_cfg(
    habilitado=True,
    intervalo_minutos=60,
    timeout_minutos=30,
    hora_inicio=None,
    hora_fin=None,
    dias_semana="1,2,3,4,5,6,7",
) -> dict:
    return {
        "habilitado": habilitado,
        "intervalo_minutos": intervalo_minutos,
        "timeout_minutos": timeout_minutos,
        "hora_inicio": hora_inicio,
        "hora_fin": hora_fin,
        "dias_semana": dias_semana,
    }


class TestRunnerSmoke:
    """runner.py imports and exposes expected constants."""

    def test_runner_importable(self) -> None:
        """runner.py imports without error."""
        import runner
        assert runner is not None

    def test_runner_has_lock_file_constant(self) -> None:
        """LOCK_FILE constant exists and is a Path."""
        import runner
        assert isinstance(runner.LOCK_FILE, Path)

    def test_runner_has_loop_interval(self) -> None:
        """LOOP_INTERVAL_DEFAULT is a positive integer."""
        import runner
        assert runner.LOOP_INTERVAL_DEFAULT > 0


class TestRunnerSeedsUltimoCiclo:
    """runner.main() seeds ultimo_ciclo from log_scripts on startup."""

    def test_seed_skips_run_when_recent_cycle_in_db(self) -> None:
        """
        If seed returns a datetime within the interval,
        debe_ejecutar → False → scraper NOT called.
        """
        import runner

        class _Stop(BaseException):
            pass

        seed_time = datetime.now() - timedelta(minutes=30)
        cfg = _make_cfg(intervalo_minutos=60, habilitado=True)
        mock_conn = MagicMock()

        def mock_sleep(_: float) -> None:
            raise _Stop()

        with patch.object(runner, "get_db_connection", return_value=mock_conn), \
             patch.object(runner, "leer_config_gaceta", return_value=cfg), \
             patch.object(runner, "leer_ultimo_ciclo_gaceta", return_value=seed_time), \
             patch.object(runner, "dia_activo", return_value=True), \
             patch.object(runner, "en_ventana_horaria", return_value=True), \
             patch.object(runner, "ejecutar_gaceta") as mock_ejecutar, \
             patch("time.sleep", side_effect=mock_sleep):
            with pytest.raises(_Stop):
                runner.main()

        mock_ejecutar.assert_not_called()

    def test_seed_runs_immediately_when_no_history(self) -> None:
        """If seed returns None, debe_ejecutar → True → scraper IS called."""
        import runner

        class _Stop(BaseException):
            pass

        cfg = _make_cfg(intervalo_minutos=60, habilitado=True)
        mock_conn = MagicMock()
        resultado = {
            "estado": "ok", "returncode": 0, "duracion": 1.0, "mensaje_error": None,
        }

        def mock_sleep(_: float) -> None:
            raise _Stop()

        with patch.object(runner, "get_db_connection", return_value=mock_conn), \
             patch.object(runner, "leer_config_gaceta", return_value=cfg), \
             patch.object(runner, "leer_ultimo_ciclo_gaceta", return_value=None), \
             patch.object(runner, "dia_activo", return_value=True), \
             patch.object(runner, "en_ventana_horaria", return_value=True), \
             patch.object(runner, "lock_existe_y_activo", return_value=False), \
             patch.object(runner, "escribir_lock"), \
             patch.object(runner, "registrar_log_scripts"), \
             patch.object(runner, "limpiar_lock"), \
             patch.object(runner, "ejecutar_gaceta", return_value=resultado) as mock_ejecutar, \
             patch("time.sleep", side_effect=mock_sleep):
            with pytest.raises(_Stop):
                runner.main()

        mock_ejecutar.assert_called_once()

    def test_db_down_at_startup_falls_back_to_none(self) -> None:
        """If DB raises at seed time, runner sets ultimo_ciclo=None without crashing."""
        import runner

        class _Stop(BaseException):
            pass

        cfg = _make_cfg(habilitado=True)
        mock_conn = MagicMock()
        call_count = [0]

        def mock_get_db() -> MagicMock:
            call_count[0] += 1
            if call_count[0] == 1:
                raise psycopg2.OperationalError("DB down at startup")
            return mock_conn

        def mock_sleep(_: float) -> None:
            raise _Stop()

        with patch.object(runner, "get_db_connection", side_effect=mock_get_db), \
             patch.object(runner, "leer_config_gaceta", return_value=cfg), \
             patch.object(runner, "dia_activo", return_value=True), \
             patch.object(runner, "en_ventana_horaria", return_value=True), \
             patch.object(runner, "lock_existe_y_activo", return_value=False), \
             patch.object(runner, "escribir_lock"), \
             patch.object(runner, "registrar_log_scripts"), \
             patch.object(runner, "limpiar_lock"), \
             patch.object(runner, "ejecutar_gaceta", return_value={
                 "estado": "ok", "returncode": 0, "duracion": 1.0, "mensaje_error": None,
             }), \
             patch("time.sleep", side_effect=mock_sleep):
            with pytest.raises(_Stop):
                runner.main()
        # Reaching here means runner survived DB failure at startup


class TestRunnerLeerConfig:
    """leer_config_gaceta reads the 'gaceta' row from config_scripts."""

    def test_returns_dict_when_row_exists(self) -> None:
        """Returns dict with the config_scripts row."""
        import runner
        row = {"script": "gaceta", "habilitado": True, "intervalo_minutos": 60}
        conn = _make_db_mock(fetchone_row=row)

        result = runner.leer_config_gaceta(conn)

        assert result == row
        assert result["script"] == "gaceta"

    def test_returns_none_when_no_row(self) -> None:
        """Returns None when config_scripts has no 'gaceta' row."""
        import runner
        conn = _make_db_mock(fetchone_row=None)

        result = runner.leer_config_gaceta(conn)

        assert result is None

    def test_returns_none_on_db_error(self) -> None:
        """Returns None and does not raise on DB error."""
        import runner
        conn = MagicMock()
        conn.cursor.side_effect = Exception("BD caída")

        result = runner.leer_config_gaceta(conn)

        assert result is None


class TestRunnerLeerUltimoCiclo:
    """leer_ultimo_ciclo_gaceta reads the most recent gaceta log_scripts row."""

    def test_returns_datetime_when_row_exists(self) -> None:
        """Returns the inicio datetime from the most recent row."""
        import runner
        inicio_dt = datetime(2026, 6, 14, 10, 0, 0)
        conn = _make_db_mock(fetchone_row={"inicio": inicio_dt})

        result = runner.leer_ultimo_ciclo_gaceta(conn)

        assert result == inicio_dt

    def test_returns_none_when_no_rows(self) -> None:
        """Returns None when log_scripts has no 'gaceta' rows."""
        import runner
        conn = _make_db_mock(fetchone_row=None)

        result = runner.leer_ultimo_ciclo_gaceta(conn)

        assert result is None

    def test_returns_none_on_db_error(self) -> None:
        """Returns None and does not raise on DB error."""
        import runner
        conn = MagicMock()
        conn.cursor.side_effect = psycopg2.OperationalError("BD caída")

        result = runner.leer_ultimo_ciclo_gaceta(conn)

        assert result is None


class TestRunnerEjecutarGaceta:
    """ejecutar_gaceta launches main.py --once as subprocess."""

    def test_exit_0_returns_ok(self) -> None:
        """Exit code 0 → estado 'ok'."""
        import runner
        cfg = _make_cfg()
        proc = MagicMock()
        proc.pid = 12345
        proc.returncode = 0
        proc.communicate.return_value = (b"", b"")

        with patch("subprocess.Popen", return_value=proc):
            result = runner.ejecutar_gaceta(cfg)

        assert result["estado"] == "ok"
        assert result["returncode"] == 0

    def test_nonzero_exit_returns_error(self) -> None:
        """Non-zero exit code → estado 'error'."""
        import runner
        cfg = _make_cfg()
        proc = MagicMock()
        proc.pid = 12345
        proc.returncode = 1
        proc.communicate.return_value = (b"", b"error msg")

        with patch("subprocess.Popen", return_value=proc):
            result = runner.ejecutar_gaceta(cfg)

        assert result["estado"] == "error"
        assert result["returncode"] == 1

    def test_command_calls_main_py_with_once(self) -> None:
        """Popen is called with main.py and --once flag."""
        import runner
        cfg = _make_cfg()
        proc = MagicMock()
        proc.pid = 12345
        proc.returncode = 0
        proc.communicate.return_value = (b"", b"")

        with patch("subprocess.Popen", return_value=proc) as mock_popen:
            runner.ejecutar_gaceta(cfg)

        args, kwargs = mock_popen.call_args
        cmd = args[0]
        assert "--once" in cmd
        assert any("main.py" in str(c) for c in cmd)


class TestDebeEjecutar:
    """debe_ejecutar pure-function unit tests — no DB, no subprocess."""

    def test_intervalo_cero_returns_false_fail_safe(self) -> None:
        """intervalo_min == 0 → False (fail-safe guard, not a valid config)."""
        from runner import debe_ejecutar
        ahora = datetime(2026, 6, 16, 10, 0, 0)
        assert debe_ejecutar(None, 0, ahora) is False

    def test_intervalo_negativo_returns_false_fail_safe(self) -> None:
        """intervalo_min < 0 → False (fail-safe guard)."""
        from runner import debe_ejecutar
        ahora = datetime(2026, 6, 16, 10, 0, 0)
        assert debe_ejecutar(None, -5, ahora) is False

    def test_no_prior_run_returns_true(self) -> None:
        """ultimo is None → True (first run ever, execute immediately)."""
        from runner import debe_ejecutar
        ahora = datetime(2026, 6, 16, 10, 0, 0)
        assert debe_ejecutar(None, 60, ahora) is True

    def test_elapsed_exceeds_interval_returns_true(self) -> None:
        """ahora - ultimo >= intervalo_min → True."""
        from runner import debe_ejecutar
        ahora = datetime(2026, 6, 16, 11, 0, 0)
        ultimo = datetime(2026, 6, 16, 9, 0, 0)  # 2 h ago, interval = 60 min
        assert debe_ejecutar(ultimo, 60, ahora) is True

    def test_elapsed_less_than_interval_returns_false(self) -> None:
        """ahora - ultimo < intervalo_min → False (too soon)."""
        from runner import debe_ejecutar
        ahora = datetime(2026, 6, 16, 10, 30, 0)
        ultimo = datetime(2026, 6, 16, 10, 0, 0)  # 30 min ago, interval = 60 min
        assert debe_ejecutar(ultimo, 60, ahora) is False

    def test_elapsed_exactly_at_interval_returns_true(self) -> None:
        """Exactly at the boundary (== intervalo_min) → True."""
        from runner import debe_ejecutar
        ahora = datetime(2026, 6, 16, 11, 0, 0)
        ultimo = datetime(2026, 6, 16, 10, 0, 0)  # exactly 60 min ago
        assert debe_ejecutar(ultimo, 60, ahora) is True


class TestEnVentanaHoraria:
    """en_ventana_horaria pure-function unit tests."""

    def test_no_window_configured_always_true(self) -> None:
        """hora_inicio=None or hora_fin=None → always True (no restriction)."""
        from runner import en_ventana_horaria
        cfg = {"hora_inicio": None, "hora_fin": None}
        assert en_ventana_horaria(cfg, datetime(2026, 6, 16, 3, 0, 0)) is True

    def test_inside_window_returns_true(self) -> None:
        """Current time within [hora_inicio, hora_fin] → True."""
        from runner import en_ventana_horaria
        from datetime import time
        cfg = {"hora_inicio": time(8, 0), "hora_fin": time(22, 0)}
        assert en_ventana_horaria(cfg, datetime(2026, 6, 16, 12, 0, 0)) is True

    def test_outside_window_before_start_returns_false(self) -> None:
        """Current time before hora_inicio → False."""
        from runner import en_ventana_horaria
        from datetime import time
        cfg = {"hora_inicio": time(8, 0), "hora_fin": time(22, 0)}
        assert en_ventana_horaria(cfg, datetime(2026, 6, 16, 3, 0, 0)) is False

    def test_outside_window_after_end_returns_false(self) -> None:
        """Current time after hora_fin → False."""
        from runner import en_ventana_horaria
        from datetime import time
        cfg = {"hora_inicio": time(8, 0), "hora_fin": time(22, 0)}
        assert en_ventana_horaria(cfg, datetime(2026, 6, 16, 23, 30, 0)) is False

    def test_midnight_crossing_window_returns_false_not_supported(self) -> None:
        """hora_inicio > hora_fin (midnight cross) → False (V1 does not support this)."""
        from runner import en_ventana_horaria
        from datetime import time
        cfg = {"hora_inicio": time(22, 0), "hora_fin": time(6, 0)}  # crosses midnight
        # Even at 23:00 which would logically be inside the window, V1 returns False
        assert en_ventana_horaria(cfg, datetime(2026, 6, 16, 23, 0, 0)) is False

    def test_timedelta_values_are_converted(self) -> None:
        """hora_inicio/hora_fin as timedelta (psycopg2 TIME) are converted to time."""
        from runner import en_ventana_horaria
        from datetime import time
        # psycopg2 returns TIME columns as timedelta
        cfg = {
            "hora_inicio": timedelta(hours=8),
            "hora_fin": timedelta(hours=22),
        }
        assert en_ventana_horaria(cfg, datetime(2026, 6, 16, 12, 0, 0)) is True


class TestDiaActivo:
    """dia_activo pure-function unit tests."""

    def test_no_dias_configured_always_true(self) -> None:
        """dias_semana=None → True (no restriction)."""
        from runner import dia_activo
        cfg = {"dias_semana": None}
        assert dia_activo(cfg, datetime(2026, 6, 16)) is True

    def test_empty_dias_returns_true(self) -> None:
        """dias_semana='' → True (empty = no restriction)."""
        from runner import dia_activo
        cfg = {"dias_semana": ""}
        assert dia_activo(cfg, datetime(2026, 6, 16)) is True

    def test_today_tuesday_in_weekday_list_returns_true(self) -> None:
        """2026-06-16 is Tuesday (isoweekday=2); '1,2,3,4,5' includes it → True."""
        from runner import dia_activo
        cfg = {"dias_semana": "1,2,3,4,5"}
        assert dia_activo(cfg, datetime(2026, 6, 16)) is True

    def test_today_tuesday_not_in_weekend_list_returns_false(self) -> None:
        """2026-06-16 is Tuesday (isoweekday=2); '6,7' does not include it → False."""
        from runner import dia_activo
        cfg = {"dias_semana": "6,7"}
        assert dia_activo(cfg, datetime(2026, 6, 16)) is False

    def test_invalid_dias_semana_string_returns_true_safe_fallback(self) -> None:
        """Non-numeric dias_semana → ValueError caught, safe fallback returns True."""
        from runner import dia_activo
        cfg = {"dias_semana": "lunes,martes"}
        assert dia_activo(cfg, datetime(2026, 6, 16)) is True
