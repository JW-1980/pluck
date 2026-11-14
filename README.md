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
2. **One spider, end state Pluck (PHP)**. Inktomi is **phased out**: it stays
   on the owner's PC (its data with it) until the PHP bulk spider replaces
   it, and its good behaviours are the adaptation spec
   (`docs/FUNCTIONAL_DESIGN.md` §4). Pluck's proxy + client are the vehicle.
   The Oracle scraper VM (Browserless :3000 + FlareSolverr :8191) is retired
   first, after its own cutover gate.
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

## Documentation

| Document | Contents |
|---|---|
| `docs/INVENTORY.md` | Verified ground truth: the three existing codebases, who fetches what, transport status, data volumes, the duplication map |
| `docs/FUNCTIONAL_DESIGN.md` | The build brief: owner requirements as stated (traceability), target architecture, Inktomi's behaviour spec (what we adapt), the division of labour, security, recommendations, open questions |
| `docs/PROXY_CONTRACT.md` | The wire contract v1 between the PHP SDK and `pluck-proxy` — strip modes incl. `xpath` keep-expressions, outcome taxonomy, politeness, caching |
| `docs/MIGRATION_PLAN.md` | Ordered, gated phases for the implementing agent, with hard rejects |
| `docs/INTEGRATION.md` | Composer adoption for both consumer apps |

## Roadmap

Implementation is ordered and gated in `docs/MIGRATION_PLAN.md`; in brief:

1. **Wiring audit** of NutriCompare's in-app spider stack — gates all
   extraction.
2. **Proxy service + contract v1** (in this repo, Dockerised, contract tests).
3. **PHP client SDK** (`ProxyClient`, typed outcomes).
4. **Consumer adoption**, one app at a time, tests first.
5. **Oracle cutover** (gate 1), then the **PHP bulk spider** adapting
   Inktomi's behaviours, then **Inktomi phase-out** (gate 2: a full
   publisher build delivered end-to-end to both consumers).
