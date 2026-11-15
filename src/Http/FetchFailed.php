<?php

declare(strict_types=1);

namespace Pluck\Http;

use RuntimeException;

/** Transport-level failure (DNS, TLS, timeout, oversize, bad redirect). Not a shop answer. */
final class FetchFailed extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
