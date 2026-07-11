"""
TDD Phase 3.A — processed_pairs: (url, categoria) migration.

Tests verify that:
1. get_processed_url_categoria_pairs() returns (url, categoria) tuples, NOT (url, keyword)
2. Same (url, categoria) with different keywords → only 1 entry in the set
3. Rows with NULL categoria are handled (included as (url, None))
4. The SQL query selects url+categoria, not url+keyword

These tests mock the DB layer fully — no real connection required.
All heavy transitive deps (selenium, psycopg2, etc.) are stubbed.
"""

import sys
from contextlib import contextmanager
from typing import Optional, Set, Tuple
from unittest.mock import MagicMock, patch

import pytest


# ── Load core/database.py under ISOLATED stubs ────────────────────────────────
# database.py pulls in heavy/optional deps (psycopg2, selenium, spacy) plus
# config.settings and utils.logger. We stub them so it loads without a real
# DB/browser/env — but ONLY for the duration of the load, via patch.dict.
#
# This isolation is critical: leaving the MagicMock stubs in sys.modules leaks
# them into the global import state and breaks OTHER test modules that need the
# real spacy/config/utils (a test-isolation bug that only surfaces when the whole
# suite runs together — e.g. in CI). patch.dict restores sys.modules on exit;
# database.py keeps the mock objects it captured in its own namespace.

def _make_settings_mock() -> MagicMock:
    m = MagicMock()
    m.db.db_type = "postgres"
    m.db.pool_size = 2
    m.db.host = "localhost"
    m.db.port = 5432
    m.db.user = "u"
    m.db.password = "p"
    m.db.database = "testdb"
    m.db.connection_timeout = 5
    m.db.pool_name = "pool"
    return m


def _load_database_module_isolated():
    """Load core/database.py with heavy deps stubbed, without leaking the stubs."""
    import importlib.util
    import os

    _cfg_stub = MagicMock()
    _cfg_stub.settings = _make_settings_mock()
    _utils_stub = MagicMock()
    _utils_stub.logger.get_logger = lambda name: MagicMock()

    stubs = {
        "psycopg2": MagicMock(),
        "psycopg2.extras": MagicMock(),
        "psycopg2.pool": MagicMock(),
        "mysql": MagicMock(),
        "mysql.connector": MagicMock(),
        "mysql.connector.pooling": MagicMock(),
        "selenium": MagicMock(),
        "selenium.webdriver": MagicMock(),
        "selenium.webdriver.common": MagicMock(),
        "selenium.webdriver.common.by": MagicMock(),
        "selenium.webdriver.support": MagicMock(),
        "selenium.webdriver.support.ui": MagicMock(),
        "selenium.webdriver.support.expected_conditions": MagicMock(),
        "selenium.webdriver.remote": MagicMock(),
        "selenium.webdriver.remote.webdriver": MagicMock(),
        "selenium.common": MagicMock(),
        "selenium.common.exceptions": MagicMock(),
        "webdriver_manager": MagicMock(),
        "webdriver_manager.chrome": MagicMock(),
        "spacy": MagicMock(),
        "config": _cfg_stub,
        "config.settings": _cfg_stub,
        "utils": _utils_stub,
        "utils.logger": _utils_stub.logger,
    }

    db_path = os.path.join(os.path.dirname(__file__), "..", "core", "database.py")
    with patch.dict(sys.modules, stubs):
        spec = importlib.util.spec_from_file_location("core._database_pairs_isolated", db_path)
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
    return module


_db_module = _load_database_module_isolated()
ScrapingRepository = _db_module.ScrapingRepository
DatabaseManager = _db_module.DatabaseManager


# ── Cursor/context mock helper ────────────────────────────────────────────────

def _patch_cursor_rows(rows: list):
    """Return (patcher, cursor_mock) that makes get_cursor yield rows."""
    cursor_mock = MagicMock()
    cursor_mock.fetchall.return_value = rows

    @contextmanager
    def fake_get_cursor(**kwargs):
        yield cursor_mock

    patcher = patch.object(DatabaseManager, "get_cursor", side_effect=fake_get_cursor)
    return patcher, cursor_mock


# ── Tests ─────────────────────────────────────────────────────────────────────


class TestProcessedUrlCategoriaPairs:
    """get_processed_url_categoria_pairs() must return (url, categoria) tuples."""

    def test_returns_url_categoria_tuples_not_url_keyword(self) -> None:
        """
        RED → GREEN: DB returns rows with url+categoria.
        Result set must contain (url, categoria) pairs — NOT (url, keyword).
        """
        rows = [
            ("https://example.com/article-1", "PEP-designacion"),
            ("https://example.com/article-2", "OPI"),
        ]
        patcher, _ = _patch_cursor_rows(rows)
        with patcher:
            result: Set[Tuple[str, Optional[str]]] = (
                ScrapingRepository.get_processed_url_categoria_pairs()
            )

        assert ("https://example.com/article-1", "PEP-designacion") in result
        assert ("https://example.com/article-2", "OPI") in result
        assert len(result) == 2

    def test_same_url_categoria_different_keywords_yields_one_entry(self) -> None:
        """
        DB has same (url, categoria) — SELECT DISTINCT url, categoria returns one row.
        The set must have exactly 1 entry.
        """
        # DB returns only one row because SELECT DISTINCT url, categoria collapses keywords
        rows = [
            ("https://example.com/art", "PEP-designacion"),
        ]
        patcher, _ = _patch_cursor_rows(rows)
        with patcher:
            result = ScrapingRepository.get_processed_url_categoria_pairs()

        assert len(result) == 1
        assert ("https://example.com/art", "PEP-designacion") in result

    def test_handles_null_categoria_legacy_rows(self) -> None:
        """
        Legacy rows with NULL categoria appear as (url, None) in the set.
        Included so they don't re-trigger scraping.
        """
        rows = [
            ("https://legacy.example.com/old-article", None),
            ("https://example.com/new-article", "PEP-designacion"),
        ]
        patcher, _ = _patch_cursor_rows(rows)
        with patcher:
            result = ScrapingRepository.get_processed_url_categoria_pairs()

        assert ("https://legacy.example.com/old-article", None) in result
        assert ("https://example.com/new-article", "PEP-designacion") in result
        assert len(result) == 2

    def test_sql_selects_url_and_categoria_not_keyword(self) -> None:
        """
        The SQL issued must SELECT url, categoria (not url, keyword).
        """
        cursor_mock = MagicMock()
        cursor_mock.fetchall.return_value = []
        captured_sql: list = []

        def capture_execute(sql, params=None):
            captured_sql.append(sql)

        cursor_mock.execute.side_effect = capture_execute

        @contextmanager
        def fake_get_cursor(**kwargs):
            yield cursor_mock

        with patch.object(DatabaseManager, "get_cursor", side_effect=fake_get_cursor):
            ScrapingRepository.get_processed_url_categoria_pairs()

        assert len(captured_sql) == 1, "Expected exactly one SQL query"
        sql = captured_sql[0].lower()
        assert "url" in sql, "SQL must select 'url'"
        assert "categoria" in sql, "SQL must select 'categoria'"
        assert "keyword" not in sql, "SQL must NOT select 'keyword' as dedup column"
