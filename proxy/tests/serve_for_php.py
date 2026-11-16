"""Starts the fixture shop and the real proxy app (uvicorn, a real socket) for the PHP interop test.
Prints one JSON line {"proxy": port, "shop": port} and serves until killed."""
import asyncio
import json
import socket
import sys
import threading
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))
sys.path.insert(0, str(Path(__file__).parent.parent))

import uvicorn

from conftest import AnyPolicy, Shop
from pluck_proxy.app import create_app
from pluck_proxy.config import Settings


async def no_sleep(_):
    return None


shop = Shop()
threading.Thread(target=shop.serve_forever, daemon=True).start()
s = socket.socket(); s.bind(("127.0.0.1", 0)); port = s.getsockname()[1]; s.close()
app = create_app(Settings(rate_limit_per_minute=0), policy=AnyPolicy(), sleeper=no_sleep)
print(json.dumps({"proxy": port, "shop": shop.server_address[1]}), flush=True)
uvicorn.run(app, host="127.0.0.1", port=port, log_level="warning")
