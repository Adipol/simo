"""
Tests for utils/lemma_loader.py — TDD: RED written first.
"""
from typing import Dict, Set
from unittest.mock import MagicMock, patch

import pytest


class TestFallbackFamilies:
    """FALLBACK_FAMILIES must have minimal safety-net content."""

    def test_fallback_has_essential_designar_key(self) -> None:
        from utils.lemma_loader import FALLBACK_FAMILIES

        assert "designar" in FALLBACK_FAMILIES

    def test_fallback_has_essential_renunciar_key(self) -> None:
        from utils.lemma_loader import FALLBACK_FAMILIES

        assert "renunciar" in FALLBACK_FAMILIES

    def test_fallback_has_essential_detener_key(self) -> None:
        from utils.lemma_loader import FALLBACK_FAMILIES

        assert "detener" in FALLBACK_FAMILIES

    def test_fallback_designar_contains_variantes(self) -> None:
        from utils.lemma_loader import FALLBACK_FAMILIES

        variantes = FALLBACK_FAMILIES["designar"]
        assert "designación" in variantes
        assert "designado" in variantes

    def test_fallback_values_are_sets(self) -> None:
        from utils.lemma_loader import FALLBACK_FAMILIES

        for raiz, variantes in FALLBACK_FAMILIES.items():
            assert isinstance(variantes, set), f"{raiz} variantes should be a set"


class TestLoadFamiliesFromDb:
    """load_families_from_db() loads from DB cursor or falls back gracefully."""

    def test_load_from_db_returns_families_when_rows_exist(self, mock_cursor: MagicMock) -> None:
        """When cursor returns rows, returns a dict with raiz → set of variantes."""
        mock_dm = MagicMock()
        ctx_mgr = MagicMock()
        ctx_mgr.__enter__ = MagicMock(return_value=mock_cursor)
        ctx_mgr.__exit__ = MagicMock(return_value=False)
        mock_dm.get_cursor.return_value = ctx_mgr

        with patch("utils.lemma_loader.DatabaseManager", mock_dm):
            from utils.lemma_loader import load_families_from_db
            result = load_families_from_db()

        assert "designar" in result
        assert isinstance(result["designar"], set)
        assert "designación" in result["designar"]

    def test_load_from_db_failure_returns_fallback(self) -> None:
        """When DB raises, returns FALLBACK_FAMILIES."""
        mock_dm = MagicMock()
        mock_dm.get_cursor.side_effect = Exception("DB connection failed")

        with patch("utils.lemma_loader.DatabaseManager", mock_dm):
            from utils.lemma_loader import FALLBACK_FAMILIES, load_families_from_db
            result = load_families_from_db()

        assert "designar" in result
        # The fallback must include the essential keys
        assert "renunciar" in result or result is FALLBACK_FAMILIES

    def test_empty_db_rows_returns_fallback(self) -> None:
        """When cursor returns empty list, falls back to FALLBACK_FAMILIES."""
        empty_cursor = MagicMock()
        empty_cursor.fetchall.return_value = []

        mock_dm = MagicMock()
        ctx_mgr = MagicMock()
        ctx_mgr.__enter__ = MagicMock(return_value=empty_cursor)
        ctx_mgr.__exit__ = MagicMock(return_value=False)
        mock_dm.get_cursor.return_value = ctx_mgr

        with patch("utils.lemma_loader.DatabaseManager", mock_dm):
            from utils.lemma_loader import load_families_from_db
            result = load_families_from_db()

        assert "designar" in result

    def test_load_from_db_returns_lowercase_keys(self, mock_cursor: MagicMock) -> None:
        """All raiz keys must be lowercase."""
        mock_dm = MagicMock()
        ctx_mgr = MagicMock()
        ctx_mgr.__enter__ = MagicMock(return_value=mock_cursor)
        ctx_mgr.__exit__ = MagicMock(return_value=False)
        mock_dm.get_cursor.return_value = ctx_mgr

        with patch("utils.lemma_loader.DatabaseManager", mock_dm):
            from utils.lemma_loader import load_families_from_db
            result = load_families_from_db()

        for key in result:
            assert key == key.lower(), f"Key '{key}' is not lowercase"
