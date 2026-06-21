"""
Tests for core/database.py — Strict TDD RED written first.

GacetaRepository must:
1. get_ultimo_gaceta_id(pais) → MAX(gaceta_id_externo) or None
2. upsert_norma(pais, norma_dict) → new row ID (int) or None if duplicate
3. insert_eventos(norma_id, pais, eventos) → count of inserted rows
4. update_estado_extraccion(norma_id, estado) → None (side effect only)

psycopg2 connection is MOCKED — no real database calls.
"""
from datetime import date
from unittest.mock import MagicMock, call, patch

import pytest


def _make_conn(fetchone_return=None, fetchall_return=None):
    """Create a mock psycopg2 connection + cursor."""
    conn = MagicMock()
    cursor = MagicMock()
    cursor.__enter__ = MagicMock(return_value=cursor)
    cursor.__exit__ = MagicMock(return_value=False)
    cursor.fetchone.return_value = fetchone_return
    cursor.fetchall.return_value = fetchall_return or []
    conn.cursor.return_value = cursor
    return conn, cursor


def _sample_norma() -> dict:
    return {
        "pais": "BO",
        "gaceta_id_externo": 180125,
        "numero_decreto": "0549/2026",
        "tipo_norma": "Decreto Presidencial",
        "sumario": "Designa a la ciudadana GARCIA LUNA como Ministra.",
        "pdf_url": "/archivos/pdf/180125.pdf",
        "fecha_publicacion": date(2026, 6, 14),
        "edicion": "3500",
        "estado_extraccion": "pendiente",
    }


def _sample_evento() -> dict:
    return {
        "persona_nombre": "MARIA JOSE GARCIA LUNA",
        "persona_nombre_normalizado": "maria jose garcia luna",
        "cargo": "Ministra de Educación",
        "cargo_categoria": None,
        "entidad": None,
        "tipo_evento": "designacion",
        "interino": False,
        "estado_revision": "pendiente",
    }


class TestGetUltimoGacetaId:
    """get_ultimo_gaceta_id returns the MAX gaceta_id_externo or None."""

    def test_returns_max_id_when_rows_exist(self) -> None:
        """When rows exist, returns the integer MAX value."""
        from core.database import GacetaRepository
        conn, cursor = _make_conn(fetchone_return=(180100,))
        repo = GacetaRepository(conn)

        result = repo.get_ultimo_gaceta_id("BO")

        assert result == 180100
        assert isinstance(result, int)

    def test_returns_none_when_no_rows(self) -> None:
        """When the table is empty, MAX() returns None."""
        from core.database import GacetaRepository
        conn, cursor = _make_conn(fetchone_return=(None,))
        repo = GacetaRepository(conn)

        result = repo.get_ultimo_gaceta_id("BO")

        assert result is None

    def test_queries_with_pais_filter(self) -> None:
        """Query includes pais = %s to scope by country."""
        from core.database import GacetaRepository
        conn, cursor = _make_conn(fetchone_return=(180100,))
        repo = GacetaRepository(conn)

        repo.get_ultimo_gaceta_id("BO")

        cursor.execute.assert_called_once()
        sql, params = cursor.execute.call_args[0]
        assert "pais" in sql.lower()
        assert "BO" in params


class TestUpsertNorma:
    """upsert_norma inserts and returns ID for new normas; None for duplicates."""

    def test_returns_new_id_on_insert(self) -> None:
        """On successful insert, returns the new row ID."""
        from core.database import GacetaRepository
        conn, cursor = _make_conn(fetchone_return=(42,))
        repo = GacetaRepository(conn)

        result = repo.upsert_norma(_sample_norma())

        assert result == 42

    def test_returns_none_on_conflict(self) -> None:
        """ON CONFLICT DO NOTHING returns no row → function returns None."""
        from core.database import GacetaRepository
        conn, cursor = _make_conn(fetchone_return=None)
        repo = GacetaRepository(conn)

        result = repo.upsert_norma(_sample_norma())

        assert result is None

    def test_upsert_sql_has_on_conflict_do_nothing(self) -> None:
        """INSERT SQL includes ON CONFLICT (pais, gaceta_id_externo) DO NOTHING."""
        from core.database import GacetaRepository
        conn, cursor = _make_conn(fetchone_return=(1,))
        repo = GacetaRepository(conn)

        repo.upsert_norma(_sample_norma())

        sql = cursor.execute.call_args[0][0]
        assert "ON CONFLICT" in sql.upper()
        assert "DO NOTHING" in sql.upper()

    def test_upsert_includes_returning_id(self) -> None:
        """INSERT SQL includes RETURNING id to detect new vs duplicate."""
        from core.database import GacetaRepository
        conn, cursor = _make_conn(fetchone_return=(1,))
        repo = GacetaRepository(conn)

        repo.upsert_norma(_sample_norma())

        sql = cursor.execute.call_args[0][0]
        assert "RETURNING" in sql.upper()


class TestInsertEventos:
    """insert_eventos inserts eventos and returns inserted count."""

    def test_insert_single_evento_returns_1(self) -> None:
        """Inserting 1 evento returns count=1 (loop iterations, not rowcount)."""
        from core.database import GacetaRepository
        conn, cursor = _make_conn()
        repo = GacetaRepository(conn)

        count = repo.insert_eventos(norma_id=42, pais="BO", eventos=[_sample_evento()])

        assert count == 1
        # execute called exactly once — implementation counts loop iterations, not rowcount
        assert cursor.execute.call_count == 1

    def test_insert_two_eventos_returns_2(self) -> None:
        """Inserting 2 eventos returns count=2 (one execute call per evento)."""
        from core.database import GacetaRepository
        conn, cursor = _make_conn()
        repo = GacetaRepository(conn)

        count = repo.insert_eventos(
            norma_id=42,
            pais="BO",
            eventos=[_sample_evento(), _sample_evento()],
        )

        assert count == 2
        # execute called once per evento — loop count drives the return value
        assert cursor.execute.call_count == 2

    def test_insert_empty_list_returns_0(self) -> None:
        """Empty eventos list → 0 inserts, no DB calls."""
        from core.database import GacetaRepository
        conn, cursor = _make_conn()
        repo = GacetaRepository(conn)

        count = repo.insert_eventos(norma_id=42, pais="BO", eventos=[])

        assert count == 0
        cursor.execute.assert_not_called()

    def test_evento_pais_copied_from_parameter(self) -> None:
        """pais column in inserted evento comes from the pais parameter (denormalized)."""
        from core.database import GacetaRepository
        conn, cursor = _make_conn()
        repo = GacetaRepository(conn)

        repo.insert_eventos(norma_id=42, pais="BO", eventos=[_sample_evento()])

        # Verify the pais 'BO' appears in the params passed to execute
        _sql, params = cursor.execute.call_args[0]
        assert "BO" in params


class TestInsertEventosAutoAprobar:
    """insert_eventos with auto_aprobar=True sets backfill approval fields."""

    def test_auto_aprobar_sets_estado_aprobado(self) -> None:
        """When auto_aprobar=True, estado_revision='aprobado' is passed to DB."""
        from core.database import GacetaRepository
        conn, cursor = _make_conn()
        repo = GacetaRepository(conn)

        repo.insert_eventos(norma_id=42, pais="BO", eventos=[_sample_evento()], auto_aprobar=True)

        _sql, params = cursor.execute.call_args[0]
        assert "aprobado" in params

    def test_auto_aprobar_sets_revisado_por_none(self) -> None:
        """
        When auto_aprobar=True, revisado_por=None is passed (NULL in DB).
        NULL is the marker that distinguishes auto-approved backfill from
        human-approved events (human approvals set revisado_por to a user id).
        """
        from core.database import GacetaRepository
        from datetime import datetime
        conn, cursor = _make_conn()
        repo = GacetaRepository(conn)

        repo.insert_eventos(norma_id=42, pais="BO", eventos=[_sample_evento()], auto_aprobar=True)

        _sql, params = cursor.execute.call_args[0]
        # revisado_por must be None (NULL) even in backfill — marker for auto-approval
        # revisado_at must be a datetime (not None)
        revisado_at_values = [p for p in params if isinstance(p, datetime)]
        revisado_por_candidates = [p for p in params if p is None]
        assert len(revisado_at_values) >= 1, "Expected at least one datetime (revisado_at) in params"
        assert len(revisado_por_candidates) >= 1, "Expected revisado_por=None in params"

    def test_auto_aprobar_sets_revisado_at_to_datetime(self) -> None:
        """When auto_aprobar=True, revisado_at is a datetime (not None)."""
        from core.database import GacetaRepository
        from datetime import datetime
        conn, cursor = _make_conn()
        repo = GacetaRepository(conn)

        repo.insert_eventos(norma_id=42, pais="BO", eventos=[_sample_evento()], auto_aprobar=True)

        _sql, params = cursor.execute.call_args[0]
        datetime_values = [p for p in params if isinstance(p, datetime)]
        assert len(datetime_values) >= 1

    def test_forward_mode_default_keeps_pendiente(self) -> None:
        """
        Regression: without auto_aprobar (default False), estado_revision stays 'pendiente'.
        Forward collection must not be affected by the backfill param.
        """
        from core.database import GacetaRepository
        conn, cursor = _make_conn()
        repo = GacetaRepository(conn)

        repo.insert_eventos(norma_id=42, pais="BO", eventos=[_sample_evento()])

        _sql, params = cursor.execute.call_args[0]
        assert "pendiente" in params
        assert "aprobado" not in params


class TestUpdateEstadoExtraccion:
    """update_estado_extraccion updates the norma record in place."""

    def test_update_sends_correct_estado(self) -> None:
        """UPDATE SQL receives the new estado and the norma_id."""
        from core.database import GacetaRepository
        conn, cursor = _make_conn()
        repo = GacetaRepository(conn)

        repo.update_estado_extraccion(norma_id=42, estado="procesado")

        cursor.execute.assert_called_once()
        sql, params = cursor.execute.call_args[0]
        assert "UPDATE" in sql.upper()
        assert "estado_extraccion" in sql.lower()
        assert "procesado" in params
        assert 42 in params

    def test_update_does_not_raise_on_success(self) -> None:
        """update_estado_extraccion completes without raising."""
        from core.database import GacetaRepository
        conn, cursor = _make_conn()
        repo = GacetaRepository(conn)

        # Should not raise
        repo.update_estado_extraccion(norma_id=1, estado="requiere_detalle")
