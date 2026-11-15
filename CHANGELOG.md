# Changelog

## Unreleased (0.1.0 candidate — not tagged)

First implementation of the PHP SDK. See `docs/BUILD_NOTES.md` for contents,
deviations from the design and verification status.

- `ProxyClient` / `RenderRequest` / `Envelope` / `Outcome` per PROXY_CONTRACT v1
  (fail-closed parsing; only `fetched` may produce data).
- Direct fetch: `PoliteFetcher` (SSRF guard + connection pin, capped body,
  bounded same-site redirects), `HttpSourceDriver`, `FlareSolverrSourceDriver`,
  `ChromiumSourceDriver`, `DirectSourceChain`.
- `BlockDetector` (port of Inktomi), `PoliteDomainThrottle`, `CircuitBreaker`,
  `UserAgent`.
- `SiteProbe` and `bin/pluck probe`: does a site need FlareSolverr/a browser?
- Supports Guzzle `^7.10 || ^8.0`, PHP `^8.3`.
