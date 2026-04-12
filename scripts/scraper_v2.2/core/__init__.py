"""
Core module v2.2 - Motor principal del scraper con filtrado inteligente.
"""
from .scraper import (
    WebScraper,
    ParallelWebScraper,
    ScrapingResult,
    ScrapingStats,
    RelevanceLevel,
    URLFilter,
    ContentExtractor,
    KeywordMatcher
)
from .database import DatabaseManager, ScrapingRepository
from .webdriver_manager import WebDriverManager, DriverPool

__all__ = [
    'WebScraper',
    'ParallelWebScraper',
    'ScrapingResult',
    'ScrapingStats',
    'RelevanceLevel',
    'URLFilter',
    'ContentExtractor',
    'KeywordMatcher',
    'DatabaseManager',
    'ScrapingRepository',
    'WebDriverManager',
    'DriverPool',
]
