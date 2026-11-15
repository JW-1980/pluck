<?php

declare(strict_types=1);

namespace Pluck\Http;

/** Raw result of one guarded HTTP exchange, before any block judgement. */
final readonly class Fetched
{
    /** @param array<string,string> $headers lower-cased name => value */
    public function __construct(
        public int $status,
        public string $finalUrl,
        public string $body,
        public array $headers = [],
        public int $redirects = 0,
    ) {}
}
