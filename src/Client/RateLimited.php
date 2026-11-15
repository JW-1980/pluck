<?php

declare(strict_types=1);

namespace Pluck\Client;

/** HTTP 429 from the proxy: this consumer is over its budget. */
final class RateLimited extends PluckException
{
    public function __construct(public readonly ?int $retryAfterSeconds, ?\Throwable $previous = null)
    {
        parent::__construct(
            'The Pluck proxy rate-limited this consumer'.($retryAfterSeconds !== null ? " (retry after {$retryAfterSeconds}s)" : '').'.',
            429,
            $previous,
        );
    }
}
