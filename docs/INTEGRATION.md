# Integrating Pluck into a consumer app

For the two consumer repos (NutriCompare, Laravel 12 / PHP ^8.3; WelZoLekker,
Laravel 13 / PHP ^8.4.1). The package is framework-agnostic — Laravel wiring
stays in each consumer.

## 1. Composer setup (VCS repository — no Packagist)

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/JW-1980/pluck.git"
        }
    ],
    "require": {
        "jw-1980/pluck": "^0.1"
    }
}
```

- The repo is reachable over HTTPS; if composer needs auth for a private
  repo, use `composer config github-oauth.github.com <token>` per machine —
  never commit a token.
- Version policy: `0.x` until both consumers run it in production; tags only,
  no branch aliases; `CHANGELOG.md` entry per tag (added by the implementing
  agent per MIGRATION_PLAN phase 2).

## 2. What each consumer adds (the thin integration layer)

A service provider that binds Pluck's contracts to consumer config — nothing
more:

**NutriCompare**

- Bind `Pluck\Contracts\*` to concrete classes configured from the existing
  Admin → Spider settings surface (a new source type "Pluck proxy": endpoint
  = `https://scraper.<domain>/<SECRET>`, auth mode already supported).
- The proxy becomes a source in `SourceResolver`'s chain — preferred source,
  with the existing paid-API drivers as fallback (owner decides when to
  retire those; see FUNCTIONAL_DESIGN §10.6).
- New env keys documented in `.env.example` (`PLUCK_PROXY_URL`, …).

**WelZoLekker**

- `config/welzolekker.php` gains `live_prices.proxy_url` (alongside the
  existing `flaresolverr_url`, which stays valid for direct FlareSolverr use
  until the cutover); `ProductPageFetcher` prefers the proxy transport when
  configured.
- New env keys documented in `.env.example`.

## 3. Upgrade flow

1. Read `CHANGELOG.md` for the new tag.
2. `composer update jw-1980/pluck` in the consumer repo.
3. Run the consumer's own test suite — it is the integration gate; a tag
   that breaks a consumer's tests unmodified is a package bug, not a
   consumer change.
4. Deploy per the consumer's normal process.

## 4. CI matrix (package repo)

- PHP 8.3 and 8.4 (the two consumers' floors).
- Tests without Laravel: the core must install and pass on a bare Guzzle
  project. If a consumer-side integration test is ever needed, it belongs in
  the consumer repo, not here.

## 5. Operational notes

- The proxy endpoint is `https://scraper.<domain>/<SECRET>/v1` — the secret
  path is part of the configured URL; rotating it means updating the Caddy
  prefix and the consumers' settings (two apps), nothing else.
- Consumers must implement PROXY_CONTRACT §4's outcome rule: only
  `fetched` produces data; `blocked`/`shop_error`/`upstream_error` are
  distinct, logged states. A consumer that logs "no price found" on
  `blocked` violates the no-silent-failure rule.
- Feeds, robots.txt and vendor URLs stay direct fetches (the documented
  boundary exception) — they do not go through the proxy.
