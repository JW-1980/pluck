# Build notes — PHP SDK `0.1.0` (not tagged)

What was built from `MIGRATION_PLAN.md` phase 2 (plus the direct fetch drivers
and the site probe), what was deliberately **not** built, where the build
deviates from the design docs, and what is verified. Written 2026-11-15.

## Built

| Area | Classes | Source of truth |
|---|---|---|
| Proxy client | `Client\ProxyClient`, `RenderRequest`, `Envelope`, `Outcome`, `StripMode`, `InvalidRequest`, `RateLimited`, `PluckException` | `PROXY_CONTRACT.md` §2–§5 |
| Direct fetch | `Http\PoliteFetcher`, `CappedStream`, `BoundedBody`, `HttpSourceDriver`, `FlareSolverrSourceDriver`, `ChromiumSourceDriver`, `DirectSourceChain`, `SourceResult` | FUNCTIONAL_DESIGN §4 #3, #4; WelZoLekker `ProductPageFetcher`; Inktomi `fetcher.py` |
| Guards | `Support\TargetGuard` (SSRF), `BlockDetector` (port of Inktomi `BlockDetection.cs`), `UserAgent` | INVENTORY §2; PROXY_CONTRACT §6 |
| Politeness | `Throttle\PoliteDomainThrottle` (3 s / 1 s / 2.1 s OFF / 10-min cooldown), `CircuitBreaker` (120 s) | Inktomi `config.py`, `fetcher.py` |
| Site probe | `Probe\SiteProbe`, `ContentSignals`, `bin/pluck probe` | owner request 2026-11-15, see `SITE_PROBE.md` |

Every constant traces to a recorded donor value or the contract; none is invented.

## Not built (and why)

- **The proxy service** (`proxy/`, MIGRATION_PLAN phase 1). The SDK is written
  against the contract and tested with canned envelopes (the phase 2 gate);
  there is no running proxy yet, so `ProxyClient` has **never talked to a real
  proxy**. Until phase 1 exists, consumers can use the direct chain
  (`DirectSourceChain`) against FlareSolverr.
- **Phase 0 wiring audit / extraction from NutriCompare.** NutriCompare's source
  was not available in this build environment, so nothing was extracted from it.
  `TargetGuard`, `PoliteFetcher` and the throttle are written fresh from the
  *documented* behaviour in INVENTORY §2, not ported from `GuardedClient`,
  `ShopRateLimiter` or `SourceResolver`. `Contracts\SourceDriver` is therefore
  **not** a port of NutriCompare's `SourceDriver`/`SourceRequest`/`SourceOutcome`
  — adopting it there means adapting that app's drivers, and the audit should
  decide which donor behaviour must be reconciled.
- **Laravel integration layers** (service providers/config) — by design they live
  in the consumer repos.
- **Paid scraping API drivers** (`Drivers/`, "later phases").
- **A stealth browser.** `ChromiumSourceDriver` is plain headless Chromium
  (`--dump-dom`, WelZoLekker's flags). Stealth rendering belongs to the proxy's
  Playwright rung.

## Deviations from the design docs (to be recorded as decisions by the owner)

1. **Guzzle constraint is `^7.10 || ^8.0`, not `^7.10`.** README/INTEGRATION
   called Guzzle ^7.10 "common ground", but WelZoLekker's `composer.lock` pins
   `guzzlehttp/guzzle 8.3.0` + `psr7 3.2.0` (Laravel 13 allows `^7.8.2 || ^8.0`).
   `^7.10` alone makes the package uninstallable there. The suite passes on 7.10.6
   and 8.5.0. NutriCompare's Guzzle version was not checked (INVENTORY says ^7.10).
2. **`stream => true` is not used.** Guzzle routes streamed requests through its
   PHP stream handler, which (a) ignores `CURLOPT_RESOLVE` — Guzzle 7 silently,
   Guzzle 8 with an exception — so DNS pinning would never have applied, and
   (b) cannot cap a body mid-transfer. Bodies go through `CappedStream` instead.
   (The donors' `ProductPageFetcher` uses `stream => true`; it caps after the fact
   and does not pin.)
3. **Mid-transfer abort uses a short write, not an exception.** Measured: PHP
   defers an exception thrown inside a cURL write callback until the transfer
   ends (a 5 MB body took the full 4 s). A short write aborts immediately.
4. **Refused target ≠ shop error.** A private/invalid target throws
   `InvalidRequest('private_target')` (a caller bug, matching the proxy's `400`);
   unreachable/oversize/bad-redirect map to `shop_error`; `upstream_error` is
   reserved for the renderer stack. The contract has no outcome for "transport
   failed before any shop answered"; `shop_error` with a `transport:` error was
   the least misleading existing value. Open question for the contract.
5. **Redirect rule.** Same host or its `www.`-toggle (the donors' "same domain"),
   never https→http, ≤ 3 hops. This refuses legitimate TLD moves such as
   `hoogvliet.com → hoogvliet.nl`; the probe reports those as `moved_host`.
6. **FlareSolverr "challenge" error messages are treated as blocks.** An `error`
   reply whose message contains "challenge" (FlareSolverr's "Error solving the
   challenge. Timeout …") is `blocked`, not an outage, and does not open the
   breaker. This is a heuristic on message text, not a documented contract.
7. **No atomic throttle.** `PoliteDomainThrottle` runs on any PSR-16 store, which
   has no atomic read-modify-write; with several workers two can book one slot.
   The consumer binds a locking store (e.g. Laravel's atomic cache) when it matters.

## Verification (this session, 2026-11-15, PHP 8.3.6)

| Check | Result |
|---|---|
| `vendor/bin/phpunit` — 176 unit + 7 integration tests | **passed**, on Guzzle 7.10.6 **and** 8.5.0 |
| Integration tests (real sockets via `php -S`) | gzip bomb, declared and chunked oversize, **mid-transfer abort timing**, DNS pin (with a pin-off negative control), redirects |
| Mutation checks (break the code, expect red) | cap, pin, 404 rule, envelope content rule, throttle wait, redirect rule, CGNAT range, FlareSolverr double-status judgement, chain `shop_error` stop, lenient error decode: **all caught**. An earlier survivor (cap disabled, tests still green) exposed the false-green and led to the timing test |
| `bin/pluck probe` against live sites | ran from the build sandbox — see `SITE_PROBE.md` for what that does and does not prove |

**Not verified:** PHP 8.4 (WelZoLekker's floor); any real FlareSolverr instance
(the driver is tested against canned replies of the documented reply shape only);
any real `pluck-proxy`; installation inside either consumer app; NutriCompare
at all; a probe run from mijn.host.
