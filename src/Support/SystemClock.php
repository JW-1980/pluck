<?php

declare(strict_types=1);

namespace Pluck\Support;

use Pluck\Contracts\Clock;

final class SystemClock implements Clock
{
    public function now(): float
    {
        return microtime(true);
    }
}
