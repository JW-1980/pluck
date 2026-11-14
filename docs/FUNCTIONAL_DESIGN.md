# Pluck — functional design

**Status: approved direction (owner, 2026-11-14). Implementation not started.**
This document is the build brief. `docs/INVENTORY.md` holds the verified
ground truth about the three existing codebases; `docs/PROXY_CONTRACT.md` is
the wire contract the package and the proxy service both implement;
`docs/MIGRATION_PLAN.md` is the ordered task list for the implementing agent;
`docs/INTEGRATION.md` covers composer adoption.

## 1. Purpose

Pluck is **one repository serving two comparison sites**:

- **NutriCompare** — `nutricompare.nl`, Laravel 12, supplements
- **WelZoLekker** — `watvindenwijvan.nl`, Laravel 13, supermarket food

It contains two things:

1. **The proxy service** (Docker, runs on the one VPS): receives a URL,
   renders the page (FlareSolverr / headless Chromium / plain HTTP),
   **strips useless HTML**, and returns the useful part. All rendering and
   cleaning happens here.
2. **The PHP client SDK** (composer package `jw-1980/pluck`, framework-agnostic):
   sends a URL to the proxy and processes the structured answer. PHP does
   **not** render and does **not** strip.

**End state: Pluck's stack is the only spider.** Inktomi is phased out and
runs only on the owner's own computer until then (§3, §4). Everything else
stays out of Pluck (see §9).

## 2. Owner requirements, as stated, and where they landed

| # | Owner statement (this conversation, 2026-11-14) | Disposition |
|---|---|---|
| 1 | "A VPS between our websites and the websites we want to scrape" — benefits: PC no longer always on; website keeps control of what/when with cache and popular pages more often | Adopted. One VPS is the single spider host (decision 2026-11-14). Control stays with each site (refresh plans, per-shop budgets); publishing the refresh plan to the spider (fixing the stranded reverse channel) is the remaining work item, in the consumer repos. |
| 2 | One.com 4 vCPU / 8 GB (~€8.99), Ubuntu 24.04 LTS, Docker Compose, Playwright+Chromium, FlareSolverr as separate service, Redis optional initially, MariaDB "if needed" | Adopted with corrections. During the transition the VPS hosts **only the proxy stack** (caddy, pluck-proxy, FlareSolverr, Chromium). MariaDB (the spider schemas) moves to the VPS only when the PHP bulk spider takes over (§3); Redis stays deferred until a queue need is measured; disk spec still to verify at that point. |
| 3 | "Possibly its own repository, we use this for two sites" | Adopted — this repository. |
| 4 | "The sites are data hungry and need at least weekly price updates" | Recorded as an owner requirement. Sizing: ~4,000 live supplement products ⇒ ~570 fetches/day minimum; the current systems already move far more (Inktomi: 10k–28k price rows/day). Non-stop scraping is **not** required to meet it. |
| 5 | Webhost picks a URL not updated in a week, sends it to the proxy; proxy launches FlareSolverr + stealth browser, renders, downloads, strips, returns | Adopted as the fetch flow, **two corrections**: (a) the pick is not random — each site's existing refresh plan (popularity tiers, `--due`) remains the scheduler, once the reverse channel is fixed; (b) "stealth browser" means the proxy's renderer chain (FlareSolverr → Chromium fallback), not a new stealth implementation. |
| 6 | "We store xPaths per domain after the first visit… quickly get prices, ingredients" | Already exists, three times (INVENTORY §5). Extraction stays **per-site** (supplement xPaths ≠ supermarket nutrition tables); Pluck transports clean HTML and JSON-LD, never site-specific extraction. |
| 7 | "Wait one second and do another round" | **Redirected.** The politeness floor is Inktomi's recorded budget (3 s per domain, 1 s global, 10-min per-domain cooldown after blocks). Non-stop looping from one IP would get both sites banned. |
| 8 | "The system has to become smart and learn" — popular pages more often, pages with frequent changes and engagement | Already exists (PRICE_TELEMETRY tiers hot/warm/cool/cold; `DynamicRecrawlScheduler`, `StaleProductMonitor`, `PromotionalPeriodDetector`). One brain wins — the documented refresh plan — the others follow it. No new scoring may be invented (no invented numbers rule). |
| 9 | "Only a setting where we enter our proxy details" | Confirmed: NutriCompare's admin (Admin → Spider settings) already takes per-source endpoints/auth; WelZoLekker takes `FLARESOLVERR_URL`. After this design they point at the proxy front door. |
| 10 | FlareSolverr security "goed punt" | Adopted as mandatory: secret-path HTTPS front door (Caddy), FlareSolverr never published directly (§8). |
| 11 | "One spider. Oracle will be removed" | Adopted. The Oracle VM (Browserless :3000 + FlareSolverr :8191) is retired after the cutover gate (§8). Inktomi is unaffected by this cutover — it stays on the owner's PC. |
| 12 | "Go for the most extensive PHP spider stuff, even in its own repository" | Adopted as the **long-term direction**: PHP becomes the primary stack via Pluck, gated by the wiring audit (MIGRATION_PLAN phase 0). |
| 13 | "PHP should only send an URL to the proxy and process the answer; the proxy renders, removes useless HTML and returns that part" | Adopted as the core division of labour (§5). Consequences: the SSRF/allowlist guard moves proxy-side; the response carries explicit outcomes so PHP cannot misread a block as "no data"; the stripper preserves JSON-LD and offers a conservative mode so existing per-chain/per-domain xPaths keep working. |
| 14 | "Inktomi should be phased out, and only runs on my own computer. Adapt what's good from Inktomi in behavior" | Adopted. Inktomi **never migrates to the VPS**; it stays on the owner's PC (its data with it) until the PHP bulk spider has replaced it (MIGRATION_PLAN phase 5–6). Its behaviors are the adaptation spec — §4 is the definitive list of what is ported, and the publisher contract is the phase-out acceptance test. |
| 15 | "The cleaning is done through an XPath query that only keeps the parts where data is wanted — either send that to the proxy or strip in PHP, but for efficiency strip at the proxy. Could be a simple Python script." | Adopted. The proxy gains an `xpath` strip mode: the consumer sends its stored keep-expressions (`keep_xpaths`, a named map — one expression is never enough for multi-field extraction), the proxy evaluates them against the rendered DOM (lxml) and returns only the matching fragments. Zero matches is still outcome `fetched` with `kept_counts: 0` — a stale XPath is an extraction-health signal for the consumer, never "no data" and never "blocked". Expressions are validated proxy-side (safe-function guard, node and size caps). See `docs/PROXY_CONTRACT.md`. |

## 3. Target architecture

**Transition (now → phase 4 of the migration plan):** the VPS hosts only the
proxy stack. Inktomi keeps running exactly as today — on the owner's PC, with
its two MySQL schemas — and keeps pushing data through the publisher
contract. Nothing about Inktomi is relocated.

**End state (after phase 6):**

```
                ┌────────────────── VPS (One.com, Ubuntu 24.04, Docker Compose) ──────────────────┐
                │                                                                                 │
 shop pages ◀── │  caddy (HTTPS, secret path, optional IP allowlist + rate limit)                  │
                │      └─▶ pluck-proxy ──▶ flaresolverr (Docker-internal only)                     │
                │                 │         └─▶ (fallback) playwright chromium                     │
                │                 └─▶ stripper (conservative | readability)                        │
                │  pluck-spider (PHP worker: discovery, queue, politeness, publisher)              │
                │  mariadb: spider schemas (nutricomparev2 + superraten equivalents)               │
                └────────────────────────────────┬────────────────────────────────────────────────┘
                                                 │  outbound HTTPS 443 only (both directions)
                ┌────────────────────────────────┴─────────────────────────────┐
    mijn.host  │  NutriCompare (Laravel 12)             WelZoLekker (Laravel 13) │
               │  pluck client SDK                      pluck client SDK         │
               │  ingest endpoint (phase 2,             sync:apply (packs)       │
               │  its own repo) + importer                                       │
               └──────────────────────────────────────────────────────────────────┘

  Transition only: Inktomi + its MySQL (owner's PC) → publisher push → the
  same consumer endpoints. Retired after phase 6.
```

Two channels, never more:

- **Fetch requests** flow site → proxy (outbound 443 from the webhosts; the
  proxy is reached through the front door only).
- **Data** flows spider → sites via the publisher contract (immutable hashed
  chunks; consumers import through their own importer).

The spider never writes into either production database, and the sites never
touch the spider's MySQL except through the published chunks (NutriCompare's
read-only `crawl` connection remains for local development).

## 4. What we adapt from Inktomi — the behaviour spec

"Inktomi should be phased out" does not mean losing what works. These are the
behaviours to carry over, with their sources; each lands in a named Pluck
component or process.

| # | Behaviour (source) | Lands in |
|---|---|---|
| 1 | **Politeness floors** — 3 s per domain, 1 s global, 2.1 s for Open Food Facts, identifying UA for identified bots (`src/superraten/config.py`, user-mandated) | Proxy politeness defaults (`pluck-proxy`) and per-consumer client budgets |
| 2 | **Cross-domain queue selection** — candidates picked randomly across domains so one shop is never hammered in sequence (`crawler.py::run`) | Future PHP bulk spider's scheduler; the proxy enforces only the floors |
| 3 | **Three-layer fetch** — TLS-impersonating HTTP client → FlareSolverr → Playwright Chromium (`networkidle`) as SPA fallback (`fetcher.py`) | Proxy renderer chain |
| 4 | **Block handling** — 10-minute per-domain cooldown after 403/503/500; 120 s circuit breaker when FlareSolverr is down; a blocked fetch is an explicit `"blocked"` outcome, never a retry storm (`fetcher.py`) | Proxy block detection / backoff, surfaced through the contract's outcomes |
| 5 | **Extraction order** — JSON-LD (Schema.org Product) first, then Magento `window.dataLayer`, OpenGraph, microdata, selector fallbacks (`AAA-SCRAPE.md` §9) | Consumers keep their extractors; the contract guarantees the `json_ld` array so cheap extraction works without the raw page |
| 6 | **LLM as gate, not extractor** — cheap extraction first; a local LLM answers one yes/no relevance question; rejects are soft-deleted with `deleted_reason` (`run_spider_pipeline.py`, `classifier.py`) | Future PHP bulk spider. **Capacity caveat:** an 8 GB VPS cannot host a 9 B model comfortably next to Chromium + MariaDB — start rules-based (JSON-LD Product + EAN/price present ⇒ relevant), measure, then decide (§11) |
| 7 | **Soft-deletes with reasons; prices carry `scraped_at`** — rejects are marked, never silently dropped; a price without a scrape timestamp is skipped | Consumer importers already require this; the future spider emits tombstones per the publisher contract |
| 8 | **Publisher discipline** — per-table watermarks, immutable sha256 chunks, at-least-once delivery with server-side dedupe, explicit tombstones, `raw_html` never exported (`src/sync/*`, `SYNC_ARCHITECTURE.md`) | The contract of record for the phase-out: the PHP spider implements the same publisher, and the consumers stay unchanged |
| 9 | **Discovery + queue states** — sitemap-driven discovery; a candidate URL queue with `pending / processing / completed / rejected / failed` (`sitemap_collector.py`, `candidate_urls_queue`) | Future PHP bulk spider's queue design |
| 10 | **User-agent policy** — browser string for shop pages (owner decision 2026-09-29 in the consumer repos), identifying UA for identified bots like Open Food Facts | `Pluck\Support\UserAgent` defaults, overridable per consumer |

**Not adapted:** the .NET generation (dormant; confirm and retire), the
per-PC storage corpus under `%LOCALAPPDATA%` (replaced by the spider schema
on the VPS), and the manual/background-process scheduling (replaced by
systemd units / cron on the VPS).

## 5. The division of labour (owner directive #13)

**Proxy (VPS):** validate the target → render (FlareSolverr, Chromium
fallback, plain HTTP when no challenge) → **strip** → answer with an explicit
outcome. The proxy owns: SSRF/target validation, per-domain politeness
(Inktomi floors as defaults), concurrency limits, response-size caps, block
detection, and caching (optional, per-request TTL).

**PHP (webhost):** send `{url, options}` → receive the envelope → process.
Processing per site: match the URL to its own product/offer, extract via its
own stored xPaths / JSON-LD, validate (EAN, price plausibility, dosage…),
record with `scraped_at`, never overwrite a good value with an empty or
blocked extraction.

**Boundary exceptions (PHP still fetches directly, on purpose):**
`robots.txt` and feed/RSS/vendor URLs are plain document fetches — routing
them through a render proxy would be wasteful and slower. Those keep using
the consumer's existing guarded clients. Everything that renders a shop page
goes through the proxy.

## 6. Package design (PHP SDK)

- Namespace `Pluck\`; **framework-agnostic** (Guzzle + PSR interfaces only —
  the two consumers are on Laravel 12 and 13; no `laravel/framework`
  requirement).
- Proposed layout (adjusted as extraction reveals real boundaries):

```
src/
  Client/       ProxyClient — send URL, typed answer (Outcome, Envelope)
  Contracts/    SourceDriver, Outcome, RateLimiter, Clock — ported from the
                donor implementations (NutriCompare's CrawlerInterface /
                SourceDriver / SourceRequest / SourceOutcome), not invented
  Drivers/      fallback sources (paid scraping APIs) — later phases
  Support/      UserAgent, body caps, error scrubbing
```

- **No invented numbers.** Every default (timeouts, caps, delays) traces to a
  recorded value from a donor implementation or an owner decision; anything
  else is a constructor/config argument.
- **No silent failure.** An outcome is `fetched | blocked | shop_error |
  upstream_error`; a blocked page is never an empty result.

## 7. Proxy service design

- One service (`pluck-proxy`) behind Caddy. It calls FlareSolverr (Docker
  internal) and falls back to its own Playwright Chromium for SPA shops; plain
  HTTP when no challenge is needed.
- **Strip modes** (every mode extracts every
  `<script type="application/ld+json">` into the envelope's `json_ld` array
  and keeps `<title>` + meta description/OG):
  - `conservative` (default): remove styles, tracker/analytics scripts,
    iframes, cookie banners, nav/footer boilerplate — **keep the DOM
    structure** so site-specific xPaths written against full HTML keep
    matching.
  - `xpath`: the consumer sends its stored keep-expressions (`keep_xpaths`,
    named map) and the proxy returns **only the matching fragments**
    (`fragments: {name: [...]}` + `kept_counts`). The efficient path the
    owner asked for: little crosses the wire, PHP never parses a full page.
    Expressions are validated before evaluation (safe-function guard — the
    concept exists in NutriCompare as `XPathExpressionGuard`; reject
    expressions using unsafe functions), matched-node count and per-fragment
    size are capped, and **zero matches does not fail the request** — it
    stays outcome `fetched` with `kept_counts: {name: 0}`, so a stale XPath
    is visible to the consumer's own extraction-health tooling instead of
    masquerading as "no data" or "blocked".
  - `readability`: main content only, for consumers that ask for it.
  - `none`: raw HTML (escape hatch; used to seed new xPath profiles).
  - `preserve_scripts: true` option (with `conservative`/`none`) for Magento
    `window.dataLayer` and similar (Inktomi uses that fallback).
- **Proxy-side target validation**: public http(s) targets only (no private
  ranges from the proxy's vantage), no credentials in URLs, same-host
  redirects only (bounded), response size cap, per-consumer rate limits,
  per-domain politeness budget with Inktomi's floors as defaults.
- **Caching**: optional, keyed on URL hash, TTL supplied per request
  (`ttl_seconds`); price URLs are typically fetched with `ttl_seconds: 0`.
- **Logs**: url, outcome, renderer, elapsed — never tokens, never stripped
  content.
- Language is the implementing agent's choice (Python FastAPI is the natural
  fit next to FlareSolverr/Inktomi); **acceptance is the contract test
  suite**, not the language.

## 8. Security

- **Front door (mandatory)**: FlareSolverr and the proxy bind to the Docker
  internal network / localhost only. Caddy exposes one HTTPS endpoint with a
  secret path (`handle_path /<SECRET>/*` → proxy; everything else 404), an
  optional IP allowlist of the webhosts' egress IPs, and a rate limit.
  Consumers use `https://scraper.<domain>/<SECRET>/v1`. Rotation = change the
  Caddy prefix + two settings; access logging of the path is disabled.
- **No consumer HTML trust**: the PHP side treats returned HTML as untrusted
  input (it is shop content); extraction validates before storing.
- **Secrets**: tokens in env only, never in the repo; every new env key is
  documented in the consumers' `.env.example`.
- **Cutover gates** (two, in order):
  1. *Oracle gate* — the Oracle VM is removed only after each consumer has
     verified the new front door from its own host (POST with and without
     the secret path → a real solution and a 404), and after
     `scraper:check-egress-ip` has been retired/rewritten so it cannot
     false-alarm daily.
  2. *Inktomi gate* — Inktomi's last run happens only after the PHP bulk
     spider has delivered a **full publisher build pushed end-to-end** to
     both consumers (the thing Inktomi built but never exercised live),
     followed by a one-time schema migration to the VPS (dump/restore with
     MySQL 8.4 → MariaDB collation conversion — the conversion procedure is
     already documented in the 2026-11-08 production handoff).

## 9. Out of scope — never in this repository

- Any **second mapping or second import service**; every consumer keeps its
  own importer (`CatalogImportService` / `SuperRatenPackImporter`).
- **Direct writes** from the proxy or spider into either production database.
- Site-specific **extraction** (xPath tables, per-chain configs) and site
  **orchestration** (what to fetch, when) — those stay per-app by design.
- Scheduling the bulk crawler on shared hosting; bulk crawling on the
  webhosts.
- Reversing documented decisions silently: D-1 (scraping outside the app),
  the `spider.respect_robots` switch, the permissive inbound AI-crawler
  policy. Changes to those are owner decisions, recorded in the consumer
  repos' decision logs.

## 10. Recommendations (review agent, 2026-11-14)

1. **Sequence over leap**: audit (what is actually wired in the PHP stack) →
   proxy + contract (new, testable from day one) → PHP client SDK → consumer
   adoption one app at a time → PHP bulk spider (adapting Inktomi's
   behaviours, §4) → phase Inktomi out through the gate in §8.
2. **The highest-value gap is not fetching**: it is NutriCompare's ingest
   endpoint (phase 2 of `SPIDER_DATA_SYNC.md`). Until it exists, prices here
   only move when a person re-loads a dump — regardless of which proxy
   fetches.
3. **Fix the stranded reverse channel** so the sites actually steer the
   spider (owner benefit #1 in §2).
4. **Defer Redis** until a queue need is measured.
5. **Prune `raw_html` with a retention policy** at the schema migration
   (46.8 GB already in `superraten`; the transport never exports it).
6. **Retire dead code deliberately**: `ScraperClient` (owner declined
   deletion — revisit once the proxy replaces its role) and the .NET
   generation of Inktomi once confirmed dormant.

## 11. Open questions for the owner

1. One.com tier **disk size** — the proxy-only transition needs little, but
   the PHP-spider cutover must fit ~112 GB + growth; verify the tier spec
   before ordering.
2. **Relevance gate**: start rules-based on the VPS (JSON-LD Product + EAN +
   price ⇒ relevant) and measure false positives, or invest in a small local
   model now? Recommendation: rules-based first; revisit with measurements.
3. Should WelZoLekker's folder-deals scraper keep its direct-Chromium path as
   a fallback, or go proxy-only?
4. Versioning/tag policy for the package (`0.x` until both consumers run it?)
5. Which PHP matrix for CI (8.3 + 8.4 assumed)?
6. After the Inktomi gate: is the owner's PC fully out of the pipeline, or
   does anything remain there?
