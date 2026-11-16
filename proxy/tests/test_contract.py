"""Contract tests (PROXY_CONTRACT v1) against the real app, a real HTTP shop on a socket, real lxml,
real curl_cffi, and — where the binary exists — real Chromium. Only FlareSolverr is a fake (no instance)."""
from __future__ import annotations

import os

import httpx
import pytest
from curl_cffi import CurlOpt  # noqa: F401  (import check: the impersonating client is installed)

from conftest import CHROME, render
from pluck_proxy.config import Settings
from pluck_proxy.politeness import Breaker
from pluck_proxy.renderers import ChromiumRung, FlareSolverrRung, PlainRung




# ---------- strip modes ----------

async def test_conservative_cleans_but_keeps_structure_and_json_ld(client, shop):
    r = await render(client, f"{shop.base}/plain")
    body = r.json()

    assert r.status_code == 200 and body["outcome"] == "fetched"
    assert body["contract_version"] == 1 and body["shop_status"] == 200 and body["title"] == "Magnesium 400 mg"
    html = body["html"]
    for gone in ("<style", "<iframe", "googletagmanager", "gtag(", "<nav", "<footer", "cookie-consent-banner", "stylesheet", "app.js", "appState"):
        assert gone not in html, gone
    # structure and data survive: a stored xPath written against full HTML must still match
    assert 'class="product"' in html and 'itemprop="price"' in html and 'data-sku="MG-400"' in html
    assert 'application/ld+json' in html and 'og:title' in html and 'name="description"' in html
    assert [d["@type"] for d in body["json_ld"]] == ["Product", "BreadcrumbList", "Organization"]
    assert body["json_ld"][0]["offers"]["price"] == "12.95"
    assert body["meta"]["renderer"] == "plain" and body["meta"]["cache"] == "bypass" and body["meta"]["attempts"] == 1
    assert "fragments" not in body and "kept_counts" not in body


async def test_preserve_scripts_keeps_inline_data_but_never_trackers_or_external(client, shop):
    kept = (await render(client, f"{shop.base}/magento", preserve_scripts=True)).json()["html"]
    dropped = (await render(client, f"{shop.base}/magento")).json()["html"]

    assert "dataLayer" in kept and '"Vit C"' in kept
    assert "gtag(" not in kept and "hotjar" not in kept
    assert "dataLayer" not in dropped


async def test_none_returns_the_raw_page(client, shop):
    body = (await render(client, f"{shop.base}/plain", strip="none")).json()

    assert "<nav" in body["html"] and "gtag(" in body["html"] and "<style" in body["html"]
    assert body["json_ld"], "json_ld is extracted in every mode"


async def test_readability_returns_main_content(client, shop):
    body = (await render(client, f"{shop.base}/plain", strip="readability")).json()

    assert body["outcome"] == "fetched" and "Magnesium" in body["html"]
    assert "<nav" not in body["html"] and "cookie" not in body["html"].lower()


async def test_xpath_returns_only_fragments_and_counts(client, shop):
    body = (await render(client, f"{shop.base}/plain", strip="xpath", keep_xpaths={
        "price": '//span[@itemprop="price"]',
        "title": "//h1",
        "ingredients": '//ul[@class="ingredients"]/li',
        "sku": '//a[@class="buy"]/@data-sku',
    })).json()

    assert body["outcome"] == "fetched"
    assert "html" not in body
    assert body["fragments"]["price"] == ["€ 12,95"]
    assert body["fragments"]["title"] == ["Magnesium 400 mg"]
    assert body["fragments"]["ingredients"] == ["Magnesiumcitraat", "Vitamine B6"]
    assert body["fragments"]["sku"] == ["MG-400"]
    assert body["kept_counts"] == {"price": 1, "title": 1, "ingredients": 2, "sku": 1}
    assert body["json_ld"] and body["title"] == "Magnesium 400 mg", "json_ld/title always present"


async def test_xpath_zero_matches_is_still_fetched_with_zero_counts(client, shop):
    r = await render(client, f"{shop.base}/plain", strip="xpath", keep_xpaths={"price": "//span[@class='does-not-exist']", "title": "//h1"})
    body = r.json()

    assert r.status_code == 200 and body["outcome"] == "fetched"
    assert body["kept_counts"] == {"price": 0, "title": 1}
    assert body["fragments"]["price"] == []


async def test_xpath_caps_nodes_and_truncates_overlong_nodes(client, shop):
    body = (await render(client, f"{shop.base}/many", strip="xpath", keep_xpaths={"rows": "//li[@class='row']", "huge": "//div[@class='huge']"})).json()

    assert body["outcome"] == "fetched"
    assert len(body["fragments"]["rows"]) == 50 and body["kept_counts"]["rows"] == 50
    huge = body["fragments"]["huge"][0]
    assert huge.endswith("<!--truncated-->") and len(huge.encode()) <= 64 * 1024 + len("<!--truncated-->")


# ---------- validation (400) ----------

@pytest.mark.parametrize("body,code", [
    ({"url": "ftp://x.test/"}, "invalid_url"),
    ({"url": "https://u:p@x.test/"}, "invalid_url"),
    ({"url": "/relative"}, "invalid_url"),
    ({"url": "https://x.test/", "strip": "magic"}, "invalid_options"),
    ({"url": "https://x.test/", "max_timeout_ms": 90_001}, "invalid_options"),
    ({"url": "https://x.test/", "wait_ms": 5_001}, "invalid_options"),
    ({"url": "https://x.test/", "ttl_seconds": 86_401}, "invalid_options"),
    ({"url": "https://x.test/", "bogus": 1}, "invalid_options"),
    ({"url": "https://x.test/", "strip": "xpath", "preserve_scripts": True, "keep_xpaths": {"a": "//a"}}, "invalid_options"),
    ({"url": "https://x.test/", "keep_xpaths": {"a": "//a"}}, "invalid_options"),
    ({"url": "https://x.test/", "strip": "xpath"}, "invalid_xpath"),
    ({"url": "https://x.test/", "strip": "xpath", "keep_xpaths": {"Bad Name": "//a"}}, "invalid_xpath"),
    ({"url": "https://x.test/", "strip": "xpath", "keep_xpaths": {"a": "//" + "a" * 500}}, "invalid_xpath"),
    ({"url": "https://x.test/", "strip": "xpath", "keep_xpaths": {f"n{i}": "//a" for i in range(17)}}, "too_many_xpaths"),
    ({"url": "https://x.test/", "strip": "xpath", "keep_xpaths": {"a": "//a["}}, "invalid_xpath"),
    ({"url": "https://x.test/", "strip": "xpath", "keep_xpaths": {"a": "//a[$x]"}}, "invalid_xpath"),
    ({"url": "https://x.test/", "strip": "xpath", "keep_xpaths": {"a": "document('file:///etc/passwd')"}}, "invalid_xpath"),
    ({"url": "https://x.test/", "strip": "xpath", "keep_xpaths": {"a": "//a[re:test(., 'x')]"}}, "invalid_xpath"),
    ({"url": "https://x.test/", "strip": "xpath", "keep_xpaths": {"a": "//a[sneaky(.)]"}}, "invalid_xpath"),
])
async def test_validation_failures_are_400_with_the_contract_code(client, body, code):
    r = await client.post("/v1/render", json=body)

    assert r.status_code == 400 and r.json()["error"] == code
    assert r.json()["contract_version"] == 1 and r.json()["request_id"]


async def test_function_names_inside_string_literals_are_not_function_calls(client, shop):
    r = await render(client, f"{shop.base}/plain", strip="xpath", keep_xpaths={"a": "//span[contains(@class, 'document(')]"})

    assert r.status_code == 200 and r.json()["kept_counts"] == {"a": 0}


async def test_non_json_body_is_a_400(client):
    r = await client.post("/v1/render", content=b"not json", headers={"content-type": "application/json"})

    assert r.status_code == 400


async def test_oversized_request_is_413(client):
    r = await client.post("/v1/render", content=b"x" * (32 * 1024 + 1), headers={"content-type": "application/json"})

    assert r.status_code == 413


# ---------- target validation ----------

@pytest.mark.parametrize("url", [
    "http://127.0.0.1/", "http://localhost/", "http://10.0.0.5/admin", "http://192.168.1.1/",
    "http://169.254.169.254/latest/meta-data", "http://100.64.0.1/", "http://[::1]/", "http://[::ffff:127.0.0.1]/",
    "http://db.internal/", "http://0.0.0.0/",
])
async def test_private_targets_are_refused_before_any_request(make_app, url):
    from pluck_proxy.target import PublicTargetPolicy

    async with make_app(policy=PublicTargetPolicy()) as c:
        r = await c.post("/v1/render", json={"url": url})

    assert r.status_code == 400 and r.json()["error"] == "private_target"


async def test_hostname_resolving_privately_is_refused(make_app, monkeypatch):
    from pluck_proxy.target import PublicTargetPolicy

    async def fake(host, port):
        return ["93.184.216.34", "10.0.0.9"]

    monkeypatch.setattr(PublicTargetPolicy, "_resolve", staticmethod(fake))
    async with make_app(policy=PublicTargetPolicy()) as c:
        r = await c.post("/v1/render", json={"url": "https://rebind.example/"})

    assert r.status_code == 400 and r.json()["error"] == "private_target"


# ---------- outcomes ----------

async def test_blocked_403_is_explicit_has_no_content_and_starts_the_cooldown(client, shop):
    first = (await render(client, f"{shop.base}/denied")).json()
    hits = shop.hits["/denied"]
    second = (await render(client, f"{shop.base}/denied")).json()

    assert first["outcome"] == "blocked" and first["shop_status"] == 403
    assert first["retry_after_seconds"] == 600 and first["block_signal"] == "access denied"
    assert "html" not in first and "fragments" not in first and first["json_ld"] == []
    assert second["outcome"] == "blocked" and second["block_signal"] == "cooldown"
    assert 0 < second["retry_after_seconds"] <= 600
    assert shop.hits["/denied"] == hits, "a domain in cooldown is not touched"


async def test_interstitial_served_as_200_is_blocked_not_data(client, shop):
    body = (await render(client, f"{shop.base}/challenge200", strip="xpath", keep_xpaths={"t": "//title"})).json()

    assert body["outcome"] == "blocked" and "fragments" not in body


async def test_cooldown_is_per_domain(make_app, shop):
    async with make_app() as c:
        await render(c, f"{shop.base}/denied")
        other = (await render(c, f"http://localhost:{shop.server_address[1]}/plain")).json()

    assert other["outcome"] == "fetched"


@pytest.mark.parametrize("path,status", [("/gone", 404), ("/boom", 500)])
async def test_shop_errors_are_not_blocks_and_stop_the_chain(client, shop, path, status):
    body = (await render(client, f"{shop.base}{path}")).json()

    assert body["outcome"] == "shop_error" and body["shop_status"] == status
    assert body["meta"]["attempts"] == 1
    assert "html" not in body


async def test_a_404_with_access_denied_wording_is_still_just_a_404(client, shop):
    assert (await render(client, f"{shop.base}/gone")).json()["outcome"] == "shop_error"
    assert (await render(client, f"{shop.base}/plain")).json()["outcome"] == "fetched", "no cooldown was started"


async def test_oversized_page_is_shop_error_content_too_large(client, shop):
    r = await render(client, f"{shop.base}/big")

    assert r.json()["outcome"] == "shop_error" and r.json()["error"] == "content_too_large"


async def test_oversized_page_without_content_length_is_cut_mid_transfer(client, shop):
    body = (await render(client, f"{shop.base}/big-stream")).json()

    assert body["outcome"] == "shop_error" and body["error"] == "content_too_large"


async def test_same_host_redirect_followed_cross_host_refused_and_follow_false_respected(client, shop):
    ok = (await render(client, f"{shop.base}/redirect")).json()
    out = (await render(client, f"{shop.base}/redirect-out")).json()
    stay = (await render(client, f"{shop.base}/redirect", follow_redirects=False)).json()

    assert ok["outcome"] == "fetched" and ok["final_url"].endswith("/plain")
    assert out["outcome"] == "shop_error" and "cross_host_redirect" in out["error"]
    assert stay["outcome"] == "shop_error" and stay["shop_status"] == 302


async def test_unreachable_shop_is_shop_error_not_empty(make_app):
    async with make_app(rungs=[PlainRung(Settings(), __import__("conftest").AnyPolicy())]) as c:
        body = (await render(c, "http://127.0.0.1:1/x")).json()

    assert body["outcome"] == "shop_error" and body["error"].startswith("transport:")


async def test_timeout_is_504(make_app, shop):
    async with make_app() as c:
        r = await render(c, f"{shop.base}/slow", max_timeout_ms=500)

    assert r.status_code == 504 and r.json()["error"] == "timeout"


# ---------- escalation: FlareSolverr (fake) and Chromium (real) ----------

def _fs_client(handler):
    return httpx.AsyncClient(transport=httpx.MockTransport(handler))


def _fs_app(make_app, handler, settings=None):
    from conftest import AnyPolicy

    s = settings or Settings()
    breaker = Breaker(120)
    rungs = [PlainRung(s, AnyPolicy()), FlareSolverrRung(s, "http://flaresolverr:8191", breaker, _fs_client(handler))]
    return make_app(settings=s, rungs=rungs), breaker


async def test_block_escalates_to_flaresolverr_and_reports_the_renderer(make_app, shop):
    sent = []

    def handler(request):
        import json

        sent.append(json.loads(request.content))
        return httpx.Response(200, json={"status": "ok", "solution": {"status": 200, "url": f"{shop.base}/denied", "response": "<html><title>Echt</title><body><main><h1>Echt</h1>" + "tekst " * 150 + "</main></body></html>"}})

    app, _ = _fs_app(make_app, handler)
    async with app as c:
        body = (await render(c, f"{shop.base}/denied")).json()

    assert body["outcome"] == "fetched" and body["meta"]["renderer"] == "flaresolverr" and body["meta"]["attempts"] == 2
    assert sent[0]["cmd"] == "request.get" and sent[0]["url"].endswith("/denied") and isinstance(sent[0]["maxTimeout"], int)


async def test_a_200_from_flaresolverr_holding_a_shop_403_is_blocked(make_app, shop):
    def handler(request):
        return httpx.Response(200, json={"status": "ok", "solution": {"status": 403, "url": f"{shop.base}/denied", "response": "<HTML>Access Denied</HTML>"}})

    app, _ = _fs_app(make_app, handler)
    async with app as c:
        body = (await render(c, f"{shop.base}/denied")).json()

    assert body["outcome"] == "blocked" and "html" not in body


async def test_flaresolverr_down_after_a_block_still_reports_blocked_and_opens_the_breaker(make_app, shop):
    def handler(request):
        raise httpx.ConnectError("refused")

    app, breaker = _fs_app(make_app, handler)
    async with app as c:
        body = (await render(c, f"{shop.base}/denied")).json()

    assert body["outcome"] == "blocked"
    assert breaker.remaining() is not None


async def test_everything_down_is_upstream_error_502(make_app):
    from conftest import AnyPolicy

    def handler(request):
        raise httpx.ConnectError("refused")

    s = Settings()
    rungs = [FlareSolverrRung(s, "http://flaresolverr:8191", Breaker(120), _fs_client(handler))]
    async with make_app(settings=s, rungs=rungs) as c:
        r = await render(c, "http://127.0.0.1:1/x")

    assert r.status_code == 502 and r.json()["outcome"] == "upstream_error"
    assert "html" not in r.json()


async def test_unsolved_challenge_message_is_blocked_not_an_outage(make_app, shop):
    def handler(request):
        return httpx.Response(200, json={"status": "error", "message": "Error solving the challenge. Timeout after 60.0 seconds."})

    app, breaker = _fs_app(make_app, handler)
    async with app as c:
        body = (await render(c, f"{shop.base}/denied")).json()

    assert body["outcome"] == "blocked" and breaker.remaining() is None


@pytest.mark.skipif(not os.path.exists(CHROME), reason="Chromium binary not present")
async def test_js_shell_escalates_to_real_chromium_and_returns_rendered_content(make_app, shop):
    from conftest import AnyPolicy

    s = Settings(chromium_path=CHROME)
    rungs = [PlainRung(s, AnyPolicy()), ChromiumRung(s, AnyPolicy())]
    async with make_app(settings=s, rungs=rungs) as c:
        body = (await render(c, f"{shop.base}/spa", strip="xpath", keep_xpaths={"price": '//span[@itemprop="price"]', "h": "//h1"})).json()

    assert body["outcome"] == "fetched" and body["meta"]["renderer"] == "chromium" and body["meta"]["attempts"] == 2
    assert body["fragments"] == {"price": ["€ 9,99"], "h": ["SPA Product"]}


@pytest.mark.skipif(not os.path.exists(CHROME), reason="Chromium binary not present")
async def test_chromium_presents_the_stealth_profile_not_a_headless_fingerprint(shop):
    from conftest import AnyPolicy
    from pluck_proxy.target import Target

    rung = ChromiumRung(Settings(chromium_path=CHROME), AnyPolicy())
    res = await rung.fetch(f"{shop.base}/fingerprint", Target("http", "127.0.0.1", shop.server_address[1], "127.0.0.1"), follow=True, wait_ms=0, timeout=30)
    text = res.html[res.html.index("<p id=\"h\">"):][:400]

    assert res.failure is None
    assert "HeadlessChrome" not in text, "the default Playwright UA is what Akamai refused (measured on ah.nl)"
    assert "Chrome/" in text and "webdriver=undefined" in text and "langs=nl-NL,nl" in text and "chromeobj=object" in text


@pytest.mark.skipif(not os.path.exists(CHROME), reason="Chromium binary not present")
async def test_chromium_cannot_be_used_to_reach_the_internal_network(shop):
    """An untrusted page asks the browser to call an 'internal' service that WOULD answer (CORS open).
    With the request guard the call is aborted; without it the page would read the secret."""
    import threading
    from http.server import BaseHTTPRequestHandler, HTTPServer
    from urllib.parse import urlsplit

    from pluck_proxy.target import PublicTargetPolicy, Target

    class Internal(BaseHTTPRequestHandler):
        def log_message(self, *a): pass
        def do_GET(self):
            self.send_response(200); self.send_header("Access-Control-Allow-Origin", "*"); self.send_header("Content-Type", "text/plain"); self.end_headers(); self.wfile.write(b"INTERNAL-SECRET")

    internal = HTTPServer(("127.0.0.1", 0), Internal)

    class Page(BaseHTTPRequestHandler):
        def log_message(self, *a): pass
        def do_GET(self):
            html = ("<html><body><script>fetch('http://127.0.0.1:%d/').then(r=>r.text()).then(t=>document.title='GOT:'+t).catch(()=>document.title='BLOCKED')</script><p>%s</p></body></html>" % (internal.server_address[1], "tekst " * 150))
            self.send_response(200); self.send_header("Content-Type", "text/html"); self.end_headers(); self.wfile.write(html.encode())

    page = HTTPServer(("127.0.0.1", 0), Page)
    for srv in (internal, page):
        threading.Thread(target=srv.serve_forever, daemon=True).start()

    class Pol(PublicTargetPolicy):
        """The page's own port is allowed (it is the 'shop'); the internal service is not public."""
        async def vet(self, url):
            p = urlsplit(url)
            if p.port == page.server_address[1]:
                return Target(p.scheme, p.hostname, p.port, "127.0.0.1")
            return await super().vet(url)

    try:
        rung = ChromiumRung(Settings(chromium_path=CHROME), Pol())
        res = await rung.fetch(f"http://127.0.0.1:{page.server_address[1]}/", Target("http", "127.0.0.1", page.server_address[1], "127.0.0.1"), follow=True, wait_ms=500, timeout=30)
    finally:
        internal.shutdown(); page.shutdown()

    assert res.failure is None
    assert "<title>BLOCKED</title>" in res.html and "INTERNAL-SECRET" not in res.html


# ---------- caching, politeness, rate limiting ----------

async def test_cache_hit_does_not_touch_the_shop_and_ttl_zero_bypasses(client, shop):
    before = shop.hits.get("/plain", 0)
    a = (await render(client, f"{shop.base}/plain", ttl_seconds=60)).json()
    b = (await render(client, f"{shop.base}/plain", ttl_seconds=60)).json()
    c = (await render(client, f"{shop.base}/plain", ttl_seconds=60, strip="none")).json()
    d = (await render(client, f"{shop.base}/plain")).json()

    assert a["meta"]["cache"] == "miss" and b["meta"]["cache"] == "hit" and b["html"] == a["html"]
    assert b["request_id"] != a["request_id"]
    assert c["meta"]["cache"] == "miss", "strip mode is part of the key"
    assert d["meta"]["cache"] == "bypass"
    assert shop.hits["/plain"] - before == 3, "miss + different-key miss + bypass; the hit cost nothing"


async def test_blocked_results_are_never_cached(client, shop):
    await render(client, f"{shop.base}/challenge200", ttl_seconds=60)
    b = (await render(client, f"{shop.base}/challenge200", ttl_seconds=60)).json()

    assert b["outcome"] == "blocked" and b["meta"]["cache"] != "hit"


async def test_politeness_books_three_seconds_per_domain_and_one_global(make_app, shop):
    waits = []

    async def spy(seconds):
        waits.append(seconds)

    async with make_app(sleeper=spy) as c:
        await render(c, f"{shop.base}/plain")
        await render(c, f"{shop.base}/many")
        await render(c, f"http://localhost:{shop.server_address[1]}/plain")

    assert waits[0] == pytest.approx(0, abs=0.05)
    assert waits[1] == pytest.approx(3.0, abs=0.2), "second hit on the same domain waits the 3 s floor"
    assert 1.0 <= waits[2] <= 7.0, "another domain waits at least the 1 s global floor"


def test_floors_may_only_be_raised():
    with pytest.raises(ValueError):
        Settings(domain_interval=2.0)
    with pytest.raises(ValueError):
        Settings(global_interval=0.5)
    assert Settings(domain_interval=5.0).domain_interval == 5.0


async def test_rate_limit_429_with_retry_after(make_app, shop):
    async with make_app(settings=Settings(rate_limit_per_minute=2)) as c:
        ok1 = await render(c, f"{shop.base}/plain")
        ok2 = await render(c, f"{shop.base}/plain")
        over = await render(c, f"{shop.base}/plain")
        other = await c.post("/v1/render", json={"url": f"{shop.base}/plain"}, headers={"x-pluck-consumer": "welzolekker"})

    assert ok1.status_code == ok2.status_code == 200
    assert over.status_code == 429 and over.json()["retry_after_seconds"] >= 1 and int(over.headers["retry-after"]) >= 1
    assert other.status_code == 200, "budgets are per consumer"


async def test_healthz(client):
    assert (await client.get("/healthz")).json()["status"] == "ok"
    assert (await client.get("/docs")).status_code == 404, "no public API docs"
