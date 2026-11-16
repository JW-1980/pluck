from __future__ import annotations

import os
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

import httpx
import pytest

from pluck_proxy.app import create_app
from pluck_proxy.config import Settings
from pluck_proxy.politeness import Politeness
from pluck_proxy.target import Target

FIX = Path(__file__).parent / "fixtures"
CHROME = "/opt/pw-browsers/chromium-1243/chrome-linux64/chrome"


class Shop(ThreadingHTTPServer):
    daemon_threads = True

    def __init__(self):
        super().__init__(("127.0.0.1", 0), Handler)
        self.hits: dict[str, int] = {}

    @property
    def base(self) -> str:
        return f"http://127.0.0.1:{self.server_address[1]}"


class Handler(BaseHTTPRequestHandler):
    def log_message(self, *a):  # silence
        pass

    def _send(self, status, body=b"", ctype="text/html; charset=utf-8", headers=None):
        self.send_response(status)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(len(body)))
        for k, v in (headers or {}).items():
            self.send_header(k, v)
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        path = self.path.split("?")[0]
        self.server.hits[path] = self.server.hits.get(path, 0) + 1
        fixture = {"/plain": "plain.html", "/challenge200": "challenge.html", "/spa": "spa_shell.html", "/magento": "magento.html", "/many": "many.html"}
        if path in fixture:
            return self._send(200, (FIX / fixture[path]).read_bytes())
        if path == "/denied":
            return self._send(403, b"<HTML><TITLE>Access Denied</TITLE></HTML>")
        if path == "/gone":
            return self._send(404, b"<h1>Access Denied</h1> not here")
        if path == "/boom":
            return self._send(500, b"oops")
        if path == "/redirect":
            return self._send(302, b"", headers={"Location": "/plain"})
        if path == "/redirect-out":
            return self._send(302, b"", headers={"Location": "http://other.test:1/plain"})
        if path == "/big":
            return self._send(200, b"<html><body>" + b"a" * (4 * 1024 * 1024) + b"</body></html>")
        if path == "/fingerprint":  # reports what the client presented, as the page itself sees it
            ua = self.headers.get("User-Agent", "")
            page = ("<html><body><p id='h'>%s</p><script>document.getElementById('h').textContent += ' | webdriver=' + navigator.webdriver + ' | langs=' + navigator.languages.join(',') + ' | chromeobj=' + (typeof window.chrome);</script><p>%s</p></body></html>" % (ua, "tekst " * 150))
            return self._send(200, page.encode())
        if path == "/big-stream":  # no Content-Length (HTTP/1.0 close-delimited): only the mid-transfer cap can stop it
            self.send_response(200)
            self.send_header("Content-Type", "text/html")
            self.end_headers()
            try:
                for _ in range(64):
                    self.wfile.write(b"b" * 65536)
            except (BrokenPipeError, ConnectionResetError):
                pass
            return
        if path == "/slow":
            import time

            time.sleep(3)
            return self._send(200, b"<html><body>slow</body></html>")
        self._send(404, b"nope")


@pytest.fixture(scope="session")
def shop():
    server = Shop()
    threading.Thread(target=server.serve_forever, daemon=True).start()
    yield server
    server.shutdown()


class AnyPolicy:
    """Accepts every target (the local shop is on 127.0.0.1) and pins it there."""

    async def vet(self, url: str) -> Target:
        from urllib.parse import urlsplit

        p = urlsplit(url)
        return Target(p.scheme, p.hostname or "", p.port or 80, "127.0.0.1")


async def _no_sleep(_: float) -> None:
    return None


@pytest.fixture
def make_app():
    def build(settings: Settings | None = None, **kw):
        s = settings or Settings(chromium_path=CHROME if os.path.exists(CHROME) else None)
        kw.setdefault("policy", AnyPolicy())
        kw.setdefault("sleeper", _no_sleep)
        app = create_app(s, **kw)
        transport = httpx.ASGITransport(app=app)
        return httpx.AsyncClient(transport=transport, base_url="http://proxy")

    return build


@pytest.fixture
async def client(make_app):
    async with make_app() as c:
        yield c


async def render(client, url, **body):
    r = await client.post("/v1/render", json={"url": url, **body})
    return r
