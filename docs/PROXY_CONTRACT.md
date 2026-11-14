# Proxy contract v1

The wire contract between the consumers' PHP SDK (`Pluck\Client\ProxyClient`)
and the `pluck-proxy` service. Both sides implement this document; the
contract test suite (`proxy/tests/`) is the shared acceptance test. Rules for
evolution: **additive fields only within v1**; any breaking change is a new
path (`/v2/…`) and a new `contract_version`.

## 1. Endpoint and transport

```
POST https://scraper.<domain>/<SECRET>/v1/render
Content-Type: application/json
```

- The `<SECRET>` path segment is the Caddy front door (see
  FUNCTIONAL_DESIGN §8). Requests without it get `404`; the proxy itself is
  never reachable directly from the internet.
- Everything below is JSON request/response bodies.

## 2. Request

| Field | Type | Default | Constraints |
|---|---|---|---|
| `url` | string | required | public `http(s)` only, no credentials in URL, ≤ 2048 chars |
| `strip` | string | `"conservative"` | one of `none` \| `conservative` \| `readability` \| `xpath` |
| `keep_xpaths` | map name → expression | — | required iff `strip = "xpath"`; ≤ 16 names; name `[a-z0-9_]{1,32}`; expression ≤ 500 chars |
| `preserve_scripts` | bool | `false` | keep non-tracking inline scripts (Magento `dataLayer` etc.); valid with `conservative`/`none` |
| `max_timeout_ms` | int | `60000` | ≤ 90000 |
| `wait_ms` | int | `0` | ≤ 5000; settle time after load |
| `ttl_seconds` | int | `0` | ≤ 86400; cache TTL (0 = no cache; price URLs use 0) |
| `follow_redirects` | bool | `true` | same-host redirects only, ≤ 3 hops |

Validation failures are `400` with an `error` code (`invalid_url`,
`private_target`, `invalid_xpath`, `too_many_xpaths`, `invalid_options`).

## 3. Response (success envelope)

```json
{
  "contract_version": 1,
  "request_id": "b3f2…",
  "outcome": "fetched",
  "shop_status": 200,
  "final_url": "https://shop.example/product/123",
  "title": "…",
  "html": "<div class=\"product\">…",
  "json_ld": [{ "@type": "Product", … }],
  "fragments": { "price": ["<span…</span>"], "title": ["<h1>…</h1>"] },
  "kept_counts": { "price": 1, "title": 1 },
  "retry_after_seconds": null,
  "meta": {
    "proxy_version": "0.1.0",
    "stripper_version": "0.1.0",
    "renderer": "flaresolverr",
    "elapsed_ms": 4312,
    "cache": "miss",
    "attempts": 1
  }
}
```

Field presence by strip mode:

| | `none` | `conservative` | `readability` | `xpath` |
|---|---|---|---|---|
| `html` | raw | cleaned | main content | **absent** |
| `fragments` + `kept_counts` | absent | absent | absent | **present** |
| `json_ld`, `title`, `meta` | always | always | always | always |

`fragments[name]` is an array of the matched nodes' serialized inner HTML, in
document order, capped at 50 nodes per name and 64 KB per node (overlong
nodes are truncated and marked with an `<!--truncated-->` comment). Total
`fragments` payload is capped at 1 MB; total `html` payload at 3 MB (the
recorded donor cap) — exceeding it yields outcome `shop_error` with error
code `content_too_large`.

## 4. Outcomes — the rule consumers must implement

| Outcome | Meaning | PHP may parse content? |
|---|---|---|
| `fetched` | Page obtained; expressions (if any) evaluated. **Zero matches is still `fetched`** (`kept_counts: {name: 0}`). | Yes — but zero kept counts are an extraction-health signal, not "no data". |
| `blocked` | Challenge/block detected after renderer attempts. Content absent. `retry_after_seconds` hints when to try again. | **Never** treat as "page had no price". |
| `shop_error` | Origin answered 4xx/5xx that is not a block (404, 410, 500…). `shop_status` carries it. | Only to record the shop's state. |
| `upstream_error` | Renderer stack failure (FlareSolverr down, Chromium crash). | Never. |

This table is the anti-silent-failure rule in wire form: **only `fetched`
may produce data, and every other outcome is a distinct, loggable,
non-empty-looking state.**

## 5. Error responses

Same envelope shape, without content fields:

| HTTP | When |
|---|---|
| `400` | validation (§2) |
| `404` | missing secret path (front door) |
| `413` | request body over cap |
| `429` | consumer over its budget; `retry_after_seconds` set |
| `502` | `upstream_error` |
| `504` | `max_timeout_ms` exceeded |

## 6. Proxy-side guarantees

- **Target validation**: public IP only (screened from the proxy's vantage —
  no private/link-local/metadata targets), `http(s)` only, no credentials in
  URLs, same-host redirects only (≤ 3), response size caps.
- **Politeness**: per-domain spacing ≥ 3 s and global spacing ≥ 1 s by
  default (Inktomi's recorded floors, overridable per consumer **upward
  only**); a 10-minute per-domain cooldown after blocks, during which
  requests to that domain get outcome `blocked` with
  `retry_after_seconds` without touching the shop.
- **Renderer chain**: plain HTTP when no challenge is indicated →
  FlareSolverr → Playwright Chromium fallback; the chain and per-attempt
  behaviour mirror Inktomi's `fetcher.py` (see FUNCTIONAL_DESIGN §4).
- **No persistence of shop state**: no cookie jars by default; FlareSolverr
  sessions, if introduced, are short-lived and explicit (v2 concern).
- **Logs**: url, outcome, renderer, elapsed, request_id, consumer label —
  never tokens, never content bodies.

## 7. Caching

Keyed on a hash of `(url, strip, keep_xpaths, preserve_scripts,
follow_redirects)`; TTL = `ttl_seconds`; a cache hit serves the stored
envelope with `meta.cache = "hit"` and does **not** touch the shop (and does
not consume politeness budget). `ttl_seconds: 0` bypasses both read and
write.

## 8. XPath evaluation rules (strip mode `xpath`)

- Expressions are evaluated with lxml against the rendered DOM only — never
  against any data store; "injection" can therefore only select oddly, but
  expressions are still validated before use.
- Allowed: location paths, predicates, the core XPath 1.0 function set.
  Rejected (mirroring the donor guard `XPathExpressionGuard`): extension
  functions, document/XSLT constructs, expressions exceeding a complexity
  budget. Validation failure is `400 invalid_xpath` — a consumer bug, not a
  scrape failure.
- Namespaces: common XHTML/HTML namespaces are pre-registered; a request may
  not declare its own in v1.
- The proxy never "fixes" a stale expression: zero matches is reported as
  `kept_counts: 0` and nothing else.
