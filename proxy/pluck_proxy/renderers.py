"""The renderer chain's rungs (Inktomi fetcher.py): TLS-impersonating plain HTTP -> FlareSolverr
-> Playwright Chromium. A rung never raises for a shop-side or renderer-side failure; it returns a
Fetch whose `failure` says what kind, and the chain decides the outcome."""
from __future__ import annotations

import asyncio
import json
import time
from dataclasses import dataclass
from urllib.parse import urljoin, urlsplit

import httpx
from curl_cffi import CurlOpt
from curl_cffi.requests import AsyncSession
from curl_cffi.requests.exceptions import RequestException

from .config import Settings
from .errors import RequestError
from .politeness import Breaker
from .target import Target, TargetPolicy


@dataclass
class Fetch:
    status: int | None = None
    final_url: str | None = None
    html: str | None = None
    # None = a page came back; else: transport | too_large | renderer | cross_host_redirect | redirect_not_followed
    failure: str | None = None
    error: str | None = None


def same_site(a: str, b: str) -> bool:
    """Same host, or its www.-toggle (the donors' "same domain")."""
    strip = lambda h: (h or "").lower().removeprefix("www.")
    return bool(strip(a)) and strip(a) == strip(b)


class Rung:
    name = "rung"

    async def fetch(self, url: str, target: Target, *, follow: bool, wait_ms: int, timeout: float) -> Fetch:
        raise NotImplementedError


class PlainRung(Rung):
    """curl_cffi with Chrome TLS impersonation (Inktomi layer 1). Pinned to the vetted IP, every redirect
    hop re-vetted, same-site only, body capped mid-transfer."""

    name = "plain"

    def __init__(self, settings: Settings, policy: TargetPolicy) -> None:
        self._s = settings
        self._policy = policy

    async def fetch(self, url: str, target: Target, *, follow: bool, wait_ms: int, timeout: float) -> Fetch:
        deadline = time.monotonic() + timeout
        current, tgt = url, target
        start_host = target.host
        for hop in range(self._s.max_redirects + 1):
            left = deadline - time.monotonic()
            if left <= 0:
                return Fetch(failure="transport", error="timeout")
            ip = f"[{tgt.ip}]" if ":" in tgt.ip else tgt.ip
            try:
                # pin to the vetted address (DNS cannot change between check and connect)
                async with AsyncSession(impersonate=self._s.impersonate, curl_options={CurlOpt.RESOLVE: [f"{tgt.host}:{tgt.port}:{ip}"]}) as session:
                    resp = await session.get(
                        current,
                        stream=True,
                        allow_redirects=False,
                        timeout=left,
                        headers={"Accept-Language": self._s.accept_language},
                    )
                    status = resp.status_code
                    if 300 <= status < 400 and resp.headers.get("location"):
                        location = urljoin(current, resp.headers["location"])
                        await resp.aclose()
                        if not follow:
                            return Fetch(status=status, final_url=current, html="")
                        if hop == self._s.max_redirects:
                            return Fetch(failure="cross_host_redirect", error="more than the allowed redirects")
                        nxt = urlsplit(location)
                        if urlsplit(current).scheme == "https" and nxt.scheme != "https":
                            return Fetch(failure="cross_host_redirect", error="redirect downgrades https to http")
                        if not same_site(start_host, nxt.hostname or ""):
                            return Fetch(failure="cross_host_redirect", error=f"redirect leaves the shop host (to {nxt.hostname})")
                        try:
                            tgt = await self._policy.vet(location)
                        except RequestError as e:
                            return Fetch(failure="cross_host_redirect", error=e.message)
                        current = location
                        continue

                    declared = resp.headers.get("content-length", "")
                    if declared.isdigit() and int(declared) > self._s.max_html_bytes:
                        await resp.aclose()
                        return Fetch(status=status, final_url=current, failure="too_large")
                    chunks: list[bytes] = []
                    size = 0
                    async for chunk in resp.aiter_content():
                        size += len(chunk)
                        if size > self._s.max_html_bytes:
                            await resp.aclose()
                            return Fetch(status=status, final_url=current, failure="too_large")
                        chunks.append(chunk)
                    body = b"".join(chunks)
                    return Fetch(status=status, final_url=current, html=body.decode(resp.encoding or "utf-8", "replace"))
            except (RequestException, OSError, asyncio.TimeoutError) as e:  # transport: DNS, TLS, reset, timeout (bugs are NOT swallowed)
                return Fetch(failure="transport", error=f"{type(e).__name__}: {str(e)[:160]}")
        return Fetch(failure="cross_host_redirect", error="more than the allowed redirects")


class FlareSolverrRung(Rung):
    """POST {endpoint}/v1 request.get. FlareSolverr wraps the page, so BOTH statuses matter; maxTimeout is
    whole ms; the HTTP client gets 10 s headroom (WelZoLekker); a failure opens the 120 s breaker."""

    name = "flaresolverr"

    def __init__(self, settings: Settings, endpoint: str, breaker: Breaker, client: httpx.AsyncClient | None = None) -> None:
        self._s = settings
        self._endpoint = endpoint.rstrip("/")
        self.breaker = breaker
        self._client = client

    async def fetch(self, url: str, target: Target, *, follow: bool, wait_ms: int, timeout: float) -> Fetch:
        left = self.breaker.remaining()
        if left is not None:
            return Fetch(failure="renderer", error=f"FlareSolverr circuit breaker open for another {left}s")
        payload: dict = {"cmd": "request.get", "url": url, "maxTimeout": max(1, int(timeout * 1000))}
        if wait_ms:
            payload["waitInSeconds"] = max(1, round(wait_ms / 1000))
        try:
            client = self._client or httpx.AsyncClient()
            try:
                r = await client.post(f"{self._endpoint}/v1", json=payload, timeout=timeout + 10)
            finally:
                if self._client is None:
                    await client.aclose()
            if r.status_code != 200:
                raise RuntimeError(f"FlareSolverr answered HTTP {r.status_code}")
            reply = r.json()
        except Exception as e:
            self.breaker.failure()
            return Fetch(failure="renderer", error=f"FlareSolverr unreachable: {type(e).__name__}")

        solution = reply.get("solution") if isinstance(reply, dict) else None
        if not isinstance(reply, dict) or reply.get("status") != "ok" or not isinstance(solution, dict):
            message = str(reply.get("message", "no message")) if isinstance(reply, dict) else "invalid reply"
            if "challenge" in message.lower():  # FlareSolverr reached the shop and lost: the shop's doing
                return Fetch(status=403, final_url=url, html=f"flaresolverr: {message}")
            self.breaker.failure()
            return Fetch(failure="renderer", error=f"FlareSolverr failed: {message[:160]}")
        self.breaker.success()

        status, html = solution.get("status"), solution.get("response")
        final = solution.get("url") if isinstance(solution.get("url"), str) else url
        if not isinstance(status, int) or not isinstance(html, str):
            return Fetch(failure="renderer", error="FlareSolverr returned no usable solution")
        if len(html.encode("utf-8", "ignore")) > self._s.max_html_bytes:
            return Fetch(status=status, final_url=final, failure="too_large")
        if not same_site(target.host, urlsplit(final).hostname or ""):
            return Fetch(status=status, final_url=final, failure="cross_host_redirect", error=f"redirect leaves the shop host (to {urlsplit(final).hostname})")
        if not follow and final.rstrip("/") != url.rstrip("/"):
            return Fetch(status=status, final_url=final, failure="redirect_not_followed", error="the renderer followed a redirect")
        return Fetch(status=status, final_url=final, html=html)


# Adapted from Inktomi PlaywrightStealthBrowser.cs (StealthInitScript). `languages` follows the nl-NL locale
# the context is created with, instead of the donor's en-US.
STEALTH_INIT_SCRIPT = """
Object.defineProperty(navigator, 'webdriver', { get: () => undefined });
Object.defineProperty(navigator, 'languages', { get: () => ['nl-NL', 'nl'] });
Object.defineProperty(navigator, 'plugins', { get: () => [1, 2, 3, 4, 5] });
window.chrome = window.chrome || { runtime: {} };
"""


class ChromiumRung(Rung):
    """Playwright Chromium (Inktomi SPA fallback) with the donor's stealth profile: no `HeadlessChrome` UA,
    AutomationControlled off, `navigator.webdriver` removed (measured: the default headless UA is what
    Akamai refused on ah.nl). Every browser request is vetted too, so an untrusted page cannot make the
    browser call the proxy's internal network."""

    name = "chromium"

    def __init__(self, settings: Settings, policy: TargetPolicy) -> None:
        self._s = settings
        self._policy = policy

    async def fetch(self, url: str, target: Target, *, follow: bool, wait_ms: int, timeout: float) -> Fetch:
        try:
            from playwright.async_api import TimeoutError as PlaywrightTimeout
            from playwright.async_api import async_playwright
        except ImportError:
            return Fetch(failure="renderer", error="playwright is not installed")
        try:
            async with async_playwright() as pw:
                browser = await pw.chromium.launch(
                    headless=True,
                    executable_path=self._s.chromium_path,
                    args=["--no-sandbox", "--disable-dev-shm-usage", "--disable-blink-features=AutomationControlled"],
                )
                try:
                    # Playwright's default UA says HeadlessChrome; derive an ordinary one from the REAL
                    # browser version so UA and engine agree.
                    user_agent = f"Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/{browser.version} Safari/537.36"
                    context = await browser.new_context(locale="nl-NL", user_agent=user_agent, extra_http_headers={"Accept-Language": self._s.accept_language})
                    await context.add_init_script(STEALTH_INIT_SCRIPT)
                    verdicts: dict[str, bool] = {}

                    async def guard(route):
                        parts = urlsplit(route.request.url)
                        if parts.scheme in ("data", "blob", "about"):
                            return await route.continue_()
                        origin = f"{parts.scheme}://{parts.hostname}:{parts.port or (443 if parts.scheme == 'https' else 80)}"
                        if origin not in verdicts:
                            try:
                                await self._policy.vet(route.request.url)
                                verdicts[origin] = True
                            except RequestError:
                                verdicts[origin] = False
                        await (route.continue_() if verdicts[origin] else route.abort("blockedbyclient"))

                    await context.route("**/*", guard)
                    page = await context.new_page()
                    started = time.monotonic()
                    response = await page.goto(url, wait_until="load", timeout=timeout * 1000)
                    # SPA content arrives after `load`: wait for idle, but never longer than the soft cap or
                    # the caller's remaining budget; a chatty site that never idles still yields its DOM.
                    idle_ms = min(self._s.networkidle_soft_ms, max(0.0, (timeout - (time.monotonic() - started)) * 1000 - 500))
                    if idle_ms > 0:
                        try:
                            await page.wait_for_load_state("networkidle", timeout=idle_ms)
                        except PlaywrightTimeout:  # the soft cap passed; take the DOM as it is
                            pass
                    if wait_ms:
                        await page.wait_for_timeout(wait_ms)
                    html = await page.content()
                    final = page.url
                    status = response.status if response else None
                finally:
                    await browser.close()
        except Exception as e:
            return Fetch(failure="renderer", error=f"{type(e).__name__}: {str(e)[:160]}")
        if len(html.encode("utf-8", "ignore")) > self._s.max_html_bytes:
            return Fetch(status=status, final_url=final, failure="too_large")
        if not same_site(target.host, urlsplit(final).hostname or ""):
            return Fetch(status=status, final_url=final, failure="cross_host_redirect", error=f"redirect leaves the shop host (to {urlsplit(final).hostname})")
        return Fetch(status=status, final_url=final, html=html)
