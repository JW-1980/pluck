# Migration plan — for the implementing agent

Ordered, gated phases. Each phase has a definition of done; a phase starts
only when the previous one's gate passes. The donor facts this plan relies on
are in `docs/INVENTORY.md` (verified 2026-11-14); the design is
`docs/FUNCTIONAL_DESIGN.md`; the wire contract is `docs/PROXY_CONTRACT.md`.

## 0. Rules of engagement

1. **No invented numbers.** Every constant traces to a donor implementation
   or an owner decision; otherwise it is a config argument with a documented
   default.
2. **No silent failure.** Blocked/errored fetches are explicit outcomes,
   never empty results, never empty catch blocks.
3. **Tests first.** Every ported behaviour gets parity tests against the
   donor implementation before a consumer switches to it.
4. **Framework-agnostic core.** No `laravel/framework` requirement; Laravel
   integration lives in the consumer repos.
5. **Donors keep working at every commit.** Extraction is additive; consumers
   switch over explicitly, one per phase.
6. **No second mapping.** Pluck never imports catalogue data and never writes
   to either production database.
7. **One concern per commit**; no formatting sweeps over donor repos.
8. **Decisions are recorded, never silent.** New decisions append to
   FUNCTIONAL_DESIGN §2; reversals need an owner statement.
9. **Secrets stay out of the repo.** Every new env key is documented in the
   consumers' `.env.example`.

## Phase 0 — wiring audit (gates everything)

Task: classify every service in NutriCompare's `app/Services/{Spider,Crawler,
Extraction,Scheduling}` plus their commands, jobs and controllers as
**wired** (scheduled or reachable from a scheduled entry point), **manual**
(only user/admin-triggered), or **dead** (unreachable). Evidence = file:line.
Include `ScraperClient` (known dead) and the test coverage per service.

Deliverable: `docs/audit/NUTRICOMPARE_AUDIT.md` in this repo, with a
verdict section: which pieces are worth extracting (candidates for
`Contracts/`, `Drivers/`, `Support/`), which are framework-coupled
(Eloquent/Illuminate dependencies — extraction blockers), and which are dead.

**Gate:** the audit exists with evidence, and extraction candidates are
listed. Nothing is extracted before this gate.

## Phase 1 — proxy service + contract v1

Build `proxy/` in this repo: the `pluck-proxy` service (language: agent's
choice; Python + lxml/FastAPI is the natural fit), a `Dockerfile`, a
`docker-compose.yml` snippet (caddy + pluck-proxy + flaresolverr), and the
contract test suite. Implement FUNCTIONAL_DESIGN §7 and the full contract:
strip modes `none/conservative/readability/xpath`, renderer chain (plain HTTP
→ FlareSolverr → Chromium), target validation, politeness floors (3 s
domain / 1 s global), block handling with 10-minute cooldowns and explicit
outcomes, caching, caps, error codes.

Fixture pages the contract tests must cover: plain HTML; challenge/block
page; SPA shell; JSON-LD-heavy product page; Magento `dataLayer` page;
page where `keep_xpaths` matches zero nodes (outcome stays `fetched`,
`kept_counts: 0`); oversized page (`content_too_large`); same-host vs
cross-host redirect; private/invalid targets.

**Gate:** contract tests green against a running compose stack; the Caddy
front-door config (secret path, no direct exposure) is in the repo and
documented.

## Phase 2 — PHP client SDK

`src/Client/ProxyClient` + typed `Envelope`/`Outcome` per
PROXY_CONTRACT §3–4; `Pluck\Support\UserAgent`; port `PoliteFetcher` /
`GuardedClient` semantics only where PHP still fetches directly (robots,
feeds — the boundary exceptions). Guzzle + PSR only.

**Gate:** unit tests with canned envelopes cover every outcome, every strip
mode, timeout, 429/Retry-After, malformed responses (fail closed, never
empty-success); tag `0.1.0`.

## Phase 3 — consumer adoption (one app at a time)

1. **NutriCompare**: a new source driver that delegates to `ProxyClient`
   (`strip = "xpath"` using the consumer's stored per-domain expressions);
   admin-configurable endpoint (Admin → Spider settings pattern); existing
   tests green; robots/feed paths untouched (they stay direct by design).
2. **WelZoLekker**: `ProductPageFetcher` gains a proxy transport behind
   config (`PROXY_URL`); the folder-deals scraper may follow (owner question
   FUNCTIONAL_DESIGN §11.3).

**Gate:** each consumer runs against a live proxy in a staging check from
its own host, and its own test suite passes unmodified except for the
explicitly switched transport.

## Phase 4 — Oracle cutover (gate 1)

Front-door verification from both webhosts (POST with and without the secret
path → real solution and 404). Retire/rewrite `scraper:check-egress-ip`;
repoint every admin source and env key; update the provisioning docs; only
then decommission the Oracle VM.

**Gate:** both consumers verified from their own hosts; Oracle removed; no
consumer references the Oracle addresses anymore (grep-verified).

## Phase 5 — PHP bulk spider on the VPS

**Own design document first** (in this repo), then build. It adapts
Inktomi's behaviours per FUNCTIONAL_DESIGN §4: sitemap-driven discovery, a
candidate-URL queue with the five states, cross-domain scheduling, the
renderer chain via the local proxy, the relevance gate (rules-based first —
see §11.2), soft-deletes with reasons, and the **publisher** (watermarks,
immutable sha256 chunks, tombstones) targeting both consumers' existing
endpoints.

**Gate:** a full catalogue build pushed end-to-end to **both** consumers and
applied by their own importers (`CatalogImportService` /
`SuperRatenPackImporter`) — the acceptance test Inktomi's publisher never
passed live.

## Phase 6 — Inktomi phase-out (gate 2)

With phase 5's gate passed: one final Inktomi run on the owner's PC; one-time
migration of the two spider schemas to the VPS (dump/restore, MySQL 8.4 →
MariaDB collation conversion per the 2026-11-08 handoff procedure; prune
`raw_html` per the retention policy during the move); Inktomi is archived,
not deleted; the owner's PC leaves the pipeline.

**Gate:** the PHP spider serves both sites alone for one full weekly cycle
without manual intervention on the owner's PC.

## What must never happen (hard rejects)

- A second mapping/import service, or spider-side writes to production DBs.
- Rendering or stripping logic in PHP (owner directive #13/#15).
- A blocked fetch recorded as "no data found".
- An XPath rewrite/repair inside the proxy — that is the consumer's
  extraction-health concern.
- New scraping providers, queues (Redis) or models without an owner decision
  and a measurement.
- Committing tokens, shop credentials, or `raw_html` anywhere in this repo.
