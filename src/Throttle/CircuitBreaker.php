<?php

declare(strict_types=1);

namespace Pluck\Throttle;

use Pluck\Contracts\Clock;
use Pluck\Support\SystemClock;
use Psr\SimpleCache\CacheInterface;

/**
 * Inktomi's recorded renderer breaker: one failure of FlareSolverr disables it
 * for 120 s (fetcher.py), so a down renderer is skipped instead of retried in a
 * storm. The skip is visible to callers through {@see isOpen()} — callers must
 * report it (upstream_error), never pretend the renderer ran.
 */
final class CircuitBreaker
{
    public const OPEN_SECONDS = 120;

    public function __construct(
        private readonly CacheInterface $store,
        private readonly string $name,
        private readonly Clock $clock = new SystemClock(),
        private readonly int $openSeconds = self::OPEN_SECONDS,
    ) {}

    public function isOpen(): bool
    {
        return $this->remaining() !== null;
    }

    /** Seconds until the breaker closes, or null when closed. */
    public function remaining(): ?int
    {
        $until = $this->store->get($this->key());
        if (! is_int($until) && ! is_float($until)) {
            return null;
        }

        $left = (int) ceil($until - $this->clock->now());

        return $left > 0 ? $left : null;
    }

    public function recordFailure(): void
    {
        $this->store->set($this->key(), $this->clock->now() + $this->openSeconds, $this->openSeconds);
    }

    public function recordSuccess(): void
    {
        $this->store->delete($this->key());
    }

    private function key(): string
    {
        return 'pluck.breaker.'.hash('sha256', $this->name);
    }
}
