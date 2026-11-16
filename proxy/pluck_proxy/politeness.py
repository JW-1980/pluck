"""Per-domain politeness (PROXY_CONTRACT §6): 3 s/domain, 1 s global, 2.1 s Open Food Facts,
10-minute cooldown after a block, and a 120 s renderer circuit breaker (Inktomi fetcher.py)."""
from __future__ import annotations

import asyncio
import math
import time
from typing import Callable

from .config import Settings


class Politeness:
    def __init__(self, settings: Settings, clock: Callable[[], float] = time.monotonic) -> None:
        self._s = settings
        self._clock = clock
        self._domain_next: dict[str, float] = {}
        self._global_next = 0.0
        self._blocked_until: dict[str, float] = {}
        self._lock = asyncio.Lock()

    def _interval(self, host: str) -> float:
        for suffix, seconds in self._s.host_intervals.items():
            if host == suffix or host.endswith("." + suffix):
                return seconds
        return self._s.domain_interval

    async def reserve(self, host: str) -> float:
        """Books the next slot for `host`; returns the seconds to wait (the caller sleeps)."""
        host = host.lower().rstrip(".")
        async with self._lock:
            now = self._clock()
            slot = max(now, self._domain_next.get(host, 0.0), self._global_next)
            self._domain_next[host] = slot + self._interval(host)
            self._global_next = slot + self._s.global_interval
            return slot - now

    def cooldown_remaining(self, host: str) -> int | None:
        until = self._blocked_until.get(host.lower().rstrip("."))
        if until is None:
            return None
        left = math.ceil(until - self._clock())
        return left if left > 0 else None

    def mark_blocked(self, host: str) -> None:
        self._blocked_until[host.lower().rstrip(".")] = self._clock() + self._s.cooldown_seconds


class Breaker:
    def __init__(self, seconds: int, clock: Callable[[], float] = time.monotonic) -> None:
        self._seconds = seconds
        self._clock = clock
        self._until = 0.0

    def remaining(self) -> int | None:
        left = math.ceil(self._until - self._clock())
        return left if left > 0 else None

    def failure(self) -> None:
        self._until = self._clock() + self._seconds

    def success(self) -> None:
        self._until = 0.0
