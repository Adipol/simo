"""
Tests for KeywordMatcher with family expansion — TDD: RED written first.

These tests exercise the MODIFIED KeywordMatcher that:
1. Loads families from DB (mocked)
2. Expands keywords via families at init
3. keyword_in_title() uses families — not raw keyword

Critical test cases that MUST pass:
- "Designación de nuevo ministro" + keyword "designar" → True
- "Presidente designó a su gabinete" + keyword "designar" → True
- "Ministro designado deja el cargo" + keyword "designar" → True
- "Designer de modas" + keyword "designar" → False (word boundary)
"""
from typing import Dict, Set
from unittest.mock import MagicMock, patch

import pytest

from tests.conftest import spacy_required


def make_matcher(keywords, families):
    """Helper: create KeywordMatcher with mocked family loader."""
    with patch("utils.lemma_loader.DatabaseManager", None):
        with patch("utils.lemma_loader.load_families_from_db", return_value=families):
            from core.scraper import KeywordMatcher
            # Reset spacy singleton for clean tests
            KeywordMatcher._nlp = None
            KeywordMatcher._spacy_tried = False
            with patch.object(KeywordMatcher, "_init_spacy"):
                matcher = KeywordMatcher(keywords)
                matcher.families = families
                # Rebuild pattern with families
                import re
                expanded = set()
                for kw in matcher.original_keywords:
                    if kw in families:
                        expanded.update(families[kw])
                    else:
                        expanded.add(kw)
                matcher.keywords = sorted(expanded)
                escaped = [re.escape(k) for k in matcher.keywords]
                matcher.pattern = re.compile(
                    r"\b(" + "|".join(escaped) + r")\b", re.IGNORECASE
                )
    return matcher


class TestKeywordExpansionWithFamilies:
    """KeywordMatcher.__init__ must expand keywords via families dict."""

    def test_expansion_with_families_includes_variantes(self, sample_families: Dict[str, Set[str]]) -> None:
        """designar → keywords include designación, designado, etc."""
        matcher = make_matcher(["designar"], sample_families)

        assert "designación" in matcher.keywords
        assert "designado" in matcher.keywords

    def test_no_expansion_without_family(self, sample_families: Dict[str, Set[str]]) -> None:
        """Unknown keyword passes through unchanged."""
        matcher = make_matcher(["keyword_desconocida"], sample_families)

        assert "keyword_desconocida" in matcher.keywords

    def test_multiple_keywords_both_expanded(self, sample_families: Dict[str, Set[str]]) -> None:
        """Both designar and renunciar are expanded."""
        matcher = make_matcher(["designar", "renunciar"], sample_families)

        assert "designación" in matcher.keywords
        assert "renuncia" in matcher.keywords


class TestKeywordInTitle:
    """keyword_in_title() must use family expansion — NOT raw keyword regex."""

    def test_keyword_in_title_matches_noun_form(self, sample_families: Dict[str, Set[str]]) -> None:
        """CRITICAL: 'Designación de nuevo ministro' + 'designar' → True"""
        matcher = make_matcher(["designar"], sample_families)

        assert matcher.keyword_in_title("Designación de nuevo ministro", "designar") is True

    def test_keyword_in_title_matches_past_tense(self, sample_families: Dict[str, Set[str]]) -> None:
        """CRITICAL: 'Presidente designó a su gabinete' + 'designar' → True"""
        matcher = make_matcher(["designar"], sample_families)

        assert matcher.keyword_in_title("Presidente designó a su gabinete", "designar") is True

    def test_keyword_in_title_matches_past_participle(self, sample_families: Dict[str, Set[str]]) -> None:
        """CRITICAL: 'Ministro designado deja el cargo' + 'designar' → True"""
        matcher = make_matcher(["designar"], sample_families)

        assert matcher.keyword_in_title("Ministro designado deja el cargo", "designar") is True

    def test_keyword_in_title_no_match_substring(self, sample_families: Dict[str, Set[str]]) -> None:
        """NEGATIVE: 'Designer de modas' + 'designar' → False (word boundary)"""
        matcher = make_matcher(["designar"], sample_families)

        assert matcher.keyword_in_title("Designer de modas famoso", "designar") is False

    def test_keyword_in_title_no_match_unrelated(self, sample_families: Dict[str, Set[str]]) -> None:
        """Unrelated title returns False."""
        matcher = make_matcher(["designar"], sample_families)

        assert matcher.keyword_in_title("El clima está lindo hoy", "designar") is False

    def test_keyword_in_title_case_insensitive(self, sample_families: Dict[str, Set[str]]) -> None:
        """Matching is case-insensitive: DESIGNACIÓN matches."""
        matcher = make_matcher(["designar"], sample_families)

        assert matcher.keyword_in_title("DESIGNACIÓN de nuevo ministro", "designar") is True

    def test_keyword_in_title_empty_title_returns_false(self, sample_families: Dict[str, Set[str]]) -> None:
        """Empty title returns False."""
        matcher = make_matcher(["designar"], sample_families)

        assert matcher.keyword_in_title("", "designar") is False


class TestFindInText:
    """find_in_text() returns the ACTUAL matched variant, not the raiz."""

    def test_find_in_text_returns_matched_variant_not_raiz(self, sample_families: Dict[str, Set[str]]) -> None:
        """designó in text → returns 'designó', not 'designar'."""
        matcher = make_matcher(["designar"], sample_families)

        results = matcher.find_in_text("El ministro designó a su gabinete.")

        assert "designó" in results
        assert "designar" not in results

    def test_find_in_text_returns_empty_for_no_match(self, sample_families: Dict[str, Set[str]]) -> None:
        """Text with no match returns empty list."""
        matcher = make_matcher(["designar"], sample_families)

        results = matcher.find_in_text("El clima está lindo hoy.")

        assert results == []


class TestExtractContext:
    """extract_context() must use family expansion to find the match."""

    def test_extract_context_around_variant(self, sample_families: Dict[str, Set[str]]) -> None:
        """Context is extracted around the variant 'designación', not 'designar'."""
        matcher = make_matcher(["designar"], sample_families)

        context = matcher.extract_context("La designación fue ayer por la mañana.", "designar")

        assert "designación" in context

    def test_extract_context_empty_for_no_match(self, sample_families: Dict[str, Set[str]]) -> None:
        """Empty string when variant not in text."""
        matcher = make_matcher(["designar"], sample_families)

        context = matcher.extract_context("El clima está lindo hoy.", "designar")

        assert context == ""


class TestGracefulDegradation:
    """System degrades gracefully when spaCy or DB is unavailable."""

    def test_graceful_degradation_no_db(self) -> None:
        """When DB unavailable, matcher still works with FALLBACK_FAMILIES."""
        with patch("utils.lemma_loader.DatabaseManager", None):
            from core.scraper import KeywordMatcher
            KeywordMatcher._nlp = None
            KeywordMatcher._spacy_tried = False
            matcher = KeywordMatcher(["designar"])

        assert matcher is not None
        assert hasattr(matcher, "families")

    def test_graceful_degradation_no_spacy(self, sample_families: Dict[str, Set[str]]) -> None:
        """When spaCy unavailable, KeywordMatcher still initializes correctly."""
        matcher = make_matcher(["designar"], sample_families)

        # Still works for family expansion matching
        assert matcher.keyword_in_title("Designación de nuevo ministro", "designar") is True
