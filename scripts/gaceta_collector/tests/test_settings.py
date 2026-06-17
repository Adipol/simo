"""
Tests for config/settings.py — Strict TDD RED written first.

All settings are read from environment variables.
No I/O, no network calls.
"""
import os
from unittest.mock import patch

import pytest


class TestSettingsDefaults:
    """Settings provide sensible defaults when env vars are not set."""

    def test_settings_has_db_host(self) -> None:
        """DB host defaults to 'localhost'."""
        with patch.dict(os.environ, {}, clear=False):
            # Remove DB_HOST to test default
            env = {k: v for k, v in os.environ.items() if k != "DB_HOST"}
            with patch.dict(os.environ, env, clear=True):
                from config.settings import Settings
                s = Settings()
                assert s.db.host == "localhost"

    def test_settings_has_db_port(self) -> None:
        """DB port defaults to 5432 (PostgreSQL)."""
        env = {k: v for k, v in os.environ.items() if k != "DB_PORT"}
        with patch.dict(os.environ, env, clear=True):
            from config.settings import Settings
            s = Settings()
            assert s.db.port == 5432

    def test_settings_has_db_name(self) -> None:
        """DB name defaults to 'simo'."""
        env = {k: v for k, v in os.environ.items() if k != "DB_NAME"}
        with patch.dict(os.environ, env, clear=True):
            from config.settings import Settings
            s = Settings()
            assert s.db.name == "simo"

    def test_settings_has_user_agent(self) -> None:
        """User agent string is non-empty by default."""
        from config.settings import Settings
        s = Settings()
        assert s.gaceta.user_agent
        assert len(s.gaceta.user_agent) > 10

    def test_settings_throttle_defaults(self) -> None:
        """Throttle delay_min_ms and delay_max_ms are positive integers."""
        env = {k: v for k, v in os.environ.items()
               if k not in ("GACETA_DELAY_MIN_MS", "GACETA_DELAY_MAX_MS")}
        with patch.dict(os.environ, env, clear=True):
            from config.settings import Settings
            s = Settings()
            assert s.gaceta.delay_min_ms > 0
            assert s.gaceta.delay_max_ms >= s.gaceta.delay_min_ms

    def test_settings_max_pages_default(self) -> None:
        """max_pages defaults to a positive integer."""
        env = {k: v for k, v in os.environ.items() if k != "GACETA_MAX_PAGES"}
        with patch.dict(os.environ, env, clear=True):
            from config.settings import Settings
            s = Settings()
            assert s.gaceta.max_pages > 0


class TestSettingsReadsEnvVars:
    """Settings correctly override defaults from environment variables."""

    def test_db_host_from_env(self) -> None:
        """DB_HOST env var overrides the default."""
        with patch.dict(os.environ, {"DB_HOST": "10.0.0.1"}):
            from config.settings import Settings
            s = Settings()
            assert s.db.host == "10.0.0.1"

    def test_db_port_from_env(self) -> None:
        """DB_PORT env var is parsed as integer."""
        with patch.dict(os.environ, {"DB_PORT": "5433"}):
            from config.settings import Settings
            s = Settings()
            assert s.db.port == 5433

    def test_db_name_from_env(self) -> None:
        """DB_NAME env var overrides the default."""
        with patch.dict(os.environ, {"DB_NAME": "simo_test"}):
            from config.settings import Settings
            s = Settings()
            assert s.db.name == "simo_test"

    def test_gaceta_delay_min_ms_from_env(self) -> None:
        """GACETA_DELAY_MIN_MS env var parsed as integer."""
        with patch.dict(os.environ, {"GACETA_DELAY_MIN_MS": "500"}):
            from config.settings import Settings
            s = Settings()
            assert s.gaceta.delay_min_ms == 500

    def test_gaceta_max_pages_from_env(self) -> None:
        """GACETA_MAX_PAGES env var parsed as integer."""
        with patch.dict(os.environ, {"GACETA_MAX_PAGES": "10"}):
            from config.settings import Settings
            s = Settings()
            assert s.gaceta.max_pages == 10

    def test_gaceta_user_agent_from_env(self) -> None:
        """GACETA_USER_AGENT env var overrides the default."""
        custom_ua = "Mozilla/5.0 TestAgent/1.0"
        with patch.dict(os.environ, {"GACETA_USER_AGENT": custom_ua}):
            from config.settings import Settings
            s = Settings()
            assert s.gaceta.user_agent == custom_ua

    def test_db_password_from_env(self) -> None:
        """DB_PASSWORD env var is captured."""
        with patch.dict(os.environ, {"DB_PASSWORD": "secret123"}):
            from config.settings import Settings
            s = Settings()
            assert s.db.password == "secret123"
