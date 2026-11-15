<?php

declare(strict_types=1);

namespace Pluck\Support;

final readonly class BlockVerdict
{
    private function __construct(public bool $blocked, public ?string $signal) {}

    public static function notBlocked(): self
    {
        return new self(false, null);
    }

    /** @param string $signal concrete observed evidence: "HTTP 403" or the matched marker */
    public static function blocked(string $signal): self
    {
        return new self(true, $signal);
    }
}
