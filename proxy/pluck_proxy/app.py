"""FastAPI app: POST /v1/render (PROXY_CONTRACT v1). Logs url host-free of content: request_id,
consumer, host, outcome, renderer, elapsed — never bodies, never secrets."""
from __future__ import annotations

import asyncio
import logging
import time
import uuid
from collections import defaultdict, deque

from fastapi import FastAPI, Request
from fastapi.responses import JSONResponse

from . import CONTRACT_VERSION, PROXY_VERSION, STRIPPER_VERSION, stripper
from .cache import Cache, key as cache_key
from .chain import Chain
from .config import Settings
from .errors import RequestError
from .models import RenderRequest
from .politeness import Breaker, Politeness
from .renderers import ChromiumRung, FlareSolverrRung, PlainRung, Rung
from .target import PublicTargetPolicy, TargetPolicy

log = logging.getLogger("pluck.proxy")


def create_app(
    settings: Settings | None = None,
    *,
    policy: TargetPolicy | None = None,
    rungs: list[Rung] | None = None,
    politeness: Politeness | None = None,
    sleeper=asyncio.sleep,
    clock=time.monotonic,
) -> FastAPI:
    s = settings or Settings.from_env()
    policy = policy or PublicTargetPolicy()
    pol = politeness or Politeness(s, clock)
    if rungs is None:
        rungs = [PlainRung(s, policy)]
        if s.flaresolverr_url:
            rungs.append(FlareSolverrRung(s, s.flaresolverr_url, Breaker(s.breaker_seconds, clock)))
        rungs.append(ChromiumRung(s, policy))
    chain = Chain(rungs, pol, s, sleeper)
    cache = Cache(s.cache_max_entries, clock)
    sem = asyncio.Semaphore(s.max_concurrency)
    windows: dict[str, deque[float]] = defaultdict(deque)

    app = FastAPI(title="pluck-proxy", version=PROXY_VERSION, docs_url=None, redoc_url=None, openapi_url=None)

    def err(status: int, code: str, message: str, request_id: str, **extra) -> JSONResponse:
        body = {"contract_version": CONTRACT_VERSION, "request_id": request_id, "error": code, "message": message, **extra}
        headers = {"Retry-After": str(extra["retry_after_seconds"])} if extra.get("retry_after_seconds") is not None else None
        return JSONResponse(body, status_code=status, headers=headers)

    @app.get("/healthz")
    async def healthz():
        return {"status": "ok", "version": PROXY_VERSION}

    @app.post("/v1/render")
    async def render(request: Request):
        request_id = uuid.uuid4().hex
        started = time.monotonic()
        consumer = (request.headers.get("x-pluck-consumer") or "default")[:64]

        declared = request.headers.get("content-length", "")
        if declared.isdigit() and int(declared) > s.max_request_bytes:
            return err(413, "request_too_large", "The request body is over the cap.", request_id)
        raw = await request.body()
        if len(raw) > s.max_request_bytes:
            return err(413, "request_too_large", "The request body is over the cap.", request_id)

        if s.rate_limit_per_minute > 0:
            window, now = windows[consumer], clock()
            while window and window[0] <= now - 60:
                window.popleft()
            if len(window) >= s.rate_limit_per_minute:
                wait = max(1, int(window[0] + 60 - now) + 1)
                return err(429, "rate_limited", "This consumer is over its budget.", request_id, retry_after_seconds=wait)
            window.append(now)

        try:
            import json

            try:
                body = json.loads(raw)
            except ValueError:
                raise RequestError("invalid_options", "The body is not valid JSON.") from None
            req = RenderRequest.parse(body)
            from .xpath_guard import compile_expression

            for name, expr in req.keep_xpaths.items():
                compile_expression(name, expr)
            target = await policy.vet(req.url)
        except RequestError as e:
            return err(400, e.code, e.message, request_id)

        ck = cache_key(req.url, req.strip, req.keep_xpaths, req.preserve_scripts, req.follow_redirects)
        if req.ttl_seconds > 0:
            hit = cache.get(ck)
            if hit is not None:
                out = {**hit, "request_id": request_id, "meta": {**hit["meta"], "cache": "hit", "elapsed_ms": int((time.monotonic() - started) * 1000)}}
                log.info("render consumer=%s host=%s outcome=fetched renderer=%s cache=hit", consumer, target.host, out["meta"].get("renderer"))
                return JSONResponse(out)

        async with sem:
            try:
                result = await asyncio.wait_for(
                    chain.run(req.url, target, follow=req.follow_redirects, wait_ms=req.wait_ms, timeout_s=req.max_timeout_ms / 1000),
                    timeout=req.max_timeout_ms / 1000,
                )
            except asyncio.TimeoutError:
                log.info("render consumer=%s host=%s outcome=timeout", consumer, target.host)
                return err(504, "timeout", "max_timeout_ms exceeded.", request_id)

        meta = {
            "proxy_version": PROXY_VERSION,
            "stripper_version": STRIPPER_VERSION,
            "renderer": result.renderer,
            "elapsed_ms": 0,
            "cache": "miss" if req.ttl_seconds > 0 else "bypass",
            "attempts": result.attempts,
        }
        envelope: dict = {
            "contract_version": CONTRACT_VERSION,
            "request_id": request_id,
            "outcome": result.outcome,
            "shop_status": result.status,
            "final_url": result.final_url,
            "title": None,
            "json_ld": [],
            "retry_after_seconds": result.retry_after,
            "meta": meta,
        }
        if result.error:
            envelope["error"] = result.error
        if result.block_signal:
            envelope["block_signal"] = result.block_signal

        status_code = 200
        if result.outcome == "upstream_error":
            status_code = 502
        elif result.outcome == "fetched":
            try:
                stripped = await asyncio.get_running_loop().run_in_executor(
                    None, stripper.run, result.html or "", req.strip, req.keep_xpaths, req.preserve_scripts, s
                )
            except RequestError as e:
                return err(400, e.code, e.message, request_id)
            except Exception as e:
                envelope["outcome"] = "shop_error"
                envelope["error"] = f"unparseable: {type(e).__name__}"
            else:
                envelope["title"] = stripped.title
                envelope["json_ld"] = stripped.json_ld
                if stripped.error:
                    envelope["outcome"] = "shop_error"
                    envelope["error"] = stripped.error
                elif stripped.html is not None and len(stripped.html.encode("utf-8")) > s.max_html_bytes:
                    envelope["outcome"] = "shop_error"
                    envelope["error"] = "content_too_large"
                elif req.strip == "xpath":
                    envelope["fragments"] = stripped.fragments
                    envelope["kept_counts"] = stripped.kept_counts
                else:
                    envelope["html"] = stripped.html

        meta["elapsed_ms"] = int((time.monotonic() - started) * 1000)
        log.info("render consumer=%s host=%s outcome=%s renderer=%s elapsed_ms=%d", consumer, target.host, envelope["outcome"], result.renderer, meta["elapsed_ms"])
        if envelope["outcome"] == "fetched" and req.ttl_seconds > 0:
            cache.put(ck, envelope, req.ttl_seconds)
        return JSONResponse(envelope, status_code=status_code)

    return app


def app_from_env() -> FastAPI:
    return create_app(Settings.from_env())
