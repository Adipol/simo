"""Eligibility contract between Laravel site validation and the Python scraper."""
from contextlib import contextmanager
from unittest.mock import MagicMock, patch

from core.database import ScrapingRepository


@contextmanager
def _cursor_context(cursor):
    yield cursor


def test_get_websites_requires_active_and_valid_status():
    cursor = MagicMock()
    cursor.fetchall.return_value = []

    with patch("core.database.DatabaseManager.get_cursor", return_value=_cursor_context(cursor)):
        ScrapingRepository.get_websites("BO")

    query = cursor.execute.call_args.args[0]
    assert "activo IS TRUE" in query
    assert "validation_status = 'valid'" in query


def test_get_paises_activos_requires_an_eligible_site():
    cursor = MagicMock()
    cursor.fetchall.return_value = []

    with patch("core.database.DatabaseManager.get_cursor", return_value=_cursor_context(cursor)):
        ScrapingRepository.get_paises_activos()

    query = cursor.execute.call_args.args[0]
    assert "s.activo IS TRUE" in query
    assert "s.validation_status = 'valid'" in query
