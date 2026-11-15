<?php

declare(strict_types=1);

namespace Pluck\Tests\Support;

use Pluck\Contracts\Clock;

final class FakeClock implements Clock
{
    public function __construct(public float $now = 1_000_000.0) {}

    public function now(): float
    {
        return $this->now;
    }

    public function advance(float $seconds): void
    {
        $this->now += $seconds;
    }
}
