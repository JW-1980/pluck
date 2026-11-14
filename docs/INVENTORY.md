# Inventory — what exists today (verified 2026-11-14)

Ground truth for anyone building on or extracting into Pluck. Every fact below
comes from a code survey of the three repositories on 2026-11-14; sizes were
measured that day. If the code has moved on since, re-verify before relying on
a specific line reference.

## 1. The three repositories

| Repository | Role | Stack |
|---|---|---|
| `C:\Projecten\Inktomi` | The external bulk spider for both sites; owns the spider MySQL schemas and the publisher | .NET 10 WinUI 3 + CLI (older generation), live **Python** pipeline (`src/superraten/`, root `spider_*.py` scripts) |
| `C:\Projecten\Nutricompare` | Laravel 12 app (supplements comparison). Contains an extensive **in-app PHP spider stack** plus crawl/feed ingestion | PHP ^8.3, Laravel ^12, Guzzle ^7.10 |
| `C:\Projecten\welzolekker` | Laravel 13 app = **watvindenwijvan.nl** (supermarket food). Two small self-scrapers + the only tested sync consumer | PHP ^8.4.1, Laravel ^13.17 |

## 2. Fetching — who fetches what

### Inktomi (the bulk path, running in production)
- Three-layer fetch: `curl_cffi` with `chrome124` TLS impersonation → FlareSolverr (`http://127.0.0.1:8191/v1`) → headless Playwright Chromium (`networkidle`) — `src/superraten/fetcher.py`.
- Block handling: 10-minute per-domain Playwright cooldown after 403/503/500 (`playwright_disabled_domains`); 120 s circuit breaker that disables FlareSolverr while it is down; blocked fetches return `("", status, "blocked")` instead of retry-storming.
- Politeness (user-mandated, recorded in `src/superraten/config.py`): `MIN_DOMAIN_INTERVAL = 3.0 s` per domain, `MIN_GLOBAL_INTERVAL = 1.0 s` per request, `OFF_MIN_INTERVAL = 2.1 s` for Open Food Facts (with identifying UA `InktomiSuperRaten/1.0`). Queue candidates are picked randomly across domains (`crawler.py::run`).
- Extraction: JSON-LD (Schema.org Product) first, then Magento dataLayer / OpenGraph / microdata / selector fallbacks; an LLM (LM Studio, local) acts as a *gate* (supplement/food yes-no), soft-deleting rejects with `deleted_reason='non_supplement'`. Per-domain xPath profiles are designed (`AAA-SCRAPE.md` §11, `domain_xpath_profiles` table) but **not implemented** in Python.
- The .NET generation has its own stealth stack (`PlaywrightStealthBrowser.cs`, `FlareSolverrClient.cs`, `BlockDetection.cs`, `RobotsTxtParser.cs`/`HttpRobotsGate.cs` — the robots gate is off by default). Its production status is an open question.
- No scheduler exists: runs are manual/backgrounded Python processes. Scale: **10k–28k price rows/day** on `superraten` since 2026-11-08; 98 Dutch supplement shops crawled by `spider_full_catalog.py`.

### NutriCompare (narrow in-app fetching)
- **Price refresher** — feature flag `in_app_price_refresh`, **off by default**: `price:refresh-due` every 15 min → one `RefreshOfferPriceJob` per offer (`$tries=1`, 90 s timeout, max 20 deferrals on busy rate slots, ≤2 retries with exponential backoff / capped `Retry-After`) → `OfferPriceRefresher`: circuit breaker → robots gate → rate slot → strategy chain (Shopify product JSON → JSON-LD offers → XPath rules) → `PriceObservationRecorder`. The fetcher (`PoliteFetcher`) is shop-domain-only, same-domain redirects only (≤3), SSRF-guarded, 3 MB body cap, 8 s timeout.
- **Rate limiting**: `ShopRateLimiter` — one request per shop per `max(5 s, websites.crawl_delay_seconds, robots Crawl-delay)`, atomic cache store, keyed on shop row id.
- **robots.txt**: `RobotsTxtGate` + RFC 9309 parser (`RobotsTxt.php`, incl. Crawl-delay; 4xx = allow, 5xx/unreachable = disallow-all cached 1 h). Applies only when the site-wide `spider.respect_robots` switch (**off, owner decision 2026-09-28**) and the shop's `respects_robots` are both on.
- **User agent**: browser string by owner decision 2026-09-29 (`config/spider.php:18`); `NutriCompareBot/1.0` fallback strings exist in config.
- **SSRF guard**: `GuardedClient` (`app/Support/Http/`) — screens A/AAAA records, pins the connection via `CURLOPT_RESOLVE`, never follows redirects, caps the body mid-transfer; plus `UrlSecurityValidator`, `CappedBodyStream`, `TransportErrorScrubber`, `IpClassifier` (private ranges blocked, with an explicit exception allowing self-hosted FlareSolverr/Browserless on localhost).
- **Generic crawler** — wired but **deliberately not scheduled** (documented decision D-1, "scraping happens outside this application"): `CrawlerService` → `ProcessCrawlUrl` jobs → `BrowserCrawler` (Browsershot through a `ProxyMeshMiddleware` + `AdaptiveFailoverMiddleware` pipeline, `HttpCrawler` fallback). Triggers: `crawl:products`, corrections, per-user rescrape (5/hour per user, 15-min per-product cooldown), admin extraction.
- **Source-driver chain**: `SourceResolver` picks per request (website's own source → preferred → default → affinity → weighted round-robin failover) with per-domain 24 h blocks, cooldowns and a metered-source gate. Drivers: `HttpSourceDriver`, `LocalBrowserSourceDriver`, `RemoteBrowserSourceDriver` (Browserless), `FlareSolverrSourceDriver`, `CustomApiSourceDriver`/`ManagedApiSourceDriver` + named drivers for **Zyte, ZenRows, ScrapingBee, Scrapfly, ScraperAPI, Bright Data, Firecrawl**. Admin UI: `SpiderSettingsController` (DB-backed, per-website overrides). `ScraperClient` is **dead code** (superseded by the drivers; owner declined deletion — `HANDOFF_AI_AGENT.md:284-294`).
- **Feeds**: `catalog:discover-feeds` weekly (platform detection Shopify/WooCommerce/Magento, up to 3 advertised XML feeds), `spider:ingest-feeds` daily (imports product feeds through `CrawlImportService` in an undoable batch; RSS/Atom only queue URLs), `feeds:run-due` hourly vendor feeds. Kruidvat/Etos/XXL/Minami homepages are 403 bot walls, "left alone" (`docs/SHOP_FEEDS.md`).
- **Monitoring**: `scraper:check-egress-ip` daily — warns when the **Oracle scraper VM's Security List** is stale (retires with the Oracle VM).

### WelZoLekker (narrow in-app fetching)
- **Live price refresh** — off by default (`LIVE_PRICES_ENABLED=false`): hourly `prices:refresh-live`; `ProductPageFetcher` loads a supermarket product page directly or through FlareSolverr (`flaresolverr_url` — **URL only, no auth-header support**); bounded HTTPS-only redirects, streamed body with a hard size cap; domain-locked to each offer's own supermarket domain; freshness cache (`fresh_hours`) and failure backoff (`retry_after_failure_minutes`).
- **Folder-deals scraper**: weekly, Mondays 06:07; per-chain URL + XPath config (`SupermarketPromotionConfig`, 12 chains' starting XPaths in `PromotionScraperPresets`); `requires_js` pages go through FlareSolverr or a headless Chromium binary (`--dump-dom`); 1 s delay between chains (`delay_between_chains_ms`).
- **No outbound robots.txt handling; no shared outbound rate limiter** — per-task knobs only.
- **Sanctioned external hook (P11)**: `GET /api/scraper/te-controleren` + `POST /api/scraper/prijscontrole` behind `AuthenticateScraper` — price verification for an external scraper.

## 3. Transport — how spider data reaches the sites

- **Inktomi publisher** (`scripts/sync.py`, `src/sync/{build,push,state,config}.py`): CLI `init/build/push/status`; watermark per table, chunked (10,000 rows / 25 MB) JSONL.gz with sha256 manifest, whitelist columns (`raw_html` excluded), explicit tombstones; state schema `inktomi_sync`. A full `superraten` build was verified 2026-11-14 (4,873 brands / 87,282 products / 113,962 offers / 222,456 price observations in 44 chunks, 18.9 MB gzipped). **The push path has never been exercised against a live endpoint.** Only the `welzolekker` target is configured; the NutriCompare serializer + ingest endpoint are **phase 2, unbuilt**.
- **WelZoLekker consumer (implemented and tested)**: `POST /api/sync/packs` + chunk upload + complete/status behind `sync.token` (SHA-256 token hash, fail-closed) and `throttle:sync-ingest` (60/min); `sync:apply` every 5 min with its own lock; `SuperRatenPackImporter` (match on `external_source`+`external_id`, then EAN; tombstones take products offline; `PricePlausibility` checks).
- **NutriCompare today (stopgap)**: manual dump into a second empty DirectAdmin database → read-only `crawl` connection → `catalog:import-crawl --due --only=prices` every 15 min. Because the copy is frozen, **prices only move when a person re-loads it**. Deletions never propagate (K-05); images have no transport; the reverse channel is stranded (`price:refresh-priorities` writes the refresh plan to the app's local disk — the spider cannot read it).
- **Legacy**: `POST/PUT /api/sync/sqlite` (bearer `CRAWLER_SYNC_TOKEN`, malware gate, byte cap) — the old harvester path.
- **Design of record**: `docs/SPIDER_DATA_SYNC.md` in the NutriCompare repo (push immutable hashed chunks over HTTPS; every transport ends in `CatalogImportService`; §11 "what not to do").

## 4. Data volumes (measured 2026-11-14)

| Schema | Products | Prices | Raw HTML | Images |
|---|---|---|---|---|
| `nutricomparev2` | 15,580 (11,625 soft-deleted) | 31,530 | 9.7 GB | — |
| `superraten` | 87,281 | 221,805 | 46.8 GB | 55 GB on disk |

Weekly-refresh requirement (owner): ~4,000 live supplement products ⇒ **~570 fetches/day minimum** per site. Inktomi's superraten pipeline already moves 10k–28k price rows/day within its politeness floors.

## 5. The duplication map — what Pluck removes

| Concern | Inktomi | NutriCompare | WelZoLekker |
|---|---|---|---|
| FlareSolverr client | `fetcher.py` + .NET `FlareSolverrClient.cs` | `FlareSolverrSourceDriver` + admin provider config | `ProductPageFetcher` + `PromotionPageFetcher` |
| Polite HTTP fetch | curl_cffi layer | `PoliteFetcher` / `GuardedClient` | `boundedRequest`/`boundedBody` in `ProductPageFetcher` |
| Block detection / backoff | domain cooldowns + circuit breaker | `SourceResolver` domain blocks + `CircuitBreaker` | `retry_after_failure_minutes` |
| Rate limiting | `MIN_DOMAIN_INTERVAL`/`MIN_GLOBAL_INTERVAL` | `ShopRateLimiter` | `delay_between_chains_ms` |
| robots.txt | .NET gate, off by default | RFC 9309 parser + gate (switch off) | **absent** |
| Scheduling / "learn" | random cross-domain queue | PRICE_TELEMETRY tiers + export; `DynamicRecrawlScheduler`, `StaleProductMonitor`, `PromotionalPeriodDetector` | per-task knobs |

Each triple currently has **different body caps, redirect rules and failure semantics**. Pluck's first job is to make each concern have one owner with one documented behaviour.
