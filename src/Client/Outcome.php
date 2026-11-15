<?php

declare(strict_types=1);

namespace Pluck\Client;

/**
 * The anti-silent-failure rule in code form (PROXY_CONTRACT §4): only
 * {@see Outcome::Fetched} may produce data. Every other outcome is a distinct,
 * loggable state and must never be recorded as "page had no data".
 */
enum Outcome: string
{
    case Fetched = 'fetched';
    case Blocked = 'blocked';
    case ShopError = 'shop_error';
    case UpstreamError = 'upstream_error';

    public function mayParseContent(): bool
    {
        return $this === self::Fetched;
    }
}
