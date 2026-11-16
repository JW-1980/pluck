# pluck-proxy

The Pluck proxy service: renders a shop page, strips it, answers with an explicit outcome. It implements
`../docs/PROXY_CONTRACT.md` v1 and is the other half of the PHP SDK in `../src`.

```
caddy (secret-path front door) -> pluck-proxy -> plain HTTP (Chrome TLS impersonation, curl_cffi)
                                              -> FlareSolverr (Docker-internal)
                                              -> Playwright Chromium (stealth profile)
```

## Behaviour (what is verified, see "Verification")

- **Renderer chain** (Inktomi `fetcher.py`): plain → FlareSolverr (when `FLARESOLVERR_URL` is set) → Chromium.
  Blocked, renderer-down, transport failure and JS-shell results escalate; 404/410 and other non-block 4xx/5xx stop
  the chain. All rungs failed: `blocked` outranks `upstream_error` outranks `shop_error`; a block starts a 10-minute
  per-domain cooldown during which the shop is not touched.
- **Strip modes** `none | conservative | readability | xpath`; every mode extracts all JSON-LD and the title.
  `xpath` evaluates the consumer's named expressions with lxml and returns only fragments; **zero matches stays
  `fetched` with `kept_counts: 0`**. Expressions are validated first (core XPath 1.0 functions only; no variables,
  no extension functions, no `document()`); a bad one is `400 invalid_xpath`.
- **Target validation**: public http(s) only, judged from the proxy's vantage; the connection is pinned to the vetted
  IP; redirects are same-site only, ≤ 3, re-vetted per hop; Chromium's own sub-requests are vetted too, so a hostile
  page cannot reach the internal Docker network.
- **Politeness**: 3 s per domain, 1 s global, 2.1 s Open Food Facts (Inktomi), floors raisable only upward; FlareSolverr
  circuit breaker 120 s. **Caps**: 3 MB page (cut mid-transfer, also without `Content-Length`), 50 nodes × 64 KB per
  fragment name, 1 MB fragments in total.
- **Caching**: in-memory LRU keyed on `(url, strip, keep_xpaths, preserve_scripts, follow_redirects)`; only `fetched`
  is stored; a hit costs the shop nothing; `ttl_seconds: 0` bypasses.
- **Logs**: consumer, host, outcome, renderer, elapsed. Never bodies, never the secret path (Caddy's access log is off).

## Run

```bash
# development / tests (Python 3.13)
python -m venv .venv && .venv/bin/pip install -e '.[test]' && .venv/bin/playwright install chromium
.venv/bin/python -m pytest                          # contract + unit tests
FLARESOLVERR_URL=http://127.0.0.1:8191 .venv/bin/uvicorn pluck_proxy.app:app_from_env --factory --port 8080

# the VPS stack
cp deploy/.env.example deploy/.env   # set PLUCK_DOMAIN and a long random PLUCK_SECRET
docker compose --env-file deploy/.env up -d --build
```

| Env | Default | Meaning |
|---|---|---|
| `FLARESOLVERR_URL` | unset | enables the FlareSolverr rung |
| `CHROMIUM_PATH` | Playwright's own | Chromium binary |
| `PLUCK_MAX_CONCURRENCY` | 4 | the design's 4 vCPU |
| `PLUCK_RATE_LIMIT_PER_MINUTE` | 0 (off) | per consumer (`X-Pluck-Consumer` header); no donor value exists |
| `PLUCK_DOMAIN_INTERVAL` / `PLUCK_GLOBAL_INTERVAL` | 3 / 1 | refused below the floors |
| `PLUCK_CACHE_MAX_ENTRIES` | 1000 | |
| `PLUCK_MAX_REQUEST_BYTES` | 32768 | largest valid request is ~11 KB |

## Decisions the contract left open (record them as owner decisions)

1. `blocked` and `shop_error` answer **HTTP 200** with the outcome in the body; only `upstream_error` is `502`.
2. Exceeding the 1 MB **fragments** cap is `shop_error` / `content_too_large` (the contract states it for `html` only).
3. `kept_counts` is the number of fragments **returned** (≤ 50), not the total matches.
4. Additive fields: `error`, `block_signal`, `message` (error responses).
5. Complexity budget for XPath = the contract's 500-character cap; no further invented numeric budget.
6. A transport failure before any shop answered is `shop_error` with `error: "transport: …"` (no better outcome exists).
7. Chromium: `load`, then `networkidle` for at most `networkidle_soft_ms` (default **10 000 ms — an operational default
   with no donor value; measure it**). Inktomi's pure `networkidle` never completed on ah.nl.
8. One worker only: cooldown, cache and politeness state are in-process. **A restart forgets cooldowns**, so a freshly
   restarted proxy can touch a domain that was in cooldown once.

## Verification (2026-11-16) and what is NOT verified

Verified in this session: the pytest suite (contract, unit, real local HTTP shop, real Chromium), mutation checks of the
guarantees (all caught), the real PHP `ProxyClient` against the running service over a socket, and live renders through
the production target policy (below). **Not verified:** the Docker image build and `docker compose up` (no Docker daemon
available; only `docker compose config` was run), the Caddy config (Caddy not available), any real FlareSolverr
instance (its rung is tested against a fake with the documented reply shape), load/concurrency behaviour, Python other
than 3.13, and behaviour from the VPS's own IP.

Live (from a cloud IP, not the VPS): jumbo.com, dirk.nl → plain; plus.nl, aldi.nl → plain returned a JS shell, Chromium
rendered it; ah.nl, kruidvat.nl, etos.nl → plain blocked (403), Chromium **with the stealth profile** returned the real
page. Playwright's *default* headless Chromium was refused by ah.nl (403 `Access Denied`, `HeadlessChrome` UA); with a
normal UA derived from the real browser version, `AutomationControlled` off and `navigator.webdriver` removed (Inktomi's
profile) it passed. TLS impersonation alone (`curl_cffi` `chrome124`) did **not** get past AH or Kruidvat from this IP.
