<?php

declare(strict_types=1);

namespace Pluck\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pluck\Support\ArrayCache;
use Pluck\Tests\Support\FakeClock;
use Pluck\Throttle\CircuitBreaker;
use Pluck\Throttle\PoliteDomainThrottle;

final class ThrottleTest extends TestCase
{
    private function throttle(?FakeClock &$clock = null): PoliteDomainThrottle
    {
        $clock = new FakeClock();

        return new PoliteDomainThrottle(new ArrayCache($clock), $clock);
    }

    public function test_first_request_goes_immediately(): void
    {
        $this->assertSame(0.0, $this->throttle()->reserve('shop.example'));
    }

    public function test_same_domain_is_spaced_three_seconds(): void
    {
        $t = $this->throttle($clock);
        $t->reserve('shop.example');

        $this->assertSame(3.0, $t->reserve('shop.example'));
        $clock->advance(3.0);
        $this->assertSame(3.0, $t->reserve('shop.example'), 'the second booking pushed the slot out again');
    }

    public function test_waiting_it_out_clears_the_debt(): void
    {
        $t = $this->throttle($clock);
        $t->reserve('shop.example');
        $clock->advance(3.0);

        $this->assertSame(0.0, $t->reserve('shop.example'));
    }

    public function test_different_domains_are_spaced_one_second_globally(): void
    {
        $t = $this->throttle();
        $t->reserve('a.example');

        $this->assertSame(1.0, $t->reserve('b.example'));
    }

    public function test_open_food_facts_uses_the_recorded_2_1_second_interval(): void
    {
        $t = $this->throttle($clock);
        $t->reserve('world.openfoodfacts.org');

        $this->assertEqualsWithDelta(2.1, $t->reserve('world.openfoodfacts.org'), 1e-9);
    }

    public function test_host_matching_is_case_insensitive(): void
    {
        $t = $this->throttle();
        $t->reserve('Shop.Example');

        $this->assertSame(3.0, $t->reserve('shop.example.'));
    }

    public function test_block_cooldown_lasts_ten_minutes_for_that_domain_only(): void
    {
        $t = $this->throttle($clock);
        $this->assertNull($t->cooldownRemaining('shop.example'));

        $t->markBlocked('shop.example');

        $this->assertSame(600, $t->cooldownRemaining('shop.example'));
        $this->assertNull($t->cooldownRemaining('other.example'));
        $clock->advance(599.5);
        $this->assertSame(1, $t->cooldownRemaining('shop.example'));
        $clock->advance(1.0);
        $this->assertNull($t->cooldownRemaining('shop.example'));
    }

    public function test_circuit_breaker_opens_for_120_seconds_and_closes_on_success(): void
    {
        $clock = new FakeClock();
        $breaker = new CircuitBreaker(new ArrayCache($clock), 'flaresolverr', $clock);

        $this->assertFalse($breaker->isOpen());
        $breaker->recordFailure();
        $this->assertTrue($breaker->isOpen());
        $this->assertSame(120, $breaker->remaining());

        $clock->advance(120.0);
        $this->assertFalse($breaker->isOpen());

        $breaker->recordFailure();
        $breaker->recordSuccess();
        $this->assertFalse($breaker->isOpen());
    }

    public function test_breakers_with_different_names_are_independent(): void
    {
        $store = new ArrayCache();
        (new CircuitBreaker($store, 'a'))->recordFailure();

        $this->assertFalse((new CircuitBreaker($store, 'b'))->isOpen());
    }
}
