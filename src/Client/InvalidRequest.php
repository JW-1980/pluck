<?php

declare(strict_types=1);

namespace Pluck\Client;

/** A consumer bug: the request violates PROXY_CONTRACT §2 (client-side or HTTP 400). */
final class InvalidRequest extends PluckException
{
    public function __construct(string $message, public readonly ?string $errorCode = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
