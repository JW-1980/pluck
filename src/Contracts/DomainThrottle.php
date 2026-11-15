<?php

declare(strict_types=1);

namespace Pluck\Contracts;

/**
 * Per-domain politeness (FUNCTIONAL_DESIGN §4 #1, #4). One regime for every
 * consumer: no consumer invents a fourth rate-limiting scheme (decision 4).
 */
interface DomainThrottle
{
    /**
     * Books the next request slot for $host and returns how many seconds the
     * caller must wait before touching the shop (0.0 = go now).
     */
    public function reserve(string $host): float;

    /** Seconds of block cooldown left for $host, or null when it may be contacted. */
    public function cooldownRemaining(string $host): ?int;

    /** Records a block: the host is left alone for the cooldown period. */
    public function markBlocked(string $host): void;
}
