"""Settings. Every default traces to a recorded donor value, the contract, or the design's
hardware spec; anything else is configuration with no invented default (None / 0 = off)."""
from __future__ import annotations

import os
from dataclasses import dataclass, field


def _int(name: str, default: int) -> int:
    return int(os.environ.get(name, default))


def _float(name: str, default: float) -> float:
    return float(os.environ.get(name, default))


@dataclass(frozen=True)
class Settings:
    # Renderers
    flaresolverr_url: str | None = None  # Docker-internal, e.g. http://flaresolverr:8191
    chromium_path: str | None = None  # None = Playwright's own browser
    impersonate: str = "chrome124"  # Inktomi fetcher.py layer 1
    # Chromium rung: how long to wait for the network to go idle AFTER `load` before taking the DOM.
    # Inktomi waits for `networkidle` outright, which never arrives on chatty shops (measured: ah.nl);
    # this soft cap is an operational default with no donor value — measure and adjust.
    networkidle_soft_ms: int = 10_000

    # Politeness (Inktomi src/superraten/config.py, user-mandated)
    domain_interval: float = 3.0
    global_interval: float = 1.0
    host_intervals: dict[str, float] = field(default_factory=lambda: {"openfoodfacts.org": 2.1})
    cooldown_seconds: int = 600  # Inktomi: 10-minute per-domain cooldown after a block
    breaker_seconds: int = 120  # Inktomi: FlareSolverr circuit breaker

    # Caps (PROXY_CONTRACT §3; 3 MB is the recorded donor cap)
    max_html_bytes: int = 3 * 1024 * 1024
    max_fragments_bytes: int = 1024 * 1024
    max_fragment_nodes: int = 50
    max_fragment_node_bytes: int = 64 * 1024
    max_redirects: int = 3

    # Capacity: the design's VPS is 4 vCPU (FUNCTIONAL_DESIGN §2 #2)
    max_concurrency: int = 4
    # Largest valid request is ~11 KB (2048 URL + 16 x (32 + 500)); configurable, generous headroom
    max_request_bytes: int = 32 * 1024
    # Per-consumer requests per minute; 0 = off (no donor value exists, so no invented default)
    rate_limit_per_minute: int = 0

    # Cache (in-memory); entries bound is configuration, not contract
    cache_max_entries: int = 1000

    accept_language: str = "nl-NL,nl;q=0.9"  # WelZoLekker ProductPageFetcher

    @classmethod
    def from_env(cls) -> "Settings":
        return cls(
            flaresolverr_url=os.environ.get("FLARESOLVERR_URL") or None,
            chromium_path=os.environ.get("CHROMIUM_PATH") or None,
            max_concurrency=_int("PLUCK_MAX_CONCURRENCY", 4),
            rate_limit_per_minute=_int("PLUCK_RATE_LIMIT_PER_MINUTE", 0),
            domain_interval=_float("PLUCK_DOMAIN_INTERVAL", 3.0),
            global_interval=_float("PLUCK_GLOBAL_INTERVAL", 1.0),
            cache_max_entries=_int("PLUCK_CACHE_MAX_ENTRIES", 1000),
            max_request_bytes=_int("PLUCK_MAX_REQUEST_BYTES", 32 * 1024),
        )

    def __post_init__(self) -> None:
        # Contract §6: floors are overridable UPWARD only.
        if self.domain_interval < 3.0 or self.global_interval < 1.0:
            raise ValueError("Politeness intervals may only be raised above the 3 s / 1 s floors.")
