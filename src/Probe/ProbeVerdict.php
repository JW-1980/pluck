<?php

declare(strict_types=1);

namespace Pluck\Probe;

/** What a site needs, judged from the evidence of one probe run. */
enum ProbeVerdict: string
{
    /** A plain guarded HTTP GET (what PHP on the webhost does) returned a usable product page. */
    case PlainHttp = 'plain_http';

    /** Plain HTTP works but the content is a JavaScript shell; a rendering browser is needed. */
    case NeedsJavascript = 'needs_javascript';

    /** Plain HTTP is blocked; FlareSolverr got through. */
    case NeedsFlareSolverr = 'needs_flaresolverr';

    /** Plain HTTP is blocked; an ordinary (non-stealth) headless browser got through, FlareSolverr was not tried or did not. */
    case NeedsBrowser = 'needs_browser';

    /** Every rung that was tried was blocked or failed. */
    case BlockedEverywhere = 'blocked_everywhere';

    /** Plain HTTP is blocked and no stronger rung was available to test. */
    case PlainBlockedUntested = 'plain_blocked_untested';

    /** The URL redirects to another host (e.g. a changed domain); the guard refuses to follow it. */
    case MovedHost = 'moved_host';

    /** The page could not be reached at all (DNS, TLS, timeout, 5xx). */
    case Unreachable = 'unreachable';

    /** Plain HTTP answered 200 but shows no product signals: probably not a product URL. Probe a product page. */
    case Inconclusive = 'inconclusive';

    public function advice(): string
    {
        return match ($this) {
            self::PlainHttp => 'Plain HTTP from this host is enough. No FlareSolverr and no browser needed.',
            self::NeedsJavascript => 'The page is a JavaScript shell. Plain HTTP is not enough: use the proxy renderer (or a rendering browser).',
            self::NeedsFlareSolverr => 'Plain HTTP is blocked from this host, FlareSolverr gets through. Route this domain through the proxy.',
            self::NeedsBrowser => 'Plain HTTP is blocked from this host, but a real headless browser gets through without any stealth patches. Route this domain through the proxy renderer; FlareSolverr is likely to work too (also Chromium-based) but was not confirmed.',
            self::BlockedEverywhere => 'Everything tried was blocked. The next rungs (stealth Chromium, a residential/managed scraping API) are not covered by this probe.',
            self::PlainBlockedUntested => 'Plain HTTP is blocked from this host. Re-run with a FlareSolverr endpoint (and/or a Chromium binary) to see whether a stronger rung works.',
            self::MovedHost => 'The URL redirects to a different host, which Pluck refuses to follow (same-site redirects only). Update the stored URL to the final address.',
            self::Unreachable => 'The shop could not be reached. Check the URL, DNS and the egress of this host.',
            self::Inconclusive => 'Plain HTTP returns a page, but it carries no product markup (JSON-LD / price). This is expected for category, promo and home pages; probe an actual product URL to decide whether product data is in the HTML or loaded by JavaScript.',
        };
    }
}
