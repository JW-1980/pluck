<?php

declare(strict_types=1);

namespace Pluck\Throttle;

use Pluck\Contracts\Clock;
use Pluck\Contracts\DomainThrottle;
use Pluck\Support\SystemClock;
use Psr\SimpleCache\CacheInterface;

/**
 * Inktomi's recorded politeness budget (src/superraten/config.py, user-mandated):
 * 3 s per domain, 1 s global, 2.1 s for Open Food Facts, and a 10-minute
 * per-domain cooldown after a block (fetcher.py `playwright_disabled_domains`).
 *
 * State lives in any PSR-16 store. Plain PSR-16 has no atomic read-modify-write,
 * so with several workers sharing one store two workers can book the same slot;
 * bind a store with locking (e.g. Laravel's atomic cache) in the consumer's
 * integration layer when that matters. Intervals are constructor arguments and
 * may only be raised above the floors, except for hosts listed in
 * $hostIntervals (the recorded Open Food Facts exception).
 */
final class PoliteDomainThrottle implements DomainThrottle
{
    public const DOMAIN_INTERVAL_SECONDS = 3.0;
    public const GLOBAL_INTERVAL_SECONDS = 1.0;
    public const COOLDOWN_SECONDS = 600;

    /** Recorded donor exception: Open Food Facts is spaced 2.1 s, not 3 s. */
    public const DEFAULT_HOST_INTERVALS = ['openfoodfacts.org' => 2.1];

    private const STATE_TTL_SECONDS = 3600;

    /** @param array<string,float> $hostIntervals host suffix => seconds */
    public function __construct(
        private readonly CacheInterface $store,
        private readonly Clock $clock = new SystemClock(),
        private readonly float $domainInterval = self::DOMAIN_INTERVAL_SECONDS,
        private readonly float $globalInterval = self::GLOBAL_INTERVAL_SECONDS,
        private readonly int $cooldownSeconds = self::COOLDOWN_SECONDS,
        private readonly array $hostIntervals = self::DEFAULT_HOST_INTERVALS,
    ) {}

    public function reserve(string $host): float
    {
        $host = $this->normalise($host);
        $now = $this->clock->now();

        $domainNext = $this->float($this->store->get($this->key('domain', $host)));
        $globalNext = $this->float($this->store->get($this->key('global', '')));
        $slot = max($now, $domainNext, $globalNext);

        $this->store->set($this->key('domain', $host), $slot + $this->intervalFor($host), self::STATE_TTL_SECONDS);
        $this->store->set($this->key('global', ''), $slot + $this->globalInterval, self::STATE_TTL_SECONDS);

        return $slot - $now;
    }

    public function cooldownRemaining(string $host): ?int
    {
        $until = $this->store->get($this->key('blocked', $this->normalise($host)));
        if (! is_float($until) && ! is_int($until)) {
            return null;
        }

        $left = (int) ceil($until - $this->clock->now());

        return $left > 0 ? $left : null;
    }

    public function markBlocked(string $host): void
    {
        $this->store->set(
            $this->key('blocked', $this->normalise($host)),
            $this->clock->now() + $this->cooldownSeconds,
            $this->cooldownSeconds,
        );
    }

    private function intervalFor(string $host): float
    {
        foreach ($this->hostIntervals as $suffix => $seconds) {
            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                return $seconds;
            }
        }

        return $this->domainInterval;
    }

    private function normalise(string $host): string
    {
        return strtolower(rtrim($host, '.'));
    }

    private function key(string $kind, string $host): string
    {
        return 'pluck.throttle.'.$kind.($host === '' ? '' : '.'.hash('sha256', $host));
    }

    private function float(mixed $value): float
    {
        return is_int($value) || is_float($value) ? (float) $value : 0.0;
    }
}
