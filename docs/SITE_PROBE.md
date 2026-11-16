# Site probe — does a site need FlareSolverr / a browser, or is plain PHP enough?

Owner question (2026-11-15): *does a given website need FlareSolverr and a
stealth headless browser, or would a simple curl from mijn.host also work?*

The answer is **per site, per host IP, and it can change**, so Pluck ships a
probe that measures it instead of guessing.

## Run it on the host you care about

```bash
vendor/bin/pluck probe https://www.example.nl/product/123 [more URLs…] \
    [--flaresolverr=http://127.0.0.1:8191] [--chromium=/path/to/chrome] [--json]
```

- The first rung is always **plain HTTP through the same code the consumers
  use** (`PoliteFetcher`: Guzzle/cURL, browser UA, `nl-NL`). Run on mijn.host
  over SSH and that rung *is* "a simple curl from mijn.host". A result produced
  elsewhere says nothing about mijn.host: WAFs judge IP reputation and TLS/HTTP
  fingerprint, not just headers.
- `--flaresolverr` adds a FlareSolverr rung (use the front-door URL when probing
  from the webhost). `--chromium` adds plain headless Chromium (`--dump-dom`) —
  **not** a stealth browser (see below). Mijn.host shared hosting probably cannot
  run Chromium; probe plain HTTP + FlareSolverr there.
- Every rung is run and judged, with the politeness floors (3 s/domain) between
  touches. **Probe product URLs**, not home pages — see "Inconclusive" below.

| Verdict | Meaning | Action |
|---|---|---|
| `plain_http` | Plain GET returns a page with product data (JSON-LD Product or price markup) | No FlareSolverr, no browser |
| `needs_javascript` | Plain GET returns a JS shell (no data, "JavaScript is required") | Rendering needed |
| `needs_flaresolverr` | Plain blocked; FlareSolverr returned real content | Route via proxy/FlareSolverr |
| `needs_browser` | Plain blocked; plain headless Chromium returned real content (FlareSolverr not tried or not through) | Route via the proxy renderer; FlareSolverr probably works too — confirm |
| `blocked_everywhere` | Every rung tried was blocked/failed | Next rungs (stealth Chromium, managed API) are outside this probe |
| `plain_blocked_untested` | Plain blocked and no stronger rung was supplied | Re-run with `--flaresolverr` / `--chromium` |
| `moved_host` | URL redirects to another host (e.g. a TLD change); refused by design | Update the stored URL |
| `inconclusive` | Plain GET returns a page with no product markup | Probe a real product URL |
| `unreachable` | DNS/TLS/timeout/5xx on the plain rung | Check URL and egress |

"Got through" for a stronger rung means *real content came back* (not blocked,
not a shell, ≥ 500 visible characters) — product markup is not demanded of a
promo or category page.

## What was measured (2026-11-15, from the build sandbox — NOT from mijn.host)

Egress was a cloud IP through the session's HTTPS proxy, Chromium 1243 headless
without stealth patches, the Pluck plain-HTTP rung on Guzzle 8.5 (cURL). No
FlareSolverr instance was available, so **that rung was never exercised**.

| URL (what it is) | Plain HTTP | Headless Chromium | Verdict |
|---|---|---|---|
| ah.nl/bonus (promo page) | **403**, Akamai "Access Denied" (final cURL run; the same 403 from plain `curl` and from an earlier stream-handler run) | real page, 17k chars | `needs_browser` |
| kruidvat.nl/ (home) | **403**, Akamai "Access Denied" (same three clients as AH) | real page, price markup | `needs_browser` |
| etos.nl/ (home) | **403** (final cURL run only; no body marker) | real page, 19k chars | `needs_browser` |
| plus.nl/aanbiedingen (promo) | first run **403** Imperva/Incapsula; later runs 200 with a `<noscript>` "JavaScript is required" shell | real page, 6.8k chars | `needs_javascript` |
| aldi.nl/aanbiedingen-deze-week (promo) | 200, JS shell (59 visible chars) | real page, 15k chars | `needs_javascript` |
| dirk.nl/aanbiedingen (promo) | 200, JSON-LD Product + prices | same | `plain_http` |
| hoogvliet.nl/aanbiedingen (promo) | 200, price markup | same | `plain_http` |
| vitaminstore.nl/ (home) | 200, price markup | same | `plain_http` |
| jumbo.com/aanbiedingen, lidl.nl/…/a10008785, vomar.nl/aanbiedingen, world.openfoodfacts.org/ | 200, real server-rendered text, **no product markup** | same | `inconclusive` (need product URLs) |

(`hoogvliet.com/aanbiedingen` → 301 → `hoogvliet.nl`: refused by the same-site
redirect rule, probe verdict `moved_host`; WelZoLekker's preset URL is stale.)

### What this does and does not show

- **Shown:** from a clean cloud IP, with a browser UA, plain HTTP is *hard
  blocked* by AH, Kruidvat and Etos (Akamai-style 403) while headless Chromium
  with an ordinary UA (the probe's `--chromium` rung sets one) gets the real
  page. **Correction (2026-11-16, found while building the proxy):** this is not
  "no stealth needed". Playwright's *default* headless Chromium (`HeadlessChrome`
  UA) was refused by ah.nl with the same 403; it passed only with Inktomi's
  stealth profile (normal UA, `AutomationControlled` off, `navigator.webdriver`
  removed), and TLS impersonation alone (`curl_cffi chrome124`) did not help. So
  the indicated rung is a rendering browser **with at least that light stealth
  profile** — which the proxy's Chromium rung now applies. Heavier stealth
  was not needed for these three from this IP. Plus and Aldi need
  JavaScript rendering regardless of blocking. Dirk, Hoogvliet and the supplement
  shop need neither.
- **Not shown:** whether mijn.host's IP and cURL/OpenSSL fingerprint get the same
  treatment (it is a *shared hosting* address range and may be treated worse);
  whether FlareSolverr itself gets through (never run); whether *product* pages
  of the `inconclusive` shops carry their data in the HTML; whether blocking is
  IP-driven or fingerprint-driven (same-IP comparison suggests the client
  fingerprint matters for Akamai, but it was not isolated — Inktomi's first layer,
  `curl_cffi` TLS impersonation, is not available from PHP at all).
- **WAF answers vary run to run** (Plus.nl answered 403 once and 200-shell later),
  so one probe is a sample. Repeat on different days before hard-coding a driver
  per domain; store the verdict per domain with its date.

**Practical reading:** do not assume plain PHP/curl from mijn.host works for the
big Dutch supermarkets and drugstores — at least AH, Kruidvat and Etos block it
even from a clean IP. Run `pluck probe` on mijn.host against one real product URL
per shop, with `--flaresolverr` pointing at the front door, and let the verdicts
decide which domains need the proxy.
