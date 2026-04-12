"""
Tests for spaCy integration in KeywordMatcher.
Uses the spacy_required marker to skip when spaCy is not available.
"""
from unittest.mock import patch

import pytest

from tests.conftest import spacy_required


class TestSpaCySingleton:
    """spaCy model must be loaded only once (class-level singleton)."""

    def test_spacy_classmethod_singleton(self) -> None:
        """_init_spacy() called multiple times, loads spaCy only once."""
        from core.scraper import KeywordMatcher

        # Reset singleton state
        KeywordMatcher._nlp = None
        KeywordMatcher._spacy_tried = False

        # Mock spacy.load to track calls
        with patch("core.scraper.spacy") as mock_spacy:
            mock_nlp = mock_spacy.load.return_value
            KeywordMatcher._init_spacy()
            KeywordMatcher._init_spacy()  # Second call should be a no-op
            KeywordMatcher._init_spacy()  # Third call should be a no-op

        # Should only load once
        mock_spacy.load.assert_called_once_with("es_core_news_sm")

    def test_spacy_failure_sets_nlp_to_none(self) -> None:
        """If spaCy load fails, _nlp is None and no crash."""
        from core.scraper import KeywordMatcher

        KeywordMatcher._nlp = None
        KeywordMatcher._spacy_tried = False

        with patch("core.scraper.spacy") as mock_spacy:
            mock_spacy.load.side_effect = Exception("Model not found")
            KeywordMatcher._init_spacy()

        assert KeywordMatcher._nlp is None
        assert KeywordMatcher._spacy_tried is True


@spacy_required
class TestSpaCyAvailable:
    """These tests only run when es_core_news_sm is installed."""

    def test_spacy_model_loads_successfully(self) -> None:
        """spaCy model loads without error."""
        import spacy

        nlp = spacy.load("es_core_news_sm")
        assert nlp is not None

    def test_spacy_lemmatizes_example_word(self) -> None:
        """spaCy can process Spanish text without crashing."""
        import spacy

        nlp = spacy.load("es_core_news_sm")
        doc = nlp("El presidente designó a su ministro.")
        tokens = [token.text for token in doc]
        assert "designó" in tokens
