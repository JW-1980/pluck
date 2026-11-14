# Pluck

Shared spider **fetch primitives** for two Laravel comparison sites:

- **NutriCompare** (`C:\Projecten\Nutricompare`, Laravel 12, PHP ^8.3, supplements)
- **watvindenwijvan.nl / WelZoLekker** (`C:\Projecten\welzolekker`, Laravel 13, PHP ^8.4.1, supermarket food)

## Scope

**In:** the fetch layer that is genuinely identical for both sites —
source drivers (FlareSolverr, Browserless, managed scraping APIs), polite HTTP
fetching (bounded redirects, body caps, timeouts), SSRF guarding, user-agent
handling, per-domain rate limiting, circuit breakers, block/challenge
detection and backoff.

**Out — and this is the constitution, not a suggestion:**

- **Orchestration** — which URLs to fetch and when is each site's own decision
  (NutriCompare's refresh plan / WelZoLekker's weekly folder scraper). It does
  not move here.
- **Extraction** — per-site by nature (supplement xPaths ≠ supermarket
  nutrition tables). Site-specific extraction stays in the site repos.
- **Transport / ingestion** — data reaches each site through its own publisher
  contract; nothing here writes to either site's database.
- **A second mapping or a second import service** — every consumer-side import
  keeps going through the importer that consumer already has.

## Decisions recorded (2026-11-14)

1. **One spider host**: a single VPS runs the bulk spider and FlareSolverr for
   both sites. The Oracle scraper VM (Browserless :3000 + FlareSolverr :8191)
   is retired once the cutover gate passes.
2. **One bulk spider today**: Inktomi (`C:\Projecten\Inktomi`, .NET 10 +
   Python) owns bulk crawling, the spider databases (`nutricomparev2`,
   `superraten`) and the publisher. Pluck does not replace it; it de-duplicates
   the fetch primitives the two PHP apps use for their own (narrow, throttled)
   scraping: live price refresh, shop feeds, promotions, corrections/rescrapes.
3. **FlareSolverr front door**: FlareSolverr runs on the Docker internal
   network only. The internet reaches it through one HTTPS endpoint with a
   secret path (and optionally an IP allowlist / rate limit), proxied by
   Caddy. FlareSolverr itself has no authentication; the front door is
   mandatory. On-VPS consumers (Inktomi) use plain `127.0.0.1:8191`.
4. **Politeness budget**: the shared VPS IP inherits Inktomi's recorded floors
   (3 s per domain, 1 s global, 10-minute per-domain cooldown after blocks).
   No consumer invents a fourth rate-limiting regime.
5. **Cutover gate**: the Oracle VM is only removed after each consumer has
   verified the new front door from its own host (POST with and without the
   secret path: expect a real solution and a 404).

## Platform

- The core is **framework-agnostic** (Guzzle + PSR-16/17/18 interfaces). The
  two consumer apps sit on different Laravel majors (12 and 13), so no
  `laravel/framework` requirement belongs here; each app keeps a thin
  integration layer (service provider, config mapping) in its own repo.
- Common ground: PHP ^8.3, Guzzle ^7.10.

## Naming note (recorded tradeoff)

`pluck` collides with Laravel Collection's `pluck()` method in autocomplete
and search. The composer name `jw-1980/pluck` and the `Pluck\` namespace are
accepted tradeoffs; renaming later is cheap now and expensive after the first
consumers land.

## Roadmap

1. **Wiring audit of NutriCompare's in-app spider stack** (`app/Services/Spider`,
   `Crawler`, `Extraction`, `Scheduling`) — what is scheduled, what is
   reachable, what is dead. Nothing is extracted into Pluck before this audit
   says the code is worth sharing.
2. **First extraction candidates** (highest-proven duplication):
   - the FlareSolverr client, which exists three times today (NutriCompare's
     `FlareSolverrSourceDriver`, WelZoLekker's `ProductPageFetcher` /
     `PromotionPageFetcher`, Inktomi's `fetcher.py`) with different body caps,
     redirect rules and failure semantics;
   - polite-fetch primitives (`PoliteFetcher`, `GuardedClient`).
3. Consumers adopt the package one app at a time, tests first.
