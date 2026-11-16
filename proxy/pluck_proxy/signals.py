"""Evidence about a fetched page (port of the PHP ContentSignals): does it look like a server-rendered
product page or an empty JavaScript shell? Decides whether the chain escalates a 200."""
from __future__ import annotations

import re

SHELL_TEXT_CHARS = 500  # same constant as the PHP probe

_LD = re.compile(r"<script[^>]+type=[\"']application/ld\+json[\"'][^>]*>(.*?)</script>", re.I | re.S)
_PRODUCT = re.compile(r'"@type"\s*:\s*(\[[^\]]*)?"Product"', re.I)
_PRICE = re.compile(r"itemprop=[\"']price[\"']|property=[\"'](?:product|og):price|\"priceCurrency\"|\"price\"\s*:", re.I)
_MOUNT = re.compile(r"<div[^>]+id=[\"'](?:root|app|__next|__nuxt|svelte)[\"']|data-reactroot|ng-app|<app-root", re.I)
_JS_NOTICE = re.compile(r"javascript\s+(?:is\s+)?(?:required|vereist|nodig)|(?:enable|schakel)\s+javascript", re.I)
_CODE = re.compile(r"<script\b.*?</script>|<style\b.*?</style>|<noscript\b.*?</noscript>", re.I | re.S)
_CODE_KEEP_NOSCRIPT = re.compile(r"<script\b.*?</script>|<style\b.*?</style>", re.I | re.S)
_TAGS = re.compile(r"<[^>]+>")
_WS = re.compile(r"\s+")


def has_json_ld_product(html: str) -> bool:
    return any(_PRODUCT.search(b) for b in _LD.findall(html))


_SCRIPT = re.compile(r"<script\b([^>]*)>(.*?)</script>", re.I | re.S)
_JSON_TYPE = re.compile(r"type=[\"'][^\"']*json[^\"']*[\"']", re.I)
_JSON_PRICE = re.compile(r"\"price\"\s*:|\"priceCurrency\"", re.I)


def has_product_data(html: str) -> bool:
    """JSON-LD Product, price markup in the document, or price inside a JSON data script.

    Plain JavaScript bodies are NOT searched: an SPA bundle that merely contains the text
    `itemprop="price"` (as a string it will render later) is a shell, not product data."""
    if has_json_ld_product(html):
        return True
    json_scripts = [body for attrs, body in _SCRIPT.findall(html) if _JSON_TYPE.search(attrs)]
    if any(_JSON_PRICE.search(b) for b in json_scripts):
        return True
    return bool(_PRICE.search(_SCRIPT.sub("", html)))


def visible_text_chars(html: str) -> int:
    return len(_WS.sub(" ", _TAGS.sub(" ", _CODE.sub("", html))).strip())


def looks_like_shell(html: str) -> bool:
    if has_product_data(html) or visible_text_chars(html) >= SHELL_TEXT_CHARS:
        return False
    notice = bool(_JS_NOTICE.search(_TAGS.sub(" ", _CODE_KEEP_NOSCRIPT.sub("", html))))
    return bool(_MOUNT.search(html)) or notice
