"""
Tests for main.py — Strict TDD RED written first.

run_cycle() must:
1. Get the cursor from the DB (MAX gaceta_id_externo for pais)
2. Fetch pages from the gaceta website (via client)
3. Parse each page with the Bolivia parser
4. Stop fetching when all items are ≤ cursor (incremental) OR max_pages reached
5. For each NEW norma:
   a. Upsert norma in DB
   b. Run extractor on sumario
   c. Insert eventos if extracted
   d. Update estado_extraccion on the norma
6. Write a log_scripts row with the run result
7. Returns a RunResult with counts

All HTTP and DB calls are MOCKED.
"""
from datetime import date
from pathlib import Path
from unittest.mock import MagicMock, patch, call

import pytest

# Reuse the HTML fixture from parser tests
FIXTURE_HTML = (
    Path(__file__).parent / "fixtures" / "bolivia_listadonor_page1.html"
).read_text(encoding="utf-8")


def _make_mock_conn(cursor_value=None, upsert_id=42):
    """Build a psycopg2 mock connection wired for main.py tests."""
    conn = MagicMock()
    cursor = MagicMock()
    cursor.__enter__ = MagicMock(return_value=cursor)
    cursor.__exit__ = MagicMock(return_value=False)
    cursor.fetchone.return_value = (cursor_value,) if cursor_value is not None else (None,)
    conn.cursor.return_value = cursor
    return conn


class TestRunCycleImport:
    """run_cycle is importable and callable."""

    def test_run_cycle_is_importable(self) -> None:
        """main.run_cycle exists."""
        from main import run_cycle
        assert callable(run_cycle)


class TestRunCycleStoresNormaAndEventos:
    """Full cycle: parser finds new norma, extractor runs, eventos stored."""

    def test_full_cycle_stores_norma_and_eventos(self) -> None:
        """
        When a new Decreto Presidencial with a designa sumario is found,
        run_cycle upserts the norma AND inserts the extracted evento.
        """
        from main import run_cycle

        mock_conn = MagicMock()

        # Cursor returns None (no prior normas) so all fixture rows are "new"
        mock_repo = MagicMock()
        mock_repo.get_ultimo_gaceta_id.return_value = None
        mock_repo.upsert_norma.return_value = 42  # new norma id
        mock_repo.insert_eventos.return_value = 1
        mock_repo.update_estado_extraccion.return_value = None

        # Client returns the fixture HTML
        mock_client = MagicMock()
        mock_client.get.return_value = FIXTURE_HTML

        with patch("main.GacetaRepository", return_value=mock_repo), \
             patch("main.GacetaClient", return_value=mock_client):
            result = run_cycle(conn=mock_conn, pais="BO", max_pages=1)

        # upsert_norma called for each Decreto Presidencial row (3 in fixture)
        assert mock_repo.upsert_norma.call_count == 3

        # insert_eventos called for rows with extractable appointments
        # Fixture has 2 extractable rows (180125 + 180123); 180120 is bulk → requiere_detalle
        assert mock_repo.insert_eventos.call_count >= 1

    def test_cycle_skips_known_normas(self) -> None:
        """
        Normas with gaceta_id_externo <= cursor are skipped (not upserted).
        """
        from main import run_cycle

        mock_conn = MagicMock()
        mock_repo = MagicMock()
        # Cursor at 180124 → fixture rows 180120, 180123 are known; only 180125 is new
        mock_repo.get_ultimo_gaceta_id.return_value = 180124
        mock_repo.upsert_norma.return_value = 42

        mock_client = MagicMock()
        mock_client.get.return_value = FIXTURE_HTML

        with patch("main.GacetaRepository", return_value=mock_repo), \
             patch("main.GacetaClient", return_value=mock_client):
            result = run_cycle(conn=mock_conn, pais="BO", max_pages=1)

        # Only 1 new norma (180125 > 180124)
        assert mock_repo.upsert_norma.call_count == 1

    def test_cycle_returns_run_result_with_counts(self) -> None:
        """run_cycle returns a result with normas_nuevas and eventos_insertados counts."""
        from main import run_cycle, RunResult

        mock_conn = MagicMock()
        mock_repo = MagicMock()
        mock_repo.get_ultimo_gaceta_id.return_value = None
        mock_repo.upsert_norma.return_value = 42
        mock_repo.insert_eventos.return_value = 1
        mock_repo.update_estado_extraccion.return_value = None

        mock_client = MagicMock()
        mock_client.get.return_value = FIXTURE_HTML

        with patch("main.GacetaRepository", return_value=mock_repo), \
             patch("main.GacetaClient", return_value=mock_client):
            result = run_cycle(conn=mock_conn, pais="BO", max_pages=1)

        assert isinstance(result, RunResult)
        assert result.normas_nuevas >= 0
        assert result.eventos_insertados >= 0

    def test_cycle_handles_duplicate_norma_gracefully(self) -> None:
        """
        When upsert_norma returns None (duplicate), insert_eventos is NOT called
        for that norma.
        """
        from main import run_cycle

        mock_conn = MagicMock()
        mock_repo = MagicMock()
        mock_repo.get_ultimo_gaceta_id.return_value = None
        mock_repo.upsert_norma.return_value = None  # all duplicates
        mock_repo.insert_eventos.return_value = 0

        mock_client = MagicMock()
        mock_client.get.return_value = FIXTURE_HTML

        with patch("main.GacetaRepository", return_value=mock_repo), \
             patch("main.GacetaClient", return_value=mock_client):
            result = run_cycle(conn=mock_conn, pais="BO", max_pages=1)

        # insert_eventos should NOT be called when upsert returns None
        mock_repo.insert_eventos.assert_not_called()


class TestRunCycleLogScripts:
    """run_cycle writes a log_scripts row."""

    def test_cycle_writes_log_scripts_row(self) -> None:
        """run_cycle calls conn.cursor().execute() for a log_scripts INSERT."""
        from main import run_cycle

        mock_conn = MagicMock()
        mock_repo = MagicMock()
        mock_repo.get_ultimo_gaceta_id.return_value = None
        mock_repo.upsert_norma.return_value = None

        mock_client = MagicMock()
        mock_client.get.return_value = FIXTURE_HTML

        with patch("main.GacetaRepository", return_value=mock_repo), \
             patch("main.GacetaClient", return_value=mock_client), \
             patch("main._log_run") as mock_log:
            run_cycle(conn=mock_conn, pais="BO", max_pages=1)

        mock_log.assert_called_once()


class TestRunCycleErrorBranch:
    """run_cycle handles exceptions gracefully: logs error, _log_run still called."""

    def test_client_error_sets_estado_error(self) -> None:
        """When client.get() raises, result.estado='error' and _log_run is still called."""
        from main import run_cycle

        mock_conn = MagicMock()
        mock_repo = MagicMock()
        mock_repo.get_ultimo_gaceta_id.return_value = None

        mock_client = MagicMock()
        mock_client.get.side_effect = ConnectionError("Network unreachable")

        with patch("main.GacetaRepository", return_value=mock_repo), \
             patch("main.GacetaClient", return_value=mock_client), \
             patch("main._log_run") as mock_log:
            result = run_cycle(conn=mock_conn, pais="BO", max_pages=1)

        assert result.estado == "error"
        assert result.mensaje_error is not None
        assert "Network unreachable" in result.mensaje_error
        mock_log.assert_called_once()

    def test_insert_eventos_failure_triggers_rollback_not_partial_commit(self) -> None:
        """
        FIX B — atomicity: when insert_eventos raises mid-norma, conn.rollback() is
        called and no partial writes are committed.  The cycle reflects the error.

        This test FAILS before FIX B because run_cycle has no explicit transaction
        wrapping — there is no conn.rollback() call anywhere in the pre-fix code.
        """
        from main import run_cycle

        mock_conn = MagicMock()
        mock_conn.autocommit = True  # matches what main() sets

        mock_repo = MagicMock()
        mock_repo.get_ultimo_gaceta_id.return_value = None
        mock_repo.upsert_norma.return_value = 42  # norma inserted OK
        mock_repo.insert_eventos.side_effect = Exception("DB write failed mid-norma")

        mock_client = MagicMock()
        mock_client.get.return_value = FIXTURE_HTML

        with patch("main.GacetaRepository", return_value=mock_repo), \
             patch("main.GacetaClient", return_value=mock_client), \
             patch("main._log_run"):  # suppress log_run so commit not called there
            result = run_cycle(conn=mock_conn, pais="BO", max_pages=1)

        # Atomicity evidence: rollback must have been called (no partial norma persisted)
        mock_conn.rollback.assert_called()
        # commit must NOT be called (no partial writes committed; _log_run is patched out)
        mock_conn.commit.assert_not_called()
        # The cycle surfaces the error (existing outer-catch behavior preserved)
        assert result.estado == "error"
        assert "mid-norma" in (result.mensaje_error or "")

    def test_partial_failure_still_logs(self) -> None:
        """Repo error mid-cycle: result.estado='error', _log_run called with error result."""
        from main import run_cycle

        mock_conn = MagicMock()
        mock_repo = MagicMock()
        mock_repo.get_ultimo_gaceta_id.return_value = None
        mock_repo.upsert_norma.side_effect = Exception("DB write failed")

        mock_client = MagicMock()
        mock_client.get.return_value = FIXTURE_HTML

        with patch("main.GacetaRepository", return_value=mock_repo), \
             patch("main.GacetaClient", return_value=mock_client), \
             patch("main._log_run") as mock_log:
            result = run_cycle(conn=mock_conn, pais="BO", max_pages=1)

        assert result.estado == "error"
        mock_log.assert_called_once()


class TestRunCycleMultiPage:
    """run_cycle fetches multiple pages and respects cross-page reached_known."""

    def test_multi_page_fetches_all_pages_when_no_cursor(self) -> None:
        """With max_pages=2 and no cursor, client.get called twice; paginas_procesadas=2."""
        from main import run_cycle

        mock_conn = MagicMock()
        mock_repo = MagicMock()
        mock_repo.get_ultimo_gaceta_id.return_value = None
        mock_repo.upsert_norma.return_value = 42
        mock_repo.insert_eventos.return_value = 1
        mock_repo.update_estado_extraccion.return_value = None

        mock_client = MagicMock()
        mock_client.get.return_value = FIXTURE_HTML

        with patch("main.GacetaRepository", return_value=mock_repo), \
             patch("main.GacetaClient", return_value=mock_client):
            result = run_cycle(conn=mock_conn, pais="BO", max_pages=2)

        assert mock_client.get.call_count == 2
        assert result.paginas_procesadas == 2

    def test_cross_page_reached_known_stops_before_next_page(self) -> None:
        """Cursor=180124: row 180125 is new, row 180123 is known → page 2 never fetched."""
        from main import run_cycle

        mock_conn = MagicMock()
        mock_repo = MagicMock()
        # Cursor set so that only 180125 is new in the fixture
        mock_repo.get_ultimo_gaceta_id.return_value = 180124
        mock_repo.upsert_norma.return_value = 42
        mock_repo.insert_eventos.return_value = 1
        mock_repo.update_estado_extraccion.return_value = None

        mock_client = MagicMock()
        mock_client.get.return_value = FIXTURE_HTML

        with patch("main.GacetaRepository", return_value=mock_repo), \
             patch("main.GacetaClient", return_value=mock_client):
            result = run_cycle(conn=mock_conn, pais="BO", max_pages=2)

        # reached_known on page 1 → page 2 never fetched
        assert mock_client.get.call_count == 1
        # Only 180125 upserted (180123 <= 180124 triggers break)
        assert mock_repo.upsert_norma.call_count == 1


class TestRunCycleExactCounts:
    """Exact counts from the fixture: 3 normas, 2 with events, 1 bulk → requiere_detalle."""

    def test_full_cycle_exact_upsert_and_event_counts(self) -> None:
        """Fixture: 3 Decreto Presidencial rows; 2 extractable, 1 bulk → exact counts."""
        from main import run_cycle, RunResult

        mock_conn = MagicMock()
        mock_repo = MagicMock()
        mock_repo.get_ultimo_gaceta_id.return_value = None
        mock_repo.upsert_norma.return_value = 42
        mock_repo.insert_eventos.return_value = 1
        mock_repo.update_estado_extraccion.return_value = None

        mock_client = MagicMock()
        mock_client.get.return_value = FIXTURE_HTML

        with patch("main.GacetaRepository", return_value=mock_repo), \
             patch("main.GacetaClient", return_value=mock_client):
            result = run_cycle(conn=mock_conn, pais="BO", max_pages=1)

        # 3 Decreto Presidencial rows in fixture (180125, 180123, 180120)
        assert mock_repo.upsert_norma.call_count == 3
        # 2 extractable rows (180125 + 180123); 180120 bulk → requiere_detalle, no eventos
        assert mock_repo.insert_eventos.call_count == 2
        # update_estado_extraccion called for all 3 normas
        assert mock_repo.update_estado_extraccion.call_count == 3
        # Exact result counts
        assert isinstance(result, RunResult)
        assert result.normas_nuevas == 3
        assert result.eventos_insertados == 2
        assert result.paginas_procesadas == 1
        assert result.estado == "ok"
