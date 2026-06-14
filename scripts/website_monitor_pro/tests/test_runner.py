"""
Tests TDD para runner.py — Orquestador del scraper SIMO
========================================================

Ejecutar con:
    python -m pytest scripts/website_monitor_pro/tests/test_runner.py -v
"""

import os
import sys
from datetime import datetime, time, timedelta
from pathlib import Path
from unittest.mock import MagicMock, patch, call

import subprocess

import psycopg2
import pytest

# Aseguramos que runner sea importable desde cualquier CWD
sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

import runner  # noqa: E402


# ════════════════════════════════════════════════════════════════
# HELPERS
# ════════════════════════════════════════════════════════════════

def _make_cfg(
    hora_inicio=None,
    hora_fin=None,
    dias_semana="1,2,3,4,5,6,7",
    intervalo_minutos=120,
    habilitado=True,
    timeout_minutos=60,
) -> dict:
    """Crea un cfg dict con defaults sensatos."""
    return {
        "hora_inicio": hora_inicio,
        "hora_fin": hora_fin,
        "dias_semana": dias_semana,
        "intervalo_minutos": intervalo_minutos,
        "habilitado": habilitado,
        "timeout_minutos": timeout_minutos,
    }


def _make_db_mock(row: dict | None = None) -> MagicMock:
    """Crea un mock de psycopg2 connection + cursor con fetchone configurado."""
    conn = MagicMock()
    cursor = MagicMock()
    cursor.__enter__ = MagicMock(return_value=cursor)
    cursor.__exit__ = MagicMock(return_value=False)
    cursor.fetchone.return_value = row
    conn.cursor.return_value = cursor
    return conn


def _make_subprocess_mock(returncode: int = 0, stderr: bytes = b"") -> MagicMock:
    """Crea un mock de subprocess.Popen."""
    proc = MagicMock()
    proc.pid = 12345
    proc.returncode = returncode
    proc.communicate.return_value = (b"", stderr)
    return proc


# ════════════════════════════════════════════════════════════════
# FASE 1: Smoke test
# ════════════════════════════════════════════════════════════════

class TestSmoke:
    def test_import_runner_ok(self):
        """runner.py importa sin errores."""
        assert runner is not None

    def test_constantes_exportadas(self):
        """Las constantes base existen y tienen valores razonables."""
        assert isinstance(runner.LOCK_FILE, Path)
        assert isinstance(runner.SCRAPER_DIR, Path)
        assert isinstance(runner.SCRAPER_PYTHON, str)
        assert runner.LOOP_INTERVAL_DEFAULT > 0


# ════════════════════════════════════════════════════════════════
# FASE 2: en_ventana_horaria
# ════════════════════════════════════════════════════════════════

class TestEnVentanaHoraria:

    # 2.1/2.2 — NULL/NULL → True (sin restricción)
    def test_ambos_null_retorna_true(self):
        cfg = _make_cfg(hora_inicio=None, hora_fin=None)
        ahora = datetime(2024, 1, 15, 10, 0)  # lunes 10:00
        assert runner.en_ventana_horaria(cfg, ahora) is True

    # Solo hora_inicio null
    def test_hora_inicio_null_retorna_true(self):
        cfg = _make_cfg(hora_inicio=None, hora_fin=time(23, 0))
        ahora = datetime(2024, 1, 15, 10, 0)
        assert runner.en_ventana_horaria(cfg, ahora) is True

    # 2.3/2.4 — dentro de ventana → True
    def test_dentro_ventana_retorna_true(self):
        cfg = _make_cfg(hora_inicio=time(6, 0), hora_fin=time(23, 0))
        ahora = datetime(2024, 1, 15, 10, 0)  # 10:00 dentro de 06:00-23:00
        assert runner.en_ventana_horaria(cfg, ahora) is True

    # Exactamente en hora_inicio → True (borde izquierdo)
    def test_exactamente_hora_inicio_retorna_true(self):
        cfg = _make_cfg(hora_inicio=time(6, 0), hora_fin=time(23, 0))
        ahora = datetime(2024, 1, 15, 6, 0)
        assert runner.en_ventana_horaria(cfg, ahora) is True

    # 2.5/2.6 — fuera de ventana → False
    def test_fuera_ventana_retorna_false(self):
        cfg = _make_cfg(hora_inicio=time(6, 0), hora_fin=time(23, 0))
        ahora = datetime(2024, 1, 15, 23, 30)  # 23:30 fuera de 06:00-23:00
        assert runner.en_ventana_horaria(cfg, ahora) is False

    def test_antes_de_hora_inicio_retorna_false(self):
        cfg = _make_cfg(hora_inicio=time(8, 0), hora_fin=time(22, 0))
        ahora = datetime(2024, 1, 15, 5, 0)  # 05:00 antes de 08:00
        assert runner.en_ventana_horaria(cfg, ahora) is False

    # 2.7/2.8 — timedelta desde psycopg2 → normalizar y evaluar
    def test_timedelta_psycopg2_dentro_ventana(self):
        """psycopg2 puede retornar timedelta para columnas TIME."""
        cfg = _make_cfg(
            hora_inicio=timedelta(hours=6),  # equivale a time(6,0)
            hora_fin=timedelta(hours=23),    # equivale a time(23,0)
        )
        ahora = datetime(2024, 1, 15, 10, 0)
        assert runner.en_ventana_horaria(cfg, ahora) is True

    def test_timedelta_psycopg2_fuera_ventana(self):
        cfg = _make_cfg(
            hora_inicio=timedelta(hours=6),
            hora_fin=timedelta(hours=23),
        )
        ahora = datetime(2024, 1, 15, 23, 30)
        assert runner.en_ventana_horaria(cfg, ahora) is False

    # Ventana cruzando medianoche → False (V1 no soportado, SS-017)
    def test_ventana_cruza_medianoche_retorna_false(self):
        cfg = _make_cfg(hora_inicio=time(22, 0), hora_fin=time(6, 0))
        ahora = datetime(2024, 1, 15, 23, 0)
        assert runner.en_ventana_horaria(cfg, ahora) is False


# ════════════════════════════════════════════════════════════════
# FASE 2: dia_activo
# ════════════════════════════════════════════════════════════════

class TestDiaActivo:

    # 2.9/2.10 — lunes en lista "1,2,3,4,5" → True
    def test_lunes_en_lista_laboral_retorna_true(self):
        cfg = _make_cfg(dias_semana="1,2,3,4,5")
        ahora = datetime(2024, 1, 15)  # lunes, isoweekday()=1
        assert runner.dia_activo(cfg, ahora) is True

    def test_viernes_en_lista_retorna_true(self):
        cfg = _make_cfg(dias_semana="1,2,3,4,5")
        ahora = datetime(2024, 1, 19)  # viernes, isoweekday()=5
        assert runner.dia_activo(cfg, ahora) is True

    # 2.11/2.12 — domingo fuera de "1,2,3,4,5" → False
    def test_domingo_fuera_de_lista_retorna_false(self):
        cfg = _make_cfg(dias_semana="1,2,3,4,5")
        ahora = datetime(2024, 1, 21)  # domingo, isoweekday()=7
        assert runner.dia_activo(cfg, ahora) is False

    def test_sabado_fuera_de_lista_retorna_false(self):
        cfg = _make_cfg(dias_semana="1,2,3,4,5")
        ahora = datetime(2024, 1, 20)  # sábado, isoweekday()=6
        assert runner.dia_activo(cfg, ahora) is False

    # Todos los días activos → siempre True
    def test_todos_los_dias_activos(self):
        cfg = _make_cfg(dias_semana="1,2,3,4,5,6,7")
        for offset in range(7):
            ahora = datetime(2024, 1, 15 + offset)  # lunes a domingo
            assert runner.dia_activo(cfg, ahora) is True

    # dias_semana=None → True (sin restricción)
    def test_dias_semana_none_retorna_true(self):
        cfg = _make_cfg(dias_semana=None)
        ahora = datetime(2024, 1, 21)  # domingo
        assert runner.dia_activo(cfg, ahora) is True

    # dias_semana="" → True (sin restricción)
    def test_dias_semana_vacio_retorna_true(self):
        cfg = _make_cfg(dias_semana="")
        ahora = datetime(2024, 1, 21)
        assert runner.dia_activo(cfg, ahora) is True


# ════════════════════════════════════════════════════════════════
# FASE 2: debe_ejecutar
# ════════════════════════════════════════════════════════════════

class TestDebeEjecutar:

    # 2.13/2.14 — ultimo=None → True (primera ejecución)
    def test_ultimo_none_retorna_true(self):
        ahora = datetime(2024, 1, 15, 10, 0)
        assert runner.debe_ejecutar(None, 120, ahora) is True

    # 2.15/2.16 — intervalo cumplido → True
    def test_intervalo_cumplido_retorna_true(self):
        ahora = datetime(2024, 1, 15, 12, 1)
        ultimo = ahora.replace(hour=10, minute=0, second=0)  # hace 121 min
        assert runner.debe_ejecutar(ultimo, 120, ahora) is True

    # 2.17/2.18 — intervalo no cumplido → False
    def test_intervalo_no_cumplido_retorna_false(self):
        ahora = datetime(2024, 1, 15, 11, 0)
        ultimo = ahora.replace(hour=10, minute=0, second=0)  # hace 60 min
        assert runner.debe_ejecutar(ultimo, 120, ahora) is False

    # Exactamente en el límite → True (>= semántica)
    def test_exactamente_en_limite_retorna_true(self):
        from datetime import timedelta
        ahora = datetime(2024, 1, 15, 12, 0)
        ultimo = ahora - timedelta(minutes=120)  # exactamente 120 min
        assert runner.debe_ejecutar(ultimo, 120, ahora) is True

    # 2.19/2.20 — intervalo=0 → False (fail-safe SS-013)
    def test_intervalo_cero_retorna_false(self):
        ahora = datetime(2024, 1, 15, 10, 0)
        assert runner.debe_ejecutar(None, 0, ahora) is False

    def test_intervalo_negativo_retorna_false(self):
        ahora = datetime(2024, 1, 15, 10, 0)
        assert runner.debe_ejecutar(None, -5, ahora) is False


# ════════════════════════════════════════════════════════════════
# FASE 3: Lock file
# ════════════════════════════════════════════════════════════════

class TestLockFile:

    # 3.1/3.2 — archivo inexistente → False
    def test_lock_inexistente_retorna_false(self, tmp_path):
        lock = tmp_path / "runner.lock"
        assert not lock.exists()
        assert runner.lock_existe_y_activo(lock) is False

    # 3.3/3.4 — archivo con PID vivo → True
    def test_lock_pid_vivo_retorna_true(self, tmp_path, monkeypatch):
        lock = tmp_path / "runner.lock"
        lock.write_text("99999", encoding="utf-8")
        # os.kill sin excepción = PID vivo
        monkeypatch.setattr(os, "kill", lambda pid, sig: None)
        assert runner.lock_existe_y_activo(lock) is True

    # 3.5/3.6 — PID muerto (orphan) → False + archivo eliminado
    def test_lock_orphan_pid_muerto_retorna_false_y_limpia(self, tmp_path, monkeypatch):
        lock = tmp_path / "runner.lock"
        lock.write_text("99999", encoding="utf-8")
        # ProcessLookupError = PID muerto
        monkeypatch.setattr(os, "kill", lambda pid, sig: (_ for _ in ()).throw(ProcessLookupError()))
        result = runner.lock_existe_y_activo(lock)
        assert result is False
        assert not lock.exists(), "El archivo lock orphan debe haber sido eliminado"

    # 3.7/3.8 — escribir_lock crea archivo con PID actual
    def test_escribir_lock_crea_archivo_con_pid(self, tmp_path):
        lock = tmp_path / "runner.lock"
        runner.escribir_lock(lock)
        assert lock.exists()
        assert lock.read_text(encoding="utf-8").strip() == str(os.getpid())

    # 3.9/3.10 — limpiar_lock borra archivo
    def test_limpiar_lock_borra_archivo(self, tmp_path):
        lock = tmp_path / "runner.lock"
        lock.write_text("12345", encoding="utf-8")
        assert lock.exists()
        runner.limpiar_lock(lock)
        assert not lock.exists()

    def test_limpiar_lock_no_falla_si_no_existe(self, tmp_path):
        """limpiar_lock con archivo inexistente no lanza excepción."""
        lock = tmp_path / "runner.lock"
        assert not lock.exists()
        runner.limpiar_lock(lock)  # no debe lanzar


# ════════════════════════════════════════════════════════════════
# FASE 4: BD helpers
# ════════════════════════════════════════════════════════════════

class TestLeerConfigScraper:

    # 4.1/4.2 — fila presente → dict retornado
    def test_con_fila_retorna_dict(self):
        fila = {
            "id": 1,
            "script": "scraper",
            "habilitado": True,
            "intervalo_minutos": 120,
            "hora_inicio": time(6, 0),
            "hora_fin": time(23, 0),
            "dias_semana": "1,2,3,4,5",
            "timeout_minutos": 60,
        }
        conn = _make_db_mock(row=fila)
        resultado = runner.leer_config_scraper(conn)
        assert resultado == fila
        assert resultado["script"] == "scraper"

    # 4.3/4.4 — fetchone retorna None → función retorna None
    def test_sin_fila_retorna_none(self):
        conn = _make_db_mock(row=None)
        resultado = runner.leer_config_scraper(conn)
        assert resultado is None

    # 4.5/4.6 — excepción en cursor → retorna None sin crash
    def test_excepcion_retorna_none(self):
        conn = MagicMock()
        conn.cursor.side_effect = Exception("BD caída")
        resultado = runner.leer_config_scraper(conn)
        assert resultado is None


class TestGetDbConnection:

    # 4.7/4.8 — conexión exitosa en primer intento → retorna conn
    def test_conexion_exitosa_retorna_conn(self):
        mock_conn = MagicMock()
        with patch("psycopg2.connect", return_value=mock_conn) as mock_connect:
            resultado = runner.get_db_connection()
        assert resultado is mock_conn
        assert mock_connect.call_count == 1

    # 4.9/4.10 — OperationalError 3× → propaga; time.sleep llamado con 5, 15, 30
    def test_retry_backoff_propaga_tras_3_fallos(self):
        with patch("psycopg2.connect", side_effect=psycopg2.OperationalError("conn refused")), \
             patch("time.sleep") as mock_sleep:
            with pytest.raises(psycopg2.OperationalError):
                runner.get_db_connection()
            # Debe haber dormido con los 3 delays de backoff
            mock_sleep.assert_any_call(5)
            mock_sleep.assert_any_call(15)
            mock_sleep.assert_any_call(30)
            assert mock_sleep.call_count == 3

    def test_retry_exitoso_en_segundo_intento(self):
        """Falla 1 vez, luego conecta — time.sleep llamado solo 1 vez."""
        mock_conn = MagicMock()
        call_count = [0]

        def connect_side_effect(**kwargs):
            call_count[0] += 1
            if call_count[0] == 1:
                raise psycopg2.OperationalError("transient error")
            return mock_conn

        with patch("psycopg2.connect", side_effect=connect_side_effect), \
             patch("time.sleep") as mock_sleep:
            resultado = runner.get_db_connection()

        assert resultado is mock_conn
        assert mock_sleep.call_count == 1
        mock_sleep.assert_called_once_with(5)


# ════════════════════════════════════════════════════════════════
# FASE 5: ejecutar_scraper
# ════════════════════════════════════════════════════════════════

class TestEjecutarScraper:

    # 5.1/5.2 — exit 0 → estado "ok"
    def test_exit_0_retorna_estado_ok(self):
        cfg = _make_cfg(timeout_minutos=60)
        proc_mock = _make_subprocess_mock(returncode=0, stderr=b"")
        with patch("subprocess.Popen", return_value=proc_mock):
            resultado = runner.ejecutar_scraper(cfg)
        assert resultado["estado"] == "ok"
        assert resultado["returncode"] == 0
        assert resultado["mensaje_error"] is None
        assert resultado["duracion"] >= 0

    # 5.3/5.4 — returncode != 0 → estado "error" con stderr
    def test_returncode_nonzero_retorna_estado_error(self):
        cfg = _make_cfg(timeout_minutos=60)
        proc_mock = _make_subprocess_mock(returncode=1, stderr=b"error fatal en scraper")
        with patch("subprocess.Popen", return_value=proc_mock):
            resultado = runner.ejecutar_scraper(cfg)
        assert resultado["estado"] == "error"
        assert resultado["returncode"] == 1
        assert "error fatal en scraper" in resultado["mensaje_error"]

    # stderr largo → truncado a 300 chars
    def test_stderr_largo_truncado(self):
        cfg = _make_cfg(timeout_minutos=60)
        stderr_largo = b"E" * 500
        proc_mock = _make_subprocess_mock(returncode=2, stderr=stderr_largo)
        with patch("subprocess.Popen", return_value=proc_mock):
            resultado = runner.ejecutar_scraper(cfg)
        assert len(resultado["mensaje_error"]) <= 300

    # 5.5/5.6 — TimeoutExpired → estado "timeout" + terminate llamado
    def test_timeout_retorna_estado_timeout(self):
        cfg = _make_cfg(timeout_minutos=1)
        proc_mock = MagicMock()
        proc_mock.pid = 12345
        proc_mock.communicate.side_effect = subprocess.TimeoutExpired(cmd="cmd", timeout=60)
        proc_mock.wait.return_value = None  # termina en wait(10)
        with patch("subprocess.Popen", return_value=proc_mock):
            resultado = runner.ejecutar_scraper(cfg)
        assert resultado["estado"] == "timeout"
        assert resultado["returncode"] == -1
        proc_mock.terminate.assert_called_once()

    def test_timeout_kill_si_wait_tambien_falla(self):
        """Si wait(10) también falla → kill() llamado."""
        cfg = _make_cfg(timeout_minutos=1)
        proc_mock = MagicMock()
        proc_mock.pid = 12345
        proc_mock.communicate.side_effect = subprocess.TimeoutExpired(cmd="cmd", timeout=60)
        proc_mock.wait.side_effect = subprocess.TimeoutExpired(cmd="cmd", timeout=10)
        with patch("subprocess.Popen", return_value=proc_mock):
            resultado = runner.ejecutar_scraper(cfg)
        assert resultado["estado"] == "timeout"
        proc_mock.kill.assert_called_once()

    # 5.7/5.8 — comando correcto verificado
    def test_comando_correcto(self):
        """Verifica que Popen recibe el comando y cwd exactos del diseño."""
        cfg = _make_cfg(timeout_minutos=60)
        proc_mock = _make_subprocess_mock(returncode=0)
        with patch("subprocess.Popen", return_value=proc_mock) as mock_popen:
            runner.ejecutar_scraper(cfg)
        args, kwargs = mock_popen.call_args
        cmd = args[0]
        assert cmd[0] == runner.SCRAPER_PYTHON
        assert cmd[1] == str(runner.SCRAPER_DIR / "main.py")
        assert "--once" in cmd
        assert "--pais" in cmd
        assert "todos" in cmd
        assert kwargs.get("cwd") == str(runner.SCRAPER_DIR)
        assert kwargs.get("stdout") == subprocess.DEVNULL
        assert kwargs.get("stderr") == subprocess.PIPE


# ════════════════════════════════════════════════════════════════
# FASE 6: registrar_log_scripts
# ════════════════════════════════════════════════════════════════

class TestRegistrarLogScripts:

    def _make_cursor_mock(self):
        conn = MagicMock()
        cursor = MagicMock()
        cursor.__enter__ = MagicMock(return_value=cursor)
        cursor.__exit__ = MagicMock(return_value=False)
        conn.cursor.return_value = cursor
        return conn, cursor

    # 6.1/6.2 — INSERT correcto con todos los campos
    def test_insert_correcto(self):
        conn, cursor = self._make_cursor_mock()
        inicio = datetime(2024, 1, 15, 10, 0, 0)
        fin = datetime(2024, 1, 15, 10, 1, 30)  # 90 segundos después

        runner.registrar_log_scripts(
            conn, "scraper", inicio, fin, "ok",
            items_procesados=0, items_resultado=0, errores=0, mensaje_error=None,
        )

        cursor.execute.assert_called_once()
        call_args = cursor.execute.call_args
        sql = call_args[0][0]
        params = call_args[0][1]

        # Verificar que la SQL tiene INSERT (comparar case-insensitive)
        assert "INSERT INTO LOG_SCRIPTS" in sql.upper().replace("\n", " ")
        # Verificar parámetros
        assert params[0] == "scraper"   # script
        assert params[1] == inicio      # inicio
        assert params[2] == fin         # fin
        assert params[3] == "ok"        # estado
        assert params[4] == 90.0        # duracion_segundos

    # 6.3/6.4 — duración calculada correctamente
    def test_duracion_calculada(self):
        conn, cursor = self._make_cursor_mock()
        inicio = datetime(2024, 1, 15, 10, 0, 0)
        fin = datetime(2024, 1, 15, 10, 1, 30)  # 90 segundos

        runner.registrar_log_scripts(conn, "scraper", inicio, fin, "ok")

        call_args = cursor.execute.call_args
        params = call_args[0][1]
        duracion = params[4]  # duracion_segundos es el 5to parámetro
        assert duracion == 90.0

    def test_duracion_fraccionaria(self):
        """Duracion con segundos fraccionarios."""
        conn, cursor = self._make_cursor_mock()
        inicio = datetime(2024, 1, 15, 10, 0, 0, 0)
        fin = datetime(2024, 1, 15, 10, 0, 45, 500000)  # 45.5 segundos

        runner.registrar_log_scripts(conn, "scraper", inicio, fin, "error")

        call_args = cursor.execute.call_args
        params = call_args[0][1]
        assert params[4] == 45.5

    def test_excepcion_no_propaga(self):
        """Error en INSERT → no lanza excepción al caller."""
        conn = MagicMock()
        conn.cursor.side_effect = Exception("BD unavailable")
        inicio = datetime(2024, 1, 15, 10, 0, 0)
        fin = datetime(2024, 1, 15, 10, 1, 0)
        # No debe lanzar
        runner.registrar_log_scripts(conn, "scraper", inicio, fin, "error")


# ════════════════════════════════════════════════════════════════
# FASE 7: main() smoke + flow
# ════════════════════════════════════════════════════════════════

class TestMainSmoke:

    # 7.2 — main es callable
    def test_main_es_callable(self):
        assert callable(runner.main)

    def test_main_tiene_docstring(self):
        assert runner.main.__doc__ is not None


class TestMainFlow:

    # 7.3 — flow de 1 iteración mockeada
    def test_main_flow_una_iteracion(self):
        """
        Mock todos los helpers. Loop ejecuta 1 iteración completa y verifica
        que registrar_log_scripts fue llamado con los argumentos correctos.
        Usa una excepción custom (no KeyboardInterrupt) para romper el loop
        sin interferir con pytest.
        """
        class _StopLoop(BaseException):
            """Hereda de BaseException para no ser capturada por `except Exception` en main()."""
            pass

        cfg = _make_cfg(
            hora_inicio=None,
            hora_fin=None,
            dias_semana="1,2,3,4,5,6,7",
            intervalo_minutos=120,
            habilitado=True,
            timeout_minutos=60,
        )
        resultado_scraper = {
            "estado": "ok",
            "returncode": 0,
            "duracion": 5.0,
            "mensaje_error": None,
        }

        mock_conn = MagicMock()

        # Romper el loop en el primer sleep (después de ejecutar 1 iteración completa)
        sleep_call_count = [0]

        def mock_sleep(seconds):
            sleep_call_count[0] += 1
            if sleep_call_count[0] >= 1:
                raise _StopLoop("stop after 1 iteration")

        with patch.object(runner, "get_db_connection", return_value=mock_conn), \
             patch.object(runner, "leer_config_scraper", return_value=cfg), \
             patch.object(runner, "lock_existe_y_activo", return_value=False), \
             patch.object(runner, "escribir_lock"), \
             patch.object(runner, "ejecutar_scraper", return_value=resultado_scraper) as mock_ejecutar, \
             patch.object(runner, "registrar_log_scripts") as mock_registrar, \
             patch.object(runner, "limpiar_lock"), \
             patch("time.sleep", side_effect=mock_sleep), \
             patch.object(runner, "debe_ejecutar", return_value=True), \
             patch.object(runner, "dia_activo", return_value=True), \
             patch.object(runner, "en_ventana_horaria", return_value=True):
            # _StopLoop es capturado por el except Exception en main()
            # lo que detiene el loop después de 1 iteración
            with pytest.raises(_StopLoop):
                runner.main()

        # Verificar que el scraper fue ejecutado
        mock_ejecutar.assert_called_once_with(cfg)
        # Verificar que se registró el log
        mock_registrar.assert_called_once()
        call_obj = mock_registrar.call_args
        # conn siempre es el primer argumento posicional
        assert call_obj.args[0] is mock_conn
        # script, estado pueden ser posicionales o kwargs según la firma
        all_args = list(call_obj.args) + [call_obj.kwargs.get("script"), call_obj.kwargs.get("estado")]
        # verificar que "scraper" y "ok" están en algún lugar de los argumentos
        assert "scraper" in call_obj.args or call_obj.kwargs.get("script") == "scraper"
        assert "ok" in call_obj.args or call_obj.kwargs.get("estado") == "ok"

    def test_main_omite_ciclo_si_deshabilitado(self):
        """Si cfg.habilitado=False, no se llama ejecutar_scraper."""

        class _Stop(BaseException):
            pass

        cfg = _make_cfg(habilitado=False)
        mock_conn = MagicMock()
        sleep_calls = [0]

        def mock_sleep(seconds):
            sleep_calls[0] += 1
            if sleep_calls[0] >= 1:
                raise _Stop()

        with patch.object(runner, "get_db_connection", return_value=mock_conn), \
             patch.object(runner, "leer_config_scraper", return_value=cfg), \
             patch.object(runner, "ejecutar_scraper") as mock_ejecutar, \
             patch("time.sleep", side_effect=mock_sleep):
            with pytest.raises(_Stop):
                runner.main()

        mock_ejecutar.assert_not_called()

    def test_main_omite_ciclo_si_lock_activo(self):
        """Si lock está activo, no se llama ejecutar_scraper."""

        class _Stop(BaseException):
            pass

        cfg = _make_cfg()
        mock_conn = MagicMock()
        sleep_calls = [0]

        def mock_sleep(seconds):
            sleep_calls[0] += 1
            if sleep_calls[0] >= 1:
                raise _Stop()

        with patch.object(runner, "get_db_connection", return_value=mock_conn), \
             patch.object(runner, "leer_config_scraper", return_value=cfg), \
             patch.object(runner, "lock_existe_y_activo", return_value=True), \
             patch.object(runner, "debe_ejecutar", return_value=True), \
             patch.object(runner, "dia_activo", return_value=True), \
             patch.object(runner, "en_ventana_horaria", return_value=True), \
             patch.object(runner, "ejecutar_scraper") as mock_ejecutar, \
             patch("time.sleep", side_effect=mock_sleep):
            with pytest.raises(_Stop):
                runner.main()

        mock_ejecutar.assert_not_called()


# ════════════════════════════════════════════════════════════════
# FASE 8: leer_ultimo_ciclo
# ════════════════════════════════════════════════════════════════

class TestLeerUltimoCiclo:

    def test_returns_most_recent_inicio(self):
        """leer_ultimo_ciclo returns the inicio datetime from the most recent row."""
        inicio_dt = datetime(2026, 6, 14, 10, 0, 0)
        conn = _make_db_mock(row={"inicio": inicio_dt})
        resultado = runner.leer_ultimo_ciclo(conn)
        assert resultado == inicio_dt

    def test_returns_none_when_no_rows(self):
        """leer_ultimo_ciclo returns None when log_scripts has no scraper rows."""
        conn = _make_db_mock(row=None)
        resultado = runner.leer_ultimo_ciclo(conn)
        assert resultado is None

    def test_returns_none_on_db_exception(self):
        """leer_ultimo_ciclo returns None and does not raise on DB error."""
        conn = MagicMock()
        conn.cursor.side_effect = psycopg2.OperationalError("BD caída")
        resultado = runner.leer_ultimo_ciclo(conn)
        assert resultado is None


# ════════════════════════════════════════════════════════════════
# FASE 9: startup seed en main()
# ════════════════════════════════════════════════════════════════

class TestStartupSeed:

    def test_seed_skips_run_when_recent_cycle_in_db(self):
        """If seed returns a recent run, debe_ejecutar → False; scraper not called."""

        class _Stop(BaseException):
            pass

        seed_time = datetime.now() - timedelta(minutes=30)
        cfg = _make_cfg(intervalo_minutos=120, habilitado=True)
        mock_conn = MagicMock()

        def mock_sleep(_: float) -> None:
            raise _Stop()

        with patch.object(runner, "get_db_connection", return_value=mock_conn), \
             patch.object(runner, "leer_config_scraper", return_value=cfg), \
             patch.object(runner, "leer_ultimo_ciclo", return_value=seed_time), \
             patch.object(runner, "dia_activo", return_value=True), \
             patch.object(runner, "en_ventana_horaria", return_value=True), \
             patch.object(runner, "ejecutar_scraper") as mock_ejecutar, \
             patch("time.sleep", side_effect=mock_sleep):
            with pytest.raises(_Stop):
                runner.main()

        mock_ejecutar.assert_not_called()

    def test_seed_runs_immediately_when_no_history(self):
        """If seed returns None, debe_ejecutar → True; scraper is called."""

        class _Stop(BaseException):
            pass

        cfg = _make_cfg(intervalo_minutos=120, habilitado=True)
        mock_conn = MagicMock()
        resultado_scraper = {
            "estado": "ok",
            "returncode": 0,
            "duracion": 1.0,
            "mensaje_error": None,
        }

        def mock_sleep(_: float) -> None:
            raise _Stop()

        with patch.object(runner, "get_db_connection", return_value=mock_conn), \
             patch.object(runner, "leer_config_scraper", return_value=cfg), \
             patch.object(runner, "leer_ultimo_ciclo", return_value=None), \
             patch.object(runner, "dia_activo", return_value=True), \
             patch.object(runner, "en_ventana_horaria", return_value=True), \
             patch.object(runner, "lock_existe_y_activo", return_value=False), \
             patch.object(runner, "escribir_lock"), \
             patch.object(runner, "registrar_log_scripts"), \
             patch.object(runner, "limpiar_lock"), \
             patch.object(runner, "ejecutar_scraper", return_value=resultado_scraper) as mock_ejecutar, \
             patch("time.sleep", side_effect=mock_sleep):
            with pytest.raises(_Stop):
                runner.main()

        mock_ejecutar.assert_called_once()

    def test_db_down_at_startup_falls_back_to_none(self):
        """If DB raises at seed time, runner sets ultimo_ciclo=None and does not crash."""

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
             patch.object(runner, "leer_config_scraper", return_value=cfg), \
             patch.object(runner, "dia_activo", return_value=True), \
             patch.object(runner, "en_ventana_horaria", return_value=True), \
             patch.object(runner, "lock_existe_y_activo", return_value=False), \
             patch.object(runner, "escribir_lock"), \
             patch.object(runner, "registrar_log_scripts"), \
             patch.object(runner, "limpiar_lock"), \
             patch.object(runner, "ejecutar_scraper", return_value={
                 "estado": "ok", "returncode": 0, "duracion": 1.0, "mensaje_error": None,
             }), \
             patch("time.sleep", side_effect=mock_sleep):
            with pytest.raises(_Stop):
                runner.main()

        # Reaching _Stop means no crash — runner survived DB being down at startup
