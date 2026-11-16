from __future__ import annotations

import hashlib
import json
import time
from collections import OrderedDict
from typing import Callable


def key(url: str, strip: str, keep_xpaths: dict, preserve_scripts: bool, follow_redirects: bool) -> str:
    raw = json.dumps([url, strip, sorted(keep_xpaths.items()), preserve_scripts, follow_redirects], sort_keys=True)
    return hashlib.sha256(raw.encode()).hexdigest()


class Cache:
    """In-memory LRU+TTL. Only `fetched` envelopes are stored; ttl 0 bypasses both read and write."""

    def __init__(self, max_entries: int, clock: Callable[[], float] = time.monotonic) -> None:
        self._max = max_entries
        self._clock = clock
        self._items: OrderedDict[str, tuple[float, dict]] = OrderedDict()

    def get(self, k: str) -> dict | None:
        hit = self._items.get(k)
        if hit is None:
            return None
        if hit[0] <= self._clock():
            del self._items[k]
            return None
        self._items.move_to_end(k)
        return hit[1]

    def put(self, k: str, value: dict, ttl: int) -> None:
        if ttl <= 0 or self._max <= 0:
            return
        self._items[k] = (self._clock() + ttl, value)
        self._items.move_to_end(k)
        while len(self._items) > self._max:
            self._items.popitem(last=False)
