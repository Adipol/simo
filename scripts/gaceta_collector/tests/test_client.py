"""
Tests for core/client.py — Strict TDD RED written first.

GacetaClient must:
- Set User-Agent header on every request
- Throttle between requests (sleep between delay_min_ms and delay_max_ms)
- Return HTML text on 200
- Raise on non-200 status
- HTTP calls are MOCKED — no real network.
"""
import time
from unittest.mock import MagicMock, patch, call

import pytest

from config.settings import GacetaConfig


def _make_config(
    delay_min_ms: int = 200,
    delay_max_ms: int = 400,
    user_agent: str = "TestBot/1.0",
    timeout_seconds: int = 30,
) -> GacetaConfig:
    """Helper — create a GacetaConfig with fast throttle for tests."""
    import os
    with patch.dict(os.environ, {
        "GACETA_DELAY_MIN_MS": str(delay_min_ms),
        "GACETA_DELAY_MAX_MS": str(delay_max_ms),
        "GACETA_USER_AGENT": user_agent,
        "GACETA_TIMEOUT_SECONDS": str(timeout_seconds),
    }):
        return GacetaConfig()


class TestGacetaClientInit:
    """GacetaClient initializes correctly."""

    def test_client_accepts_config(self) -> None:
        """GacetaClient can be instantiated with a GacetaConfig."""
        from core.client import GacetaClient
        cfg = _make_config()
        client = GacetaClient(cfg)
        assert client is not None

    def test_client_stores_user_agent(self) -> None:
        """GacetaClient stores the user_agent from config."""
        from core.client import GacetaClient
        cfg = _make_config(user_agent="CustomAgent/2.0")
        client = GacetaClient(cfg)
        assert client.user_agent == "CustomAgent/2.0"


class TestGacetaClientGet:
    """GacetaClient.get() method behavior."""

    def _mock_response(self, status_code: int = 200, text: str = "<html></html>") -> MagicMock:
        resp = MagicMock()
        resp.status_code = status_code
        resp.text = text
        resp.raise_for_status = MagicMock()
        if status_code >= 400:
            from requests import HTTPError
            resp.raise_for_status.side_effect = HTTPError(f"{status_code}")
        return resp

    def test_get_returns_html_text_on_200(self) -> None:
        """get() returns the response text when status is 200."""
        from core.client import GacetaClient
        cfg = _make_config()
        client = GacetaClient(cfg)
        html = "<html><body>Normas</body></html>"
        mock_resp = self._mock_response(200, html)

        with patch("requests.Session.get", return_value=mock_resp):
            result = client.get("https://example.com/normas/listadonor/11")

        assert result == html

    def test_get_sets_user_agent_header(self) -> None:
        """get() sends the User-Agent header from config."""
        from core.client import GacetaClient
        cfg = _make_config(user_agent="SIMO-Bot/1.0")
        client = GacetaClient(cfg)
        mock_resp = self._mock_response(200)

        with patch("requests.Session.get", return_value=mock_resp) as mock_get:
            client.get("https://example.com/test")

        _args, kwargs = mock_get.call_args
        headers = kwargs.get("headers", {})
        assert headers.get("User-Agent") == "SIMO-Bot/1.0"

    def test_get_raises_on_non_200(self) -> None:
        """get() raises an exception when the server returns a non-200 status."""
        from core.client import GacetaClient
        from requests import HTTPError
        cfg = _make_config()
        client = GacetaClient(cfg)
        mock_resp = self._mock_response(404)

        with patch("requests.Session.get", return_value=mock_resp):
            with pytest.raises(HTTPError):
                client.get("https://example.com/missing")


class TestGacetaClientThrottle:
    """GacetaClient throttles between consecutive requests."""

    def test_throttle_sleeps_between_requests(self) -> None:
        """Second get() call sleeps before sending the request."""
        from core.client import GacetaClient
        cfg = _make_config(delay_min_ms=100, delay_max_ms=100)
        client = GacetaClient(cfg)
        mock_resp = MagicMock()
        mock_resp.status_code = 200
        mock_resp.text = "<html/>"
        mock_resp.raise_for_status = MagicMock()

        with patch("requests.Session.get", return_value=mock_resp), \
             patch("time.sleep") as mock_sleep:
            client.get("https://example.com/page1")
            client.get("https://example.com/page2")

        # At least one sleep call with a positive delay
        assert mock_sleep.call_count >= 1
        sleep_args = [c.args[0] for c in mock_sleep.call_args_list]
        assert all(s >= 0 for s in sleep_args)

    def test_no_sleep_on_first_request(self) -> None:
        """First get() call does NOT sleep (no prior request to throttle against)."""
        from core.client import GacetaClient
        cfg = _make_config(delay_min_ms=500, delay_max_ms=500)
        client = GacetaClient(cfg)
        mock_resp = MagicMock()
        mock_resp.status_code = 200
        mock_resp.text = "<html/>"
        mock_resp.raise_for_status = MagicMock()

        with patch("requests.Session.get", return_value=mock_resp), \
             patch("time.sleep") as mock_sleep:
            client.get("https://example.com/first")

        mock_sleep.assert_not_called()

    def test_throttle_delay_within_configured_bounds(self) -> None:
        """Sleep duration is within [delay_min_ms, delay_max_ms] converted to seconds."""
        from core.client import GacetaClient
        cfg = _make_config(delay_min_ms=200, delay_max_ms=600)
        client = GacetaClient(cfg)
        mock_resp = MagicMock()
        mock_resp.status_code = 200
        mock_resp.text = "<html/>"
        mock_resp.raise_for_status = MagicMock()

        sleep_calls = []
        with patch("requests.Session.get", return_value=mock_resp), \
             patch("time.sleep", side_effect=lambda s: sleep_calls.append(s)):
            client.get("https://example.com/page1")
            client.get("https://example.com/page2")

        # The second call should have caused a sleep
        assert len(sleep_calls) >= 1
        for s in sleep_calls:
            assert 0.2 <= s <= 0.6, f"Sleep {s}s outside expected range 0.2-0.6s"


class TestGacetaClientTimeout:
    """GacetaClient passes the configured timeout to each HTTP request."""

    def _mock_response(self, status_code: int = 200, text: str = "<html/>") -> MagicMock:
        resp = MagicMock()
        resp.status_code = status_code
        resp.text = text
        resp.raise_for_status = MagicMock()
        return resp

    def test_get_passes_timeout_to_session(self) -> None:
        """get() passes timeout_seconds from config to requests.Session.get()."""
        from core.client import GacetaClient
        cfg = _make_config(timeout_seconds=45)
        client = GacetaClient(cfg)
        mock_resp = self._mock_response(200)

        with patch("requests.Session.get", return_value=mock_resp) as mock_get:
            client.get("https://example.com/test")

        _args, kwargs = mock_get.call_args
        assert kwargs.get("timeout") == 45

    def test_default_timeout_is_30_seconds(self) -> None:
        """Default GACETA_TIMEOUT_SECONDS resolves to 30."""
        from config.settings import GacetaConfig
        import os
        env = {k: v for k, v in os.environ.items() if k != "GACETA_TIMEOUT_SECONDS"}
        with patch.dict(os.environ, env, clear=True):
            cfg = GacetaConfig()
        assert cfg.timeout_seconds == 30
