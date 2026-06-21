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

NOTE on FIXTURE_HTML: test_main.py uses a standalone inline card-based mock HTML
(FIXTURE_HTML below) instead of the real parser fixture.  The real fixture
(bolivia_listadonor_page1.html) is the parser's responsibility; coupling test_main
to it creates brittle count assertions.  The inline mock provides controlled
IDs, sumarios, and counts that are stable across fixture updates.

IDs used (descending, as per real site ordering):
  180125  Decreto Presidencial  — extractable sumario (1 event)
  180124  Decreto Supremo       — filtered out by parser
  180123  Decreto Presidencial  — extractable sumario (1 event, interino)
  180120  Decreto Presidencial  — bulk sumario → requiere_detalle (0 events)
"""
from datetime import date, datetime
from pathlib import Path
from unittest.mock import MagicMock, patch, call

import pytest

# Inline card-based mock HTML (matches the real Drupal 10 Bootstrap card structure
# that the new BoliviaParser expects).  Uses controlled IDs and sumarios so that
# test_main.py assertions stay stable regardless of what the real parser fixture
# contains.
FIXTURE_HTML = """<html><body>
<div class="row"><div class="col-12 m-2">
  <div class="card h-100 p-2 fondo-paper">
    <div class="card-body">
      <p class="card-text texto-default">
        Publicado en edición: <strong><a href="/edicions/view/3500NEC">3500NEC</a></strong>
        | Fecha de Publicación: 2026-06-14
      </p>
      <h6><b>Decreto Presidencial N° 549</b></h6>
      <div class="contentpaneopen">
        <p>Designa a la ciudadana MARIA JOSE GARCIA LUNA como Ministra de Educacion.</p>
      </div>
    </div>
    <div class="card-footer bg-transparent text-end" style="border: none;">
      <a href="/normas/verGratis_gob/180125">Ver Norma</a> |
      <a href="/normas/verGratis_gob1/180125" target="_blank">Descargar Word</a> |
      <a href="/normas/descargarNrms/180125">Descargar PDF</a>
    </div>
  </div>
</div></div>
<div class="row"><div class="col-12 m-2">
  <div class="card h-100 p-2 fondo-paper">
    <div class="card-body">
      <p class="card-text texto-default">
        Publicado en edición: <strong><a href="/edicions/view/3500NEC">3500NEC</a></strong>
        | Fecha de Publicación: 2026-06-14
      </p>
      <h6><b>Decreto Supremo N° 548</b></h6>
      <div class="contentpaneopen">
        <p>Aprueba el Presupuesto General del Estado 2027.</p>
      </div>
    </div>
    <div class="card-footer bg-transparent text-end" style="border: none;">
      <a href="/normas/verGratis_gob/180124">Ver Norma</a> |
      <a href="/normas/verGratis_gob1/180124" target="_blank">Descargar Word</a> |
      <a href="/normas/descargarNrms/180124">Descargar PDF</a>
    </div>
  </div>
</div></div>
<div class="row"><div class="col-12 m-2">
  <div class="card h-100 p-2 fondo-paper">
    <div class="card-body">
      <p class="card-text texto-default">
        Publicado en edición: <strong><a href="/edicions/view/3499NEC">3499NEC</a></strong>
        | Fecha de Publicación: 2026-06-12
      </p>
      <h6><b>Decreto Presidencial N° 547</b></h6>
      <div class="contentpaneopen">
        <p>Designa al ciudadano JUAN CARLOS MAMANI QUISPE como INTERINO Viceministro de Energias Renovables de la Agencia Nacional de Hidrocarburos.</p>
      </div>
    </div>
    <div class="card-footer bg-transparent text-end" style="border: none;">
      <a href="/normas/verGratis_gob/180123">Ver Norma</a> |
      <a href="/normas/verGratis_gob1/180123" target="_blank">Descargar Word</a> |
      <a href="/normas/descargarNrms/180123">Descargar PDF</a>
    </div>
  </div>
</div></div>
<div class="row"><div class="col-12 m-2">
  <div class="card h-100 p-2 fondo-paper">
    <div class="card-body">
      <p class="card-text texto-default">
        Publicado en edición: <strong><a href="/edicions/view/3498NEC">3498NEC</a></strong>
        | Fecha de Publicación: 2026-06-10
      </p>
      <h6><b>Decreto Presidencial N° 546</b></h6>
      <div class="contentpaneopen">
        <p>Designacion del Alto Mando Militar.</p>
      </div>
    </div>
    <div class="card-footer bg-transparent text-end" style="border: none;">
      <a href="/normas/verGratis_gob/180120">Ver Norma</a> |
      <a href="/normas/verGratis_gob1/180120" target="_blank">Descargar Word</a> |
      <a href="/normas/descargarNrms/180120">Descargar PDF</a>
    </div>
  </div>
</div></div>
</body></html>"""


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
        # ITEM 1: autocommit must be restored to its pre-call value after rollback
        assert mock_conn.autocommit == True  # noqa: E712
        # ITEM 3: normas_nuevas must NOT be incremented when commit never happens
        assert result.normas_nuevas == 0

    def test_duplicate_norma_restores_autocommit(self) -> None:
        """
        ITEM 1: When upsert_norma returns None (duplicate-skip path), autocommit
        must be restored to its pre-call value.  Guards against a regression where
        conn.autocommit stays False after the rollback+continue branch.
        """
        from main import run_cycle

        mock_conn = MagicMock()
        mock_conn.autocommit = True  # matches what main() sets

        mock_repo = MagicMock()
        mock_repo.get_ultimo_gaceta_id.return_value = None
        mock_repo.upsert_norma.return_value = None  # all duplicates → skip path

        mock_client = MagicMock()
        mock_client.get.return_value = FIXTURE_HTML

        with patch("main.GacetaRepository", return_value=mock_repo), \
             patch("main.GacetaClient", return_value=mock_client), \
             patch("main._log_run"):
            run_cycle(conn=mock_conn, pais="BO", max_pages=1)

        # autocommit must be back to the value it had before run_cycle touched it
        assert mock_conn.autocommit == True  # noqa: E712

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


# ── Backfill-specific fixtures ────────────────────────────────────────────────
# Two fixture pages for backfill cutoff tests.  All normas are Decreto
# Presidencial (the only type processed by the Bolivia driver).
# Dates are RFC-like strings that the real BoliviaParser emits as date objects.

# Page 1: two DPs with dates ABOVE cutoff 2026-06-11 → both should be upserted
_BACKFILL_PAGE_ABOVE = """<html><body>
<div class="row"><div class="col-12 m-2">
  <div class="card h-100 p-2 fondo-paper">
    <div class="card-body">
      <p class="card-text texto-default">
        Publicado en edición: <strong><a href="/edicions/view/3500NEC">3500NEC</a></strong>
        | Fecha de Publicación: 2026-06-14
      </p>
      <h6><b>Decreto Presidencial N° 549</b></h6>
      <div class="contentpaneopen">
        <p>Designa a la ciudadana MARIA JOSE GARCIA LUNA como Ministra de Educacion.</p>
      </div>
    </div>
    <div class="card-footer bg-transparent text-end" style="border: none;">
      <a href="/normas/verGratis_gob/180125">Ver Norma</a> |
      <a href="/normas/descargarNrms/180125">Descargar PDF</a>
    </div>
  </div>
</div></div>
<div class="row"><div class="col-12 m-2">
  <div class="card h-100 p-2 fondo-paper">
    <div class="card-body">
      <p class="card-text texto-default">
        Publicado en edición: <strong><a href="/edicions/view/3499NEC">3499NEC</a></strong>
        | Fecha de Publicación: 2026-06-12
      </p>
      <h6><b>Decreto Presidencial N° 547</b></h6>
      <div class="contentpaneopen">
        <p>Designa al ciudadano JUAN CARLOS MAMANI QUISPE como Viceministro de Energias Renovables.</p>
      </div>
    </div>
    <div class="card-footer bg-transparent text-end" style="border: none;">
      <a href="/normas/verGratis_gob/180123">Ver Norma</a> |
      <a href="/normas/descargarNrms/180123">Descargar PDF</a>
    </div>
  </div>
</div></div>
</body></html>"""

# Page 2: first DP with date BELOW cutoff 2026-06-11 → should stop here
_BACKFILL_PAGE_BELOW = """<html><body>
<div class="row"><div class="col-12 m-2">
  <div class="card h-100 p-2 fondo-paper">
    <div class="card-body">
      <p class="card-text texto-default">
        Publicado en edición: <strong><a href="/edicions/view/3498NEC">3498NEC</a></strong>
        | Fecha de Publicación: 2026-06-09
      </p>
      <h6><b>Decreto Presidencial N° 546</b></h6>
      <div class="contentpaneopen">
        <p>Designa al ciudadano PEDRO CONDORI QUISPE como Director General de Infraestructura.</p>
      </div>
    </div>
    <div class="card-footer bg-transparent text-end" style="border: none;">
      <a href="/normas/verGratis_gob/180120">Ver Norma</a> |
      <a href="/normas/descargarNrms/180120">Descargar PDF</a>
    </div>
  </div>
</div></div>
</body></html>"""


class TestComputeBackfillCutoff:
    """_compute_backfill_cutoff is a pure function: today - N years."""

    def test_subtracts_exact_years(self) -> None:
        """5 years back from 2026-06-20 → 2021-06-20."""
        from main import _compute_backfill_cutoff
        result = _compute_backfill_cutoff(date(2026, 6, 20), years=5)
        assert result == date(2021, 6, 20)

    def test_different_year_count(self) -> None:
        """3 years back from 2026-06-20 → 2023-06-20 (triangulation)."""
        from main import _compute_backfill_cutoff
        result = _compute_backfill_cutoff(date(2026, 6, 20), years=3)
        assert result == date(2023, 6, 20)

    def test_leap_year_feb_29_handled_gracefully(self) -> None:
        """Feb 29 in a leap year minus 5 years → Feb 28 (non-leap year)."""
        from main import _compute_backfill_cutoff
        # 2024 is a leap year; 2019 is not
        result = _compute_backfill_cutoff(date(2024, 2, 29), years=5)
        assert result.year == 2019
        assert result.month == 2
        # Feb 28 is the nearest valid date in non-leap year
        assert result.day == 28


class TestBackfillMode:
    """run_cycle(backfill=True) — historical backfill behavior."""

    def test_backfill_ignores_cursor_processes_all_normas(self) -> None:
        """
        In backfill mode, normas below the incremental cursor are NOT skipped.
        With cursor=180124, forward mode would stop at 180123.
        Backfill must continue and process all 3 Decreto Presidencial normas.
        """
        from main import run_cycle
        from config.settings import Settings, GacetaConfig, DatabaseConfig

        mock_conn = MagicMock()
        mock_repo = MagicMock()
        # Cursor at 180124 — in forward mode this would skip 180123 and 180120
        mock_repo.get_ultimo_gaceta_id.return_value = 180124
        mock_repo.upsert_norma.return_value = 42
        mock_repo.insert_eventos.return_value = 1
        mock_repo.update_estado_extraccion.return_value = None

        mock_client = MagicMock()
        mock_client.get.return_value = FIXTURE_HTML

        # 1-page backfill cap so we only fetch one page; desde_fecha far enough
        # in the past that no date-based cutoff triggers within the fixture.
        settings = Settings(db=DatabaseConfig(), gaceta=GacetaConfig(backfill_max_pages=1))

        with patch("main.GacetaRepository", return_value=mock_repo), \
             patch("main.GacetaClient", return_value=mock_client):
            result = run_cycle(
                conn=mock_conn,
                pais="BO",
                backfill=True,
                desde_fecha=date(2020, 1, 1),
                settings=settings,
            )

        # All 3 Decreto Presidencial normas processed (cursor ignored)
        assert mock_repo.upsert_norma.call_count == 3

    def test_backfill_stops_when_fecha_crosses_cutoff(self) -> None:
        """
        Backfill stops pagination when norma.fecha_publicacion < desde_fecha.
        Page 1 (dates 2026-06-14, 2026-06-12) is above cutoff 2026-06-11.
        Page 2 first norma (2026-06-09) is below cutoff → stop, do not upsert.
        """
        from main import run_cycle

        mock_conn = MagicMock()
        mock_repo = MagicMock()
        mock_repo.get_ultimo_gaceta_id.return_value = None
        mock_repo.upsert_norma.return_value = 42
        mock_repo.insert_eventos.return_value = 1
        mock_repo.update_estado_extraccion.return_value = None

        mock_client = MagicMock()
        # Page 1: above cutoff; Page 2: below cutoff
        mock_client.get.side_effect = [_BACKFILL_PAGE_ABOVE, _BACKFILL_PAGE_BELOW]

        cutoff = date(2026, 6, 11)
        with patch("main.GacetaRepository", return_value=mock_repo), \
             patch("main.GacetaClient", return_value=mock_client):
            result = run_cycle(
                conn=mock_conn,
                pais="BO",
                backfill=True,
                desde_fecha=cutoff,
            )

        # Both pages fetched (cutoff seen on page 2)
        assert mock_client.get.call_count == 2
        # Only page 1's 2 normas upserted (page 2 first norma triggers cutoff stop)
        assert mock_repo.upsert_norma.call_count == 2

    def test_backfill_respects_backfill_max_pages_cap(self) -> None:
        """
        Backfill uses settings.gaceta.backfill_max_pages as the page cap.
        With backfill_max_pages=2 and 3 available pages, only 2 pages are fetched.
        """
        from main import run_cycle
        from config.settings import Settings, GacetaConfig, DatabaseConfig

        mock_conn = MagicMock()
        mock_repo = MagicMock()
        mock_repo.get_ultimo_gaceta_id.return_value = None
        mock_repo.upsert_norma.return_value = 42
        mock_repo.insert_eventos.return_value = 1
        mock_repo.update_estado_extraccion.return_value = None

        mock_client = MagicMock()
        mock_client.get.return_value = FIXTURE_HTML

        # Settings with backfill_max_pages=2
        settings = Settings(
            db=DatabaseConfig(),
            gaceta=GacetaConfig(backfill_max_pages=2),
        )

        with patch("main.GacetaRepository", return_value=mock_repo), \
             patch("main.GacetaClient", return_value=mock_client):
            run_cycle(
                conn=mock_conn,
                pais="BO",
                backfill=True,
                desde_fecha=date(2020, 1, 1),
                settings=settings,
            )

        # Safety cap: only 2 pages fetched even though content is available
        assert mock_client.get.call_count == 2

    def test_backfill_passes_auto_aprobar_true_to_insert_eventos(self) -> None:
        """
        In backfill mode, insert_eventos must be called with auto_aprobar=True
        so events are stored as 'aprobado' (not 'pendiente').
        """
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
            run_cycle(
                conn=mock_conn,
                pais="BO",
                max_pages=1,
                backfill=True,
                desde_fecha=date(2020, 1, 1),
            )

        # At least one insert_eventos call with auto_aprobar=True
        assert mock_repo.insert_eventos.called
        for call_args in mock_repo.insert_eventos.call_args_list:
            assert call_args.kwargs.get("auto_aprobar") is True, (
                f"Expected auto_aprobar=True in backfill, got {call_args}"
            )

    def test_forward_mode_cursor_stop_regression(self) -> None:
        """
        Regression: forward mode (backfill=False) still stops at the cursor.
        Cursor=180124 → only 180125 is new; 180123 and 180120 are skipped.
        """
        from main import run_cycle

        mock_conn = MagicMock()
        mock_repo = MagicMock()
        mock_repo.get_ultimo_gaceta_id.return_value = 180124
        mock_repo.upsert_norma.return_value = 42
        mock_repo.insert_eventos.return_value = 1
        mock_repo.update_estado_extraccion.return_value = None

        mock_client = MagicMock()
        mock_client.get.return_value = FIXTURE_HTML

        with patch("main.GacetaRepository", return_value=mock_repo), \
             patch("main.GacetaClient", return_value=mock_client):
            result = run_cycle(conn=mock_conn, pais="BO", max_pages=1, backfill=False)

        # Only 1 new norma (180125 > 180124); cursor stop triggers on 180123
        assert mock_repo.upsert_norma.call_count == 1

    def test_forward_mode_eventos_stay_pendiente_regression(self) -> None:
        """
        Regression: forward mode events are NOT auto-approved.
        insert_eventos must be called WITHOUT auto_aprobar=True (default False).
        """
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
            run_cycle(conn=mock_conn, pais="BO", max_pages=1, backfill=False)

        # In forward mode, auto_aprobar must be False (default) — not True
        for call_args in mock_repo.insert_eventos.call_args_list:
            auto_aprobar = call_args.kwargs.get("auto_aprobar", False)
            assert auto_aprobar is False, (
                f"Forward mode must not auto-approve events, got auto_aprobar={auto_aprobar}"
            )


class TestRunCycleUniqueViolation:
    """
    WARNING #2 — numero_decreto unique constraint collision must be a SKIP, not a crash.

    gaceta_normas has TWO unique constraints:
      1. (pais, gaceta_id_externo) — handled by ON CONFLICT DO NOTHING in upsert_norma
      2. (pais, numero_decreto)    — NOT handled by ON CONFLICT; raises UniqueViolation
                                     on the second INSERT path

    A UniqueViolation on constraint #2 must be treated as a duplicate-skip:
    roll back that norma's transaction, log it, and CONTINUE to the next norma.
    The cycle must end with estado='ok', not 'error'.

    Non-unique exceptions (e.g. programming errors, connection drops) must STILL
    abort the cycle (estado='error').

    RED: these tests FAIL before the fix because the inner except clause re-raises
    ALL exceptions unconditionally, which bubbles into the outer error handler
    and sets estado='error'.
    """

    # FIXTURE_HTML (imported from module scope) has 3 DP normas: 180125, 180123, 180120.
    # We simulate UniqueViolation on the FIRST upsert (180125) so the remaining
    # two (180123, 180120) are still processed — proving the cycle continues.

    def test_unique_violation_on_upsert_is_skipped_not_error(self) -> None:
        """
        UniqueViolation from upsert_norma (numero_decreto collision) → skip that
        norma and continue.  Cycle ends estado='ok', not 'error'.

        RED: fails because inner except re-raises all exceptions, bubbling to the
        outer handler which sets estado='error'.
        """
        import psycopg2.errors
        from main import run_cycle

        mock_conn = MagicMock()
        mock_conn.autocommit = True

        mock_repo = MagicMock()
        mock_repo.get_ultimo_gaceta_id.return_value = None
        # First upsert raises UniqueViolation; remaining return a new id
        mock_repo.upsert_norma.side_effect = [
            psycopg2.errors.UniqueViolation("duplicate key value violates unique constraint"),
            42,
            43,
        ]
        mock_repo.insert_eventos.return_value = 1
        mock_repo.update_estado_extraccion.return_value = None

        mock_client = MagicMock()
        mock_client.get.return_value = FIXTURE_HTML

        with patch("main.GacetaRepository", return_value=mock_repo), \
             patch("main.GacetaClient", return_value=mock_client), \
             patch("main._log_run"):
            result = run_cycle(conn=mock_conn, pais="BO", max_pages=1)

        # Cycle must end successfully — UniqueViolation is a skip, not a crash
        assert result.estado == "ok", (
            f"Expected estado='ok' after UniqueViolation skip, got {result.estado!r}"
        )
        # The dup norma (id 0) is rolled back; the other 2 are processed
        assert result.normas_nuevas == 2, (
            f"Expected 2 normas_nuevas (skipped 1 dup), got {result.normas_nuevas}"
        )
        # rollback called once (for the UniqueViolation norma)
        mock_conn.rollback.assert_called()

    def test_unique_violation_dup_norma_eventos_not_inserted(self) -> None:
        """
        When upsert_norma raises UniqueViolation, insert_eventos must NOT be called
        for that norma (the transaction is rolled back, no partial writes).

        Triangulation: proves the rollback guard covers eventos too.
        """
        import psycopg2.errors
        from main import run_cycle

        mock_conn = MagicMock()
        mock_conn.autocommit = True

        mock_repo = MagicMock()
        mock_repo.get_ultimo_gaceta_id.return_value = None
        # Only one norma; upsert raises UniqueViolation
        mock_repo.upsert_norma.side_effect = psycopg2.errors.UniqueViolation(
            "duplicate key value"
        )

        mock_client = MagicMock()
        mock_client.get.return_value = FIXTURE_HTML

        with patch("main.GacetaRepository", return_value=mock_repo), \
             patch("main.GacetaClient", return_value=mock_client), \
             patch("main._log_run"):
            result = run_cycle(conn=mock_conn, pais="BO", max_pages=1)

        # insert_eventos must NOT be called for any of the dup normas
        mock_repo.insert_eventos.assert_not_called()
        # Cycle is still ok (all 3 normas skipped as dup → normas_nuevas=0 but no error)
        assert result.estado == "ok"
        assert result.normas_nuevas == 0

    def test_unique_violation_restores_autocommit(self) -> None:
        """
        After a UniqueViolation skip, autocommit must be restored to its pre-call
        value (True).  Guards against leaving conn.autocommit=False permanently.
        """
        import psycopg2.errors
        from main import run_cycle

        mock_conn = MagicMock()
        mock_conn.autocommit = True

        mock_repo = MagicMock()
        mock_repo.get_ultimo_gaceta_id.return_value = None
        mock_repo.upsert_norma.side_effect = psycopg2.errors.UniqueViolation(
            "duplicate key value"
        )

        mock_client = MagicMock()
        mock_client.get.return_value = FIXTURE_HTML

        with patch("main.GacetaRepository", return_value=mock_repo), \
             patch("main.GacetaClient", return_value=mock_client), \
             patch("main._log_run"):
            run_cycle(conn=mock_conn, pais="BO", max_pages=1)

        assert mock_conn.autocommit == True, (  # noqa: E712
            f"Expected autocommit=True after UniqueViolation skip, got {mock_conn.autocommit!r}"
        )

    def test_non_unique_exception_still_aborts_cycle(self) -> None:
        """
        Regression: a non-UniqueViolation exception (e.g. OperationalError, programming
        error) still aborts the cycle with estado='error'.
        Only UniqueViolation is swallowed; all other DB errors must propagate.
        """
        import psycopg2
        from main import run_cycle

        mock_conn = MagicMock()
        mock_conn.autocommit = True

        mock_repo = MagicMock()
        mock_repo.get_ultimo_gaceta_id.return_value = None
        mock_repo.upsert_norma.side_effect = psycopg2.OperationalError("connection reset")

        mock_client = MagicMock()
        mock_client.get.return_value = FIXTURE_HTML

        with patch("main.GacetaRepository", return_value=mock_repo), \
             patch("main.GacetaClient", return_value=mock_client), \
             patch("main._log_run"):
            result = run_cycle(conn=mock_conn, pais="BO", max_pages=1)

        # Non-unique error must still set estado='error'
        assert result.estado == "error", (
            f"Expected estado='error' for OperationalError, got {result.estado!r}"
        )
        assert "connection reset" in (result.mensaje_error or "")
