"""Request validation (PROXY_CONTRACT §2). Hand-rolled so every failure maps to the contract's
own error code instead of a framework-shaped 422."""
from __future__ import annotations

import re
from dataclasses import dataclass, field
from typing import Any
from urllib.parse import urlsplit

from .errors import RequestError

STRIP_MODES = {"none", "conservative", "readability", "xpath"}
_NAME = re.compile(r"^[a-z0-9_]{1,32}$")
_KNOWN = {"url", "strip", "keep_xpaths", "preserve_scripts", "max_timeout_ms", "wait_ms", "ttl_seconds", "follow_redirects"}


@dataclass(frozen=True)
class RenderRequest:
    url: str
    strip: str = "conservative"
    keep_xpaths: dict[str, str] = field(default_factory=dict)
    preserve_scripts: bool = False
    max_timeout_ms: int = 60_000
    wait_ms: int = 0
    ttl_seconds: int = 0
    follow_redirects: bool = True

    @classmethod
    def parse(cls, body: Any) -> "RenderRequest":
        if not isinstance(body, dict):
            raise RequestError("invalid_options", "The body must be a JSON object.")
        unknown = set(body) - _KNOWN
        if unknown:
            raise RequestError("invalid_options", f"Unknown field(s): {', '.join(sorted(map(str, unknown)))}.")

        url = body.get("url")
        if not isinstance(url, str) or not url or len(url) > 2048:
            raise RequestError("invalid_url", "url must be a string of 1-2048 characters.")
        try:
            parts = urlsplit(url)
            _ = parts.port
        except ValueError:
            raise RequestError("invalid_url", "The URL is malformed.") from None
        if parts.scheme.lower() not in ("http", "https") or not parts.hostname:
            raise RequestError("invalid_url", "Only absolute http(s) URLs can be rendered.")
        if parts.username is not None or parts.password is not None:
            raise RequestError("invalid_url", "Credentials in the URL are not allowed.")

        strip = body.get("strip", "conservative")
        if strip not in STRIP_MODES:
            raise RequestError("invalid_options", f"strip must be one of {sorted(STRIP_MODES)}.")

        preserve = _bool(body, "preserve_scripts", False)
        follow = _bool(body, "follow_redirects", True)
        timeout = _int(body, "max_timeout_ms", 60_000, 1, 90_000)
        wait = _int(body, "wait_ms", 0, 0, 5_000)
        ttl = _int(body, "ttl_seconds", 0, 0, 86_400)

        if preserve and strip not in ("conservative", "none"):
            raise RequestError("invalid_options", "preserve_scripts is only valid with conservative or none.")

        keep = body.get("keep_xpaths")
        if strip == "xpath":
            if not isinstance(keep, dict) or not keep:
                raise RequestError("invalid_xpath", "strip=xpath requires keep_xpaths.")
            if len(keep) > 16:
                raise RequestError("too_many_xpaths", "At most 16 keep_xpaths are allowed.")
            for name, expr in keep.items():
                if not isinstance(name, str) or not _NAME.match(name):
                    raise RequestError("invalid_xpath", "keep_xpaths names must match [a-z0-9_]{1,32}.")
                if not isinstance(expr, str) or not expr.strip() or len(expr) > 500:
                    raise RequestError("invalid_xpath", f"keep_xpaths['{name}'] must be 1-500 characters.")
            keep_map = dict(keep)
        else:
            if keep:
                raise RequestError("invalid_options", "keep_xpaths is only valid with strip=xpath.")
            keep_map = {}

        return cls(url, strip, keep_map, preserve, timeout, wait, ttl, follow)


def _bool(body: dict, key: str, default: bool) -> bool:
    value = body.get(key, default)
    if not isinstance(value, bool):
        raise RequestError("invalid_options", f"{key} must be a boolean.")
    return value


def _int(body: dict, key: str, default: int, low: int, high: int) -> int:
    value = body.get(key, default)
    if isinstance(value, bool) or not isinstance(value, int) or not (low <= value <= high):
        raise RequestError("invalid_options", f"{key} must be an integer {low}-{high}.")
    return value
