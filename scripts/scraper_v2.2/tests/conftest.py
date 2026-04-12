"""
Shared pytest fixtures for scraper_v2.2 tests.
"""
from typing import Dict, Set
from unittest.mock import MagicMock

import pytest

# ── spaCy availability check ─────────────────────────────────────────────────

try:
    import spacy

    spacy.load("es_core_news_sm")
    SPACY_AVAILABLE = True
except Exception:
    SPACY_AVAILABLE = False

spacy_required = pytest.mark.skipif(
    not SPACY_AVAILABLE,
    reason="spaCy es_core_news_sm not installed",
)


# ── Sample families fixture ──────────────────────────────────────────────────


@pytest.fixture
def sample_families() -> Dict[str, Set[str]]:
    """A small dict of lemma families for unit testing."""
    return {
        "designar": {"designar", "designación", "designaciones", "designado", "designada", "designó"},
        "renunciar": {"renunciar", "renuncia", "renuncias", "renunciado", "renunció"},
        "detener": {"detener", "detención", "detenciones", "detenido", "detenida"},
    }


@pytest.fixture
def mock_db_families(sample_families: Dict[str, Set[str]]) -> Dict[str, Set[str]]:
    """Same data as sample_families — simulates what load_families_from_db returns."""
    return dict(sample_families)


@pytest.fixture
def mock_cursor() -> MagicMock:
    """Mock cursor that returns rows matching the DB schema."""
    cursor = MagicMock()
    cursor.fetchall.return_value = [
        {"raiz": "designar", "variantes": '["designar","designación","designado","designada","designó"]'},
        {"raiz": "renunciar", "variantes": '["renunciar","renuncia","renuncias","renunciado","renunció"]'},
        {"raiz": "detener", "variantes": '["detener","detención","detenido","detenida"]'},
    ]
    return cursor
