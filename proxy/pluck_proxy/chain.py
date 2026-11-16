"""The renderer chain (PROXY_CONTRACT §6; Inktomi fetcher.py): plain -> FlareSolverr -> Chromium.

  - every shop touch first books a politeness slot; a domain in block cooldown is NOT touched
  - blocked / renderer failure / transport failure / JS shell escalate to the next rung
  - 404/410 and other non-block 4xx/5xx stop the chain (a bigger browser cannot cure them)
  - all rungs failed: blocked outranks upstream_error outranks shop_error, and a block starts the cooldown
"""
from __future__ import annotations

import asyncio
import time
from dataclasses import dataclass
from typing import Awaitable, Callable

from . import blocks, signals
from .config import Settings
from .politeness import Politeness
from .renderers import Fetch, Rung
from .target import Target


@dataclass
class Result:
    outcome: str  # fetched | blocked | shop_error | upstream_error
    renderer: str
    attempts: int
    status: int | None = None
    final_url: str | None = None
    html: str | None = None
    error: str | None = None
    block_signal: str | None = None
    retry_after: int | None = None


Sleeper = Callable[[float], Awaitable[None]]


class Chain:
    def __init__(self, rungs: list[Rung], politeness: Politeness, settings: Settings, sleeper: Sleeper = asyncio.sleep) -> None:
        self._rungs = rungs
        self._p = politeness
        self._s = settings
        self._sleep = sleeper

    async def run(self, url: str, target: Target, *, follow: bool, wait_ms: int, timeout_s: float) -> Result:
        host = target.host
        left_cd = self._p.cooldown_remaining(host)
        if left_cd is not None:
            return Result("blocked", "none", 0, error="domain in block cooldown; the shop was not contacted", block_signal="cooldown", retry_after=left_cd)

        deadline = time.monotonic() + timeout_s
        attempts = 0
        blocked: Result | None = None
        renderer_failure: Result | None = None
        transport_failure: Result | None = None
        shell: Result | None = None

        for rung in self._rungs:
            remaining = deadline - time.monotonic()
            if remaining <= 0:
                break
            await self._sleep(await self._p.reserve(host))
            attempts += 1
            fetch: Fetch = await rung.fetch(url, target, follow=follow, wait_ms=wait_ms, timeout=max(1.0, deadline - time.monotonic()))

            if fetch.failure == "too_large":
                return Result("shop_error", rung.name, attempts, fetch.status, fetch.final_url, error="content_too_large")
            if fetch.failure in ("cross_host_redirect", "redirect_not_followed"):
                return Result("shop_error", rung.name, attempts, fetch.status, fetch.final_url, error=f"{fetch.failure}: {fetch.error}")
            if fetch.failure == "renderer":
                renderer_failure = Result("upstream_error", rung.name, attempts, error=fetch.error)
                continue
            if fetch.failure == "transport":
                transport_failure = Result("shop_error", rung.name, attempts, error=f"transport: {fetch.error}")
                continue

            status, html = fetch.status or 0, fetch.html or ""
            genuine = 200 <= status < 300 and signals.has_json_ld_product(html)
            signal = blocks.evaluate(status, html, genuine_document=genuine)
            if signal is not None:
                blocked = Result("blocked", rung.name, attempts, status, fetch.final_url, error=None, block_signal=signal, retry_after=self._s.cooldown_seconds)
                continue
            if not 200 <= status < 300:
                return Result("shop_error", rung.name, attempts, status, fetch.final_url, error=f"shop answered HTTP {status}")
            if signals.looks_like_shell(html):
                shell = Result("fetched", rung.name, attempts, status, fetch.final_url, html)
                continue
            return Result("fetched", rung.name, attempts, status, fetch.final_url, html)

        if shell is not None:  # a page was obtained; the last renderer could not do better
            shell.attempts = attempts
            return shell
        if blocked is not None:
            blocked.attempts = attempts
            self._p.mark_blocked(host)
            return blocked
        if renderer_failure is not None:
            renderer_failure.attempts = attempts
            return renderer_failure
        if transport_failure is not None:
            transport_failure.attempts = attempts
            return transport_failure
        return Result("shop_error", "none", attempts, error="timeout budget exhausted before any rung answered")
