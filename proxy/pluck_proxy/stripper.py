"""Stripping (PROXY_CONTRACT §3, FUNCTIONAL_DESIGN §7). Every mode extracts all JSON-LD and the title.

  none          raw html
  conservative  drop styles, tracker scripts, iframes, cookie banners, nav/footer; KEEP the DOM
                structure so xPaths written against full HTML keep matching
  readability   main content only (readability-lxml)
  xpath         only the fragments the consumer's expressions match; zero matches is NOT an error
"""
from __future__ import annotations

import json
import re
from dataclasses import dataclass, field

import lxml.html
from lxml import etree

from .config import Settings
from .xpath_guard import compile_expression

# Well-known analytics/ad/tag hosts and inline signatures. Not exhaustive by nature; a miss only means a
# tracker script survives in the html. Note: a Magento `dataLayer` definition is data, not a tracker.
TRACKER_SRC = re.compile(
    r"googletagmanager\.com|google-analytics\.com|googleadservices\.com|doubleclick\.net|facebook\.net|"
    r"connect\.facebook|hotjar\.com|clarity\.ms|matomo|piwik|linkedin\.com/insight|snap\.licdn|"
    r"static\.ads-twitter|analytics\.tiktok|criteo|trustpilot|cookiebot|onetrust|usercentrics|cookiefirst|"
    r"klaviyo|segment\.(?:com|io)|mixpanel|optimizely|tracking",
    re.I,
)
TRACKER_INLINE = re.compile(r"\bgtag\s*\(|\bfbq\s*\(|_gaq|\bga\s*\(\s*['\"]create|_paq\.push|hj\s*\(|clarity\s*\(|\bttq\.|\bsnaptr\b|_linkedin_partner_id", re.I)
BANNER = re.compile(r"cookie|consent|gdpr|cmp-|privacy-banner", re.I)
BANNER_TAGS = {"div", "section", "aside", "dialog", "form"}


@dataclass
class Stripped:
    title: str | None
    html: str | None
    json_ld: list[dict]
    fragments: dict[str, list[str]] | None = None
    kept_counts: dict[str, int] | None = None
    error: str | None = None  # e.g. content_too_large (becomes outcome shop_error)


def parse(html: str) -> etree._Element:
    parser = lxml.html.HTMLParser(encoding="utf-8", huge_tree=False)
    root = lxml.html.document_fromstring(html.encode("utf-8", "replace"), parser=parser)
    return root


def extract_json_ld(root: etree._Element) -> list[dict]:
    found: list[dict] = []
    for script in root.xpath('//script[translate(@type,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="application/ld+json"]'):
        text = (script.text or "").strip()
        if not text:
            continue
        try:
            data = json.loads(text, strict=False)
        except ValueError:
            continue
        for item in data if isinstance(data, list) else [data]:
            if isinstance(item, dict):
                found.append(item)
    return found


def _title(root: etree._Element) -> str | None:
    nodes = root.xpath("//title")
    text = (nodes[0].text_content() if nodes else "").strip()
    return text or None


def _drop(el: etree._Element) -> None:
    parent = el.getparent()
    if parent is None:
        return
    # keep the tail text so surrounding text nodes survive
    tail = el.tail
    if tail:
        prev = el.getprevious()
        if prev is not None:
            prev.tail = (prev.tail or "") + tail
        else:
            parent.text = (parent.text or "") + tail
    parent.remove(el)


def conservative(root: etree._Element, preserve_scripts: bool) -> None:
    for el in list(root.iter("style")) + list(root.iter("iframe")):
        _drop(el)
    for link in list(root.xpath('//link[translate(@rel,"STYLESHEET","stylesheet")="stylesheet"]')):
        _drop(link)
    for script in list(root.iter("script")):
        kind = (script.get("type") or "").lower()
        if kind == "application/ld+json":
            continue
        src = script.get("src")
        inline = script.text or ""
        tracker = bool(src and TRACKER_SRC.search(src)) or bool(TRACKER_INLINE.search(inline))
        if tracker or src or not preserve_scripts:
            _drop(script)  # external scripts are never kept; inline ones only with preserve_scripts
    for el in list(root.iter("nav")) + list(root.iter("footer")):
        _drop(el)
    for el in list(root.iter(*BANNER_TAGS)):
        if el.getparent() is None or el.getparent() is root.getparent():
            continue
        ident = f"{el.get('id', '')} {el.get('class', '')}"
        if BANNER.search(ident) and not el.xpath('.//h1 | .//main | .//*[contains(@itemtype,"Product")]'):
            _drop(el)
    for comment in list(root.xpath("//comment()")):
        _drop(comment)


def _inner_html(node: etree._Element) -> str:
    out = _escape(node.text or "")
    for child in node:
        out += lxml.html.tostring(child, encoding="unicode", with_tail=True)
    return out


def _escape(text: str) -> str:
    return text.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;")


def run(html: str, mode: str, keep_xpaths: dict[str, str], preserve_scripts: bool, s: Settings) -> Stripped:
    # Compile first: an invalid expression is a consumer bug (400), never a scrape failure.
    compiled = {name: compile_expression(name, expr) for name, expr in keep_xpaths.items()} if mode == "xpath" else {}

    root = parse(html)
    title = _title(root)
    json_ld = extract_json_ld(root)

    if mode == "none":
        return Stripped(title, html, json_ld)

    if mode == "readability":
        from readability import Document

        try:
            # clean boilerplate first so the scorer never sees nav/footer/banners
            conservative(root, preserve_scripts=False)
            return Stripped(title, Document(lxml.html.tostring(root, encoding="unicode")).summary(html_partial=True), json_ld)
        except Exception:
            # readability found no article: fall back to the conservative cut rather than empty
            return Stripped(title, lxml.html.tostring(root, encoding="unicode"), json_ld)

    if mode == "conservative":
        conservative(root, preserve_scripts)
        return Stripped(title, lxml.html.tostring(root, encoding="unicode"), json_ld)

    # xpath
    fragments: dict[str, list[str]] = {}
    counts: dict[str, int] = {}
    total = 0
    for name, xp in compiled.items():
        try:
            result = xp(root)
        except etree.XPathError as e:
            from .errors import RequestError

            raise RequestError("invalid_xpath", f"keep_xpaths['{name}']: {e}") from None
        matches = result if isinstance(result, list) else [result]
        nodes: list[str] = []
        for m in matches[: s.max_fragment_nodes]:
            if isinstance(m, etree._Element):
                text = _inner_html(m)
            else:  # attribute / text / number / boolean result
                text = str(m)
            if len(text.encode("utf-8")) > s.max_fragment_node_bytes:
                text = text.encode("utf-8")[: s.max_fragment_node_bytes].decode("utf-8", "ignore") + "<!--truncated-->"
            total += len(text.encode("utf-8"))
            nodes.append(text)
        fragments[name] = nodes
        counts[name] = len(nodes)
    if total > s.max_fragments_bytes:
        return Stripped(title, None, json_ld, error="content_too_large")
    return Stripped(title, None, json_ld, fragments, counts)
