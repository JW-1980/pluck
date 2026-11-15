<?php

declare(strict_types=1);

namespace Pluck\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pluck\Client\Outcome;
use Pluck\Contracts\SourceDriver;
use Pluck\Http\DirectSourceChain;
use Pluck\Http\SourceResult;
use Pluck\Support\ArrayCache;
use Pluck\Tests\Support\FakeClock;
use Pluck\Throttle\PoliteDomainThrottle;

final class DirectSourceChainTest extends TestCase
{
    private FakeClock $clock;
    private PoliteDomainThrottle $throttle;

    /** @var list<float> */
    private array $waits = [];

    protected function setUp(): void
    {
        $this->clock = new FakeClock();
        $this->throttle = new PoliteDomainThrottle(new ArrayCache($this->clock), $this->clock);
        $this->waits = [];
    }

    private function chain(SourceDriver ...$rungs): DirectSourceChain
    {
        return new DirectSourceChain(array_values($rungs), $this->throttle, function (float $s): void {
            $this->waits[] = $s;
            $this->clock->advance($s);
        });
    }

    private function rung(string $name, Outcome $outcome, int $status = 200): SourceDriver
    {
        return new class($name, $outcome, $status) implements SourceDriver {
            public int $calls = 0;

            public function __construct(private string $n, private Outcome $o, private int $s) {}

            public function name(): string
            {
                return $this->n;
            }

            public function fetch(string $url): SourceResult
            {
                $this->calls++;

                return new SourceResult($this->o, $this->n, $this->s, body: $this->o === Outcome::Fetched ? '<p>ok</p>' : null, blockSignal: $this->o === Outcome::Blocked ? 'HTTP 403' : null);
            }
        };
    }

    public function test_first_rung_that_fetches_wins_and_later_rungs_are_not_touched(): void
    {
        $http = $this->rung('http', Outcome::Fetched);
        $flare = $this->rung('flaresolverr', Outcome::Fetched);

        $result = $this->chain($http, $flare)->fetch('https://shop.example/p/1');

        $this->assertSame('http', $result->renderer);
        $this->assertSame(0, $flare->calls);
    }

    public function test_a_block_escalates_to_the_next_rung_after_the_politeness_wait(): void
    {
        $http = $this->rung('http', Outcome::Blocked, 403);
        $flare = $this->rung('flaresolverr', Outcome::Fetched);

        $result = $this->chain($http, $flare)->fetch('https://shop.example/p/1');

        $this->assertSame('flaresolverr', $result->renderer);
        $this->assertSame([0.0, 3.0], $this->waits);
        $this->assertNull($this->throttle->cooldownRemaining('shop.example'), 'a block that a later rung solved does not start a cooldown');
    }

    public function test_shop_error_stops_the_chain_a_bigger_browser_cannot_cure_a_404(): void
    {
        $http = $this->rung('http', Outcome::ShopError, 404);
        $flare = $this->rung('flaresolverr', Outcome::Fetched);

        $result = $this->chain($http, $flare)->fetch('https://shop.example/gone');

        $this->assertSame(Outcome::ShopError, $result->outcome);
        $this->assertSame(0, $flare->calls);
    }

    public function test_upstream_error_falls_through_to_the_next_rung(): void
    {
        $result = $this->chain($this->rung('flaresolverr', Outcome::UpstreamError), $this->rung('chromium', Outcome::Fetched))->fetch('https://shop.example/p/1');

        $this->assertSame('chromium', $result->renderer);
    }

    public function test_when_everything_fails_blocked_outranks_upstream_error_and_starts_the_cooldown(): void
    {
        $result = $this->chain($this->rung('http', Outcome::Blocked, 403), $this->rung('flaresolverr', Outcome::UpstreamError))->fetch('https://shop.example/p/1');

        $this->assertSame(Outcome::Blocked, $result->outcome);
        $this->assertSame(600, $this->throttle->cooldownRemaining('shop.example'));
    }

    public function test_only_upstream_errors_gives_upstream_error_and_no_cooldown(): void
    {
        $result = $this->chain($this->rung('flaresolverr', Outcome::UpstreamError))->fetch('https://shop.example/p/1');

        $this->assertSame(Outcome::UpstreamError, $result->outcome);
        $this->assertNull($this->throttle->cooldownRemaining('shop.example'));
    }

    public function test_a_domain_in_cooldown_is_not_contacted_and_says_when_to_retry(): void
    {
        $http = $this->rung('http', Outcome::Fetched);
        $this->throttle->markBlocked('shop.example');
        $this->clock->advance(100);

        $result = $this->chain($http)->fetch('https://shop.example/p/1');

        $this->assertSame(Outcome::Blocked, $result->outcome);
        $this->assertSame(500, $result->retryAfterSeconds);
        $this->assertSame('cooldown', $result->blockSignal);
        $this->assertSame(0, $http->calls);
        $this->assertSame([], $this->waits);

        $other = $this->chain($http)->fetch('https://other.example/p/1');
        $this->assertSame(Outcome::Fetched, $other->outcome, 'other domains are unaffected');
    }

    public function test_cooldown_ends_after_ten_minutes(): void
    {
        $this->throttle->markBlocked('shop.example');
        $this->clock->advance(600);

        $this->assertSame(Outcome::Fetched, $this->chain($this->rung('http', Outcome::Fetched))->fetch('https://shop.example/p/1')->outcome);
    }

    public function test_chain_needs_at_least_one_rung(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DirectSourceChain([], $this->throttle);
    }
}
