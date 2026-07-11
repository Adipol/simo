"""
TDD Phase 3.A.4 — Integration test: restart-no-duplicates scenario.

Spec capability: dedup-by-url-categoria
Scenario: rescrape after restart → no duplicates.

Simulates:
  1. First run: insert (url=X, categoria=A, keyword=k1)
  2. Scraper restarts → new processed_pairs built from DB (returns {(X, A)})
  3. Same source rescrapes → finds k1 AND k2 (two keywords, same categoria)
  4. Expected: only ONE result collected (dedup blocks second attempt)

All DB calls are mocked — no real connection needed.
"""

import sys
from contextlib import contextmanager
from typing import Optional, Set, Tuple
from unittest.mock import MagicMock, patch, call

import pytest

# ── Load core/database.py under ISOLATED stubs ────────────────────────────────
# Same isolation approach as test_processed_pairs.py: stub heavy deps ONLY while
# loading database.py (via patch.dict), so the MagicMock stubs do not leak into
# sys.modules and poison other test modules that need the real spacy/config/utils.

def _load_database_module_isolated():
    """Load core/database.py with heavy deps stubbed, without leaking the stubs."""
    import importlib.util
    import os

    _cfg = MagicMock()
    _cfg.settings.db.db_type = "postgres"
    _utils = MagicMock()
    _utils.logger.get_logger = lambda n: MagicMock()

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
        "config": _cfg,
        "config.settings": _cfg,
        "utils": _utils,
        "utils.logger": _utils.logger,
    }

    db_path = os.path.join(os.path.dirname(__file__), "..", "core", "database.py")
    with patch.dict(sys.modules, stubs):
        spec = importlib.util.spec_from_file_location("core._database_dedup_isolated", db_path)
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
    return module


_db_mod = _load_database_module_isolated()
ScrapingRepository = _db_mod.ScrapingRepository
DatabaseManager = _db_mod.DatabaseManager


# ── Helper ────────────────────────────────────────────────────────────────────

def _patch_cursor_rows(rows: list):
    """Patch DatabaseManager.get_cursor to return given rows."""
    cursor_mock = MagicMock()
    cursor_mock.fetchall.return_value = rows

    @contextmanager
    def fake_get_cursor(**kwargs):
        yield cursor_mock

    patcher = patch.object(DatabaseManager, "get_cursor", side_effect=fake_get_cursor)
    return patcher, cursor_mock


# ── Tests ─────────────────────────────────────────────────────────────────────


class TestRestartNoDuplicates:
    """
    Restart scenario: scraper reloads processed_pairs from DB and correctly
    blocks re-insertion of (url, categoria) pairs already in DB.
    """

    def test_restart_builds_processed_pairs_from_db(self) -> None:
        """
        After restart, get_processed_url_categoria_pairs() rebuilds the
        in-memory set from DB state. The set contains (url, categoria).
        """
        # DB state after first run: one row (url=X, categoria=A)
        db_state = [
            ("https://source.example.com/article-pep", "PEP-designacion"),
        ]
        patcher, _ = _patch_cursor_rows(db_state)
        with patcher:
            rebuilt_pairs = ScrapingRepository.get_processed_url_categoria_pairs()

        assert ("https://source.example.com/article-pep", "PEP-designacion") in rebuilt_pairs

    def test_in_memory_dedup_blocks_second_keyword_same_categoria(self) -> None:
        """
        Within a single run: url=X already added for categoria=A (keyword=k1).
        When keyword=k2 matches the same url+categoria → dedup check blocks it.
        The processed_pairs set has only one entry for (url, categoria).
        """
        url = "https://source.example.com/article-pep"
        categoria = "PEP-designacion"

        # Simulate in-memory set after first keyword match
        processed_pairs: Set[Tuple[str, Optional[str]]] = set()

        # First keyword match: k1 passes (url, categoria) not yet in set
        assert (url, categoria) not in processed_pairs
        processed_pairs.add((url, categoria))

        # Second keyword match: k2 for SAME (url, categoria) → blocked
        assert (url, categoria) in processed_pairs  # dedup blocks second insert

        # Set still has exactly ONE entry (not two)
        assert len(processed_pairs) == 1
        assert (url, categoria) in processed_pairs

    def test_restart_then_rescrape_blocked_by_rebuilt_set(self) -> None:
        """
        Full scenario:
        1. First run inserts (url=X, cat=A, keyword=k1) → set gets {(X, A)}
        2. Restart: get_processed_url_categoria_pairs() returns {(X, A)}
        3. Rescrape finds k1 and k2 on same url → both blocked by (X, A) in set
        → 0 new results collected (no re-insertion)
        """
        url = "https://source.example.com/article-pep"
        categoria = "PEP-designacion"

        # Step 2: Simulate DB returning existing (url, categoria) after restart
        db_state = [(url, categoria)]
        patcher, _ = _patch_cursor_rows(db_state)
        with patcher:
            processed_pairs = ScrapingRepository.get_processed_url_categoria_pairs()

        # Step 3: Simulate scraper finding both k1 and k2 on same URL/categoria
        found_keywords = ["designacion", "designado"]  # two keywords, same categoria
        collected_results = []

        for keyword in found_keywords:
            if (url, categoria) in processed_pairs:
                continue  # blocked — (url, categoria) already in set
            collected_results.append({"url": url, "keyword": keyword, "categoria": categoria})
            processed_pairs.add((url, categoria))

        # 0 new results: both keywords blocked because (url, categoria) was in rebuilt set
        assert len(collected_results) == 0, (
            f"Expected 0 results after restart (dedup should block both keywords), "
            f"got {len(collected_results)}"
        )

    def test_different_categoria_not_blocked(self) -> None:
        """
        (url=X, categoria=A) in processed_pairs does NOT block (url=X, categoria=B).
        Different categories are independent dedup domains.
        """
        url = "https://source.example.com/article"
        cat_a = "PEP-designacion"
        cat_b = "OPI"

        # Simulate set with only cat_a
        processed_pairs: Set[Tuple[str, Optional[str]]] = {(url, cat_a)}

        # cat_b for same url is NOT blocked
        assert (url, cat_b) not in processed_pairs

        # Simulate processing cat_b keyword → adds its own entry
        collected = []
        if (url, cat_b) not in processed_pairs:
            collected.append({"url": url, "keyword": "discurso", "categoria": cat_b})
            processed_pairs.add((url, cat_b))

        assert len(collected) == 1
        assert collected[0]["categoria"] == cat_b
        # Now set has both
        assert len(processed_pairs) == 2
