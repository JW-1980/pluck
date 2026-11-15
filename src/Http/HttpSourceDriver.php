<?php

declare(strict_types=1);

namespace Pluck\Http;

use Pluck\Client\InvalidRequest;
use Pluck\Client\Outcome;
use Pluck\Contracts\SourceDriver;
use Pluck\Support\BlockDetector;

/** Plain HTTP rung: a guarded GET judged by {@see BlockDetector}. */
final class HttpSourceDriver implements SourceDriver
{
    public function __construct(
        private readonly PoliteFetcher $fetcher = new PoliteFetcher(),
        private readonly BlockDetector $detector = new BlockDetector(),
    ) {}

    public function name(): string
    {
        return 'http';
    }

    public function fetch(string $url): SourceResult
    {
        try {
            $page = $this->fetcher->fetch($url);
        } catch (FetchFailed $e) {
            if ($e->reason === 'blocked_target') {
                // A caller bug (private/invalid target), not something the shop did.
                throw new InvalidRequest($e->getMessage(), 'private_target', $e);
            }

            // Unreachable shop, timeout, oversize, bad redirect: explicit, non-data, never "empty".
            return new SourceResult(Outcome::ShopError, $this->name(), error: $e->reason.': '.$e->getMessage());
        }

        return self::judge($this->name(), $page->status, $page->finalUrl, $page->body, $this->detector, $page->headers['retry-after'] ?? null);
    }

    /** Shared by every driver so a block is judged the same way everywhere. */
    public static function judge(string $renderer, int $status, string $finalUrl, string $body, BlockDetector $detector, ?string $retryAfter = null): SourceResult
    {
        $verdict = $detector->evaluate($status, $body);
        if ($verdict->blocked) {
            return new SourceResult(
                outcome: Outcome::Blocked,
                renderer: $renderer,
                shopStatus: $status,
                finalUrl: $finalUrl,
                blockSignal: $verdict->signal,
                retryAfterSeconds: $retryAfter !== null && ctype_digit(trim($retryAfter)) ? (int) trim($retryAfter) : null,
            );
        }

        if ($status < 200 || $status >= 300) {
            return new SourceResult(Outcome::ShopError, $renderer, $status, $finalUrl, error: "Shop answered HTTP {$status}.");
        }

        return new SourceResult(Outcome::Fetched, $renderer, $status, $finalUrl, $body);
    }
}
