from __future__ import annotations

import pytest

from pluck_proxy import blocks, signals
from pluck_proxy.errors import RequestError
from pluck_proxy.politeness import Breaker, Politeness
from pluck_proxy.config import Settings
from pluck_proxy.target import is_public_ip
from pluck_proxy.xpath_guard import compile_expression


@pytest.mark.parametrize("status,body,expected", [
    (200, "<p>Magnesium</p>", None),
    (403, "<TITLE>Access Denied</TITLE>", "access denied"),
    (200, "<title>Just a moment...</title>", "just a moment"),
    (403, "Forbidden", "HTTP 403"),
    (429, "", "HTTP 429"),
    (522, "x", "HTTP 522"),
    (404, "<h1>Access Denied</h1>", None),
    (410, "Just a moment", None),
    (500, "oops", None),
    (401, "login", None),
])
def test_block_detection_matches_the_donor(status, body, expected):
    assert blocks.evaluate(status, body) == expected


def test_block_scan_is_limited_to_64k_and_genuine_documents_skip_markers():
    assert blocks.evaluate(200, "a" * 65_536 + "access denied") is None
    assert blocks.evaluate(200, "Our access denied policy", genuine_document=True) is None
    assert blocks.evaluate(403, "Our access denied policy", genuine_document=True) == "HTTP 403"


@pytest.mark.parametrize("ip", ["127.0.0.1", "10.1.1.1", "172.16.0.1", "192.168.0.1", "169.254.169.254", "100.64.0.1", "0.0.0.0", "224.0.0.1", "::1", "fe80::1", "fc00::1", "::ffff:10.0.0.1", "garbage"])
def test_non_public_ips(ip):
    assert not is_public_ip(ip)


@pytest.mark.parametrize("ip", ["93.184.216.34", "8.8.8.8", "100.63.255.255", "2606:4700:4700::1111", "::ffff:8.8.8.8"])
def test_public_ips(ip):
    assert is_public_ip(ip)


@pytest.mark.parametrize("expr", ["//a", "//span[@itemprop='price']", "//li[position() < 3]", "normalize-space(//h1)", "//a/@href", "//*[contains(@class,'p') and not(@hidden)]", "count(//li)"])
def test_allowed_expressions_compile(expr):
    compile_expression("x", expr)


@pytest.mark.parametrize("expr", ["//a[", "//a[$v]", "document('x')", "//a[re:test(.,'x')]", "//a[str:tokenize(.)]", "//a[sneaky()]", "//a[current()]", "//a[format-number(.,'#')]"])
def test_rejected_expressions(expr):
    with pytest.raises(RequestError) as e:
        compile_expression("x", expr)
    assert e.value.code == "invalid_xpath"


def test_shell_detection():
    shell = '<html><body><div id="root"></div><noscript>JavaScript is required</noscript></body></html>'
    product = '<script type="application/ld+json">{"@type":"Product"}</script><div id="root"></div>'
    long_page = "<body>" + "Wij verkopen vitamines. " * 40 + "Schakel JavaScript in.</body>"

    assert signals.looks_like_shell(shell)
    assert not signals.looks_like_shell(product)
    assert not signals.looks_like_shell(long_page)


async def test_politeness_slots_and_cooldown_with_a_fake_clock():
    now = [100.0]
    p = Politeness(Settings(), lambda: now[0])

    assert await p.reserve("a.test") == 0
    assert await p.reserve("a.test") == pytest.approx(3.0)
    assert await p.reserve("b.test") == pytest.approx(1.0 + 0, abs=3.1)
    now[0] += 1000
    assert await p.reserve("a.test") == 0

    p.mark_blocked("a.test")
    assert p.cooldown_remaining("a.test") == 600 and p.cooldown_remaining("b.test") is None
    now[0] += 600
    assert p.cooldown_remaining("a.test") is None


async def test_open_food_facts_uses_2_1_seconds():
    p = Politeness(Settings(), lambda: 0.0)
    await p.reserve("world.openfoodfacts.org")

    assert await p.reserve("world.openfoodfacts.org") == pytest.approx(2.1)


def test_breaker_opens_for_120_seconds_and_closes_on_success():
    now = [0.0]
    b = Breaker(120, lambda: now[0])
    assert b.remaining() is None
    b.failure()
    assert b.remaining() == 120
    now[0] = 120
    assert b.remaining() is None
    b.failure(); b.success()
    assert b.remaining() is None


def test_price_text_inside_a_plain_js_bundle_is_not_product_data():
    spa = '<html><body><div id="root"></div><script>el.innerHTML=\'<span itemprop="price">\'; var o={"price":1}</script></body></html>'

    assert not signals.has_product_data(spa)
    assert signals.looks_like_shell(spa)


def test_price_inside_json_state_or_markup_is_product_data():
    assert signals.has_product_data('<script type="application/json" id="__NEXT_DATA__">{"props":{"price":12.5}}</script>')
    assert signals.has_product_data('<span itemprop="price" content="3">€ 3</span>')
    assert signals.has_product_data('<script type="application/ld+json">{"@type":"Product"}</script>')
