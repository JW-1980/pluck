<?php

declare(strict_types=1);

namespace Pluck\Support;

use DateInterval;
use Pluck\Contracts\Clock;
use Psr\SimpleCache\CacheInterface;

/**
 * Process-local PSR-16 store with TTL support, for CLI runs and tests. Web
 * consumers bind their framework cache instead (shared across workers).
 */
final class ArrayCache implements CacheInterface
{
    /** @var array<string,array{0:mixed,1:?float}> */
    private array $items = [];

    public function __construct(private readonly Clock $clock = new SystemClock()) {}

    public function get(string $key, mixed $default = null): mixed
    {
        if (! $this->has($key)) {
            return $default;
        }

        return $this->items[$key][0];
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        $seconds = $ttl instanceof DateInterval
            ? (float) (new \DateTimeImmutable('@0'))->add($ttl)->getTimestamp()
            : ($ttl === null ? null : (float) $ttl);

        if ($seconds !== null && $seconds <= 0) {
            unset($this->items[$key]);

            return true;
        }

        $this->items[$key] = [$value, $seconds === null ? null : $this->clock->now() + $seconds];

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->items[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->items = [];

        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $this->get($key, $default);
        }

        return $out;
    }

    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set((string) $key, $value, $ttl);
        }

        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            unset($this->items[$key]);
        }

        return true;
    }

    public function has(string $key): bool
    {
        if (! isset($this->items[$key])) {
            return false;
        }

        $expires = $this->items[$key][1];
        if ($expires !== null && $expires <= $this->clock->now()) {
            unset($this->items[$key]);

            return false;
        }

        return true;
    }
}
