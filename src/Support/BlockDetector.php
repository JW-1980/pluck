<?php

declare(strict_types=1);

namespace Pluck\Support;

/**
 * Port of Inktomi's `BlockDetection.Evaluate` (src/Inktomi.Scraping/Challenge/
 * BlockDetection.cs): decides whether a response is a bot-protection refusal
 * rather than the document it claims to be.
 *
 * Behaviour carried over deliberately:
 *  - 404/410 settle "no document here" by status alone and outrank any body
 *    wording ("Access Denied" is standard text in ordinary 404 templates).
 *  - Challenge markers are scanned for every other status, including 200: an
 *    interstitial served with 200 is the classic silent poison.
 *  - A marker verdict beats a bare status verdict (it names the vendor).
 *  - Only the first {@see SCAN_CHAR_LIMIT} characters are scanned.
 *
 * Known limit (same as the donor): a genuine page that merely *mentions* one of
 * the marker phrases in its first 64 KB is a false positive. Callers that can
 * prove a genuine document (e.g. a JSON-LD Product) should say so via
 * `$genuineDocument` rather than weakening the marker list.
 */
final class BlockDetector
{
    public const SCAN_CHAR_LIMIT = 65_536;

    /** 403/429, Cloudflare under-attack 503, and Cloudflare edge codes 520-527. */
    private const BLOCKING_STATUSES = [403, 429, 503, 520, 521, 522, 523, 524, 525, 526, 527];

    private const ABSENT_DOCUMENT_STATUSES = [404, 410];

    private const CHALLENGE_MARKERS = [
        'just a moment',
        'cf-browser-verification',
        'cf_chl_',
        '__cf_chl',
        'challenge-platform',
        'checking your browser before accessing',
        'attention required! | cloudflare',
        'enable javascript and cookies to continue',
        'ddos-guard',
        '_incapsula_resource',
        'request unsuccessful. incapsula incident',
        'px-captcha',
        'perimeterx',
        'sucuri website firewall',
        'access denied',
        'cf-mitigated',
        'cloudflare ray id',
    ];

    public function evaluate(int $httpStatus, ?string $body, bool $genuineDocument = false): BlockVerdict
    {
        if (in_array($httpStatus, self::ABSENT_DOCUMENT_STATUSES, true)) {
            return BlockVerdict::notBlocked();
        }

        if (! $genuineDocument) {
            $scanned = $body === null ? '' : substr($body, 0, self::SCAN_CHAR_LIMIT);
            foreach (self::CHALLENGE_MARKERS as $marker) {
                if (stripos($scanned, $marker) !== false) {
                    return BlockVerdict::blocked($marker);
                }
            }
        }

        if (in_array($httpStatus, self::BLOCKING_STATUSES, true)) {
            return BlockVerdict::blocked('HTTP '.$httpStatus);
        }

        return BlockVerdict::notBlocked();
    }
}
