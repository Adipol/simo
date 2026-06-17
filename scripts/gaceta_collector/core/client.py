"""
HTTP client for the gaceta collector.

Responsibilities:
- Apply per-request User-Agent header from config
- Throttle between requests (sleep delay_min_ms..delay_max_ms)
- Return HTML text; raise on non-2xx
"""
import random
import time
from typing import Optional

import requests

from config.settings import GacetaConfig


class GacetaClient:
    """Country-agnostic HTTP client with throttling and custom User-Agent."""

    def __init__(self, config: GacetaConfig) -> None:
        self._config = config
        self._session = requests.Session()
        self._last_request_at: Optional[float] = None

    @property
    def user_agent(self) -> str:
        return self._config.user_agent

    def get(self, url: str) -> str:
        """
        Fetch `url` and return the response body as text.

        Throttles before sending if a previous request was made.
        Raises requests.HTTPError on non-2xx status.
        """
        self._throttle()
        headers = {"User-Agent": self._config.user_agent}
        response = self._session.get(url, headers=headers, timeout=self._config.timeout_seconds)
        self._last_request_at = time.monotonic()
        response.raise_for_status()
        return response.text

    # ── private ──────────────────────────────────────────────────────────────

    def _throttle(self) -> None:
        """Sleep a random delay between delay_min_ms and delay_max_ms (only after first request)."""
        if self._last_request_at is None:
            return
        delay_s = random.uniform(
            self._config.delay_min_ms / 1000.0,
            self._config.delay_max_ms / 1000.0,
        )
        time.sleep(delay_s)
