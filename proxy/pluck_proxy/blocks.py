"""Port of Inktomi BlockDetection.cs (and the PHP Pluck\\Support\\BlockDetector): is this response a
bot-protection refusal rather than the document it claims to be?"""
from __future__ import annotations

SCAN_LIMIT = 65_536
BLOCKING_STATUSES = {403, 429, 503, 520, 521, 522, 523, 524, 525, 526, 527}
ABSENT_STATUSES = {404, 410}
MARKERS = (
    "just a moment", "cf-browser-verification", "cf_chl_", "__cf_chl", "challenge-platform",
    "checking your browser before accessing", "attention required! | cloudflare",
    "enable javascript and cookies to continue", "ddos-guard", "_incapsula_resource",
    "request unsuccessful. incapsula incident", "px-captcha", "perimeterx",
    "sucuri website firewall", "access denied", "cf-mitigated", "cloudflare ray id",
)


def evaluate(status: int, body: str | None, genuine_document: bool = False) -> str | None:
    """Returns the block signal (marker or "HTTP <n>"), or None when not blocked.

    404/410 settle "no document" by status alone; a marker beats a bare status (it names the
    vendor); only the first 64 KB are scanned. `genuine_document` skips marker scanning for a page
    already proven real (known limit of the donor: a real page that merely mentions a marker)."""
    if status in ABSENT_STATUSES:
        return None
    if not genuine_document:
        scanned = (body or "")[:SCAN_LIMIT].lower()
        for marker in MARKERS:
            if marker in scanned:
                return marker
    if status in BLOCKING_STATUSES:
        return f"HTTP {status}"
    return None
