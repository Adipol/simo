"""
Centralized configuration for the gaceta_collector.
All values read from environment variables with sensible defaults.
No dotenv loading here — the caller (runner/main) handles .env loading.
"""
import os
from dataclasses import dataclass, field
from typing import Optional


@dataclass(frozen=True)
class DatabaseConfig:
    """PostgreSQL connection settings."""

    host: str = field(default_factory=lambda: os.getenv("DB_HOST", "localhost"))
    port: int = field(default_factory=lambda: int(os.getenv("DB_PORT", "5432")))
    name: str = field(default_factory=lambda: os.getenv("DB_NAME", "simo"))
    user: str = field(default_factory=lambda: os.getenv("DB_USER", "postgres"))
    password: str = field(default_factory=lambda: os.getenv("DB_PASSWORD", ""))
    connect_timeout: int = 10

    def as_psycopg2_kwargs(self) -> dict:
        """Return keyword arguments suitable for psycopg2.connect()."""
        return {
            "host": self.host,
            "port": self.port,
            "dbname": self.name,
            "user": self.user,
            "password": self.password,
            "connect_timeout": self.connect_timeout,
        }


@dataclass(frozen=True)
class GacetaConfig:
    """Gaceta-specific collection settings."""

    # HTTP throttle — milliseconds between requests
    delay_min_ms: int = field(
        default_factory=lambda: int(os.getenv("GACETA_DELAY_MIN_MS", "1000"))
    )
    delay_max_ms: int = field(
        default_factory=lambda: int(os.getenv("GACETA_DELAY_MAX_MS", "3000"))
    )

    # Pagination cap (first-run guard)
    max_pages: int = field(
        default_factory=lambda: int(os.getenv("GACETA_MAX_PAGES", "5"))
    )

    # User-Agent header sent with every request
    user_agent: str = field(
        default_factory=lambda: os.getenv(
            "GACETA_USER_AGENT",
            "Mozilla/5.0 (compatible; SIMO-GacetaBot/1.0; +https://simo.example.com)",
        )
    )

    # Per-request HTTP timeout in seconds (prevents indefinite hangs on slow servers)
    timeout_seconds: int = field(
        default_factory=lambda: int(os.getenv("GACETA_TIMEOUT_SECONDS", "30"))
    )

    # Backfill look-back window in years.
    # Default: 5 — Bolivian PEP regulation retains PEP status for 5 years after
    # leaving office, so the baseline must cover everyone who held office in the
    # last 5 years. Configurable so operators can adjust if the regulation changes.
    backfill_years: int = field(
        default_factory=lambda: int(os.getenv("GACETA_BACKFILL_YEARS", "5"))
    )

    # Safety page cap for the backfill crawl. Prevents a misconfigured cutoff
    # date from crawling hundreds of pages in a single run.
    backfill_max_pages: int = field(
        default_factory=lambda: int(os.getenv("GACETA_BACKFILL_MAX_PAGES", "60"))
    )


@dataclass(frozen=True)
class Settings:
    """Global application configuration."""

    db: DatabaseConfig = field(default_factory=DatabaseConfig)
    gaceta: GacetaConfig = field(default_factory=GacetaConfig)
