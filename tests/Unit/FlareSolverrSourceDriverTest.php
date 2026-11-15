<?php

declare(strict_types=1);

namespace Pluck\Tests\Unit;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Pluck\Client\InvalidRequest;
use Pluck\Client\Outcome;
use Pluck\Http\FlareSolverrSourceDriver;
use Pluck\Support\ArrayCache;
use Pluck\Tests\Support\FakeClock;
use Pluck\Tests\Support\Http;
use Pluck\Throttle\CircuitBreaker;

final class FlareSolverrSourceDriverTest extends TestCase
{
    private const ENDPOINT = 'http://127.0.0.1:8191';

    /** @param array<string,mixed> $solution */
    private static function ok(array $solution): Response
    {
        return Http::json(['status' => 'ok', 'solution' => $solution + ['status' => 200, 'url' => 'https://shop.example/p/1', 'response' => '<html>page</html>']]);
    }

    private function driver(array $queue, ?array &$history = null, ?CircuitBreaker $breaker = null, int $maxBytes = 1000): FlareSolverrSourceDriver
    {
        return new FlareSolverrSourceDriver(self::ENDPOINT, Http::client($queue, $history), Http::publicGuard(), breaker: $breaker, maxBytes: $maxBytes);
    }

    public function test_posts_request_get_with_whole_millisecond_timeout_and_headroom(): void
    {
        $result = $this->driver([self::ok([])], $history)->fetch('https://shop.example/p/1');

        $this->assertSame('POST', $history[0]['request']->getMethod());
        $this->assertSame(self::ENDPOINT.'/v1', (string) $history[0]['request']->getUri());
        $this->assertSame(['cmd' => 'request.get', 'url' => 'https://shop.example/p/1', 'maxTimeout' => 60000], json_decode((string) $history[0]['request']->getBody(), true));
        $this->assertSame(70, $history[0]['options']['timeout']);
        $this->assertSame(Outcome::Fetched, $result->outcome);
        $this->assertSame('<html>page</html>', $result->body);
        $this->assertSame('flaresolverr', $result->renderer);
    }

    public function test_a_200_from_flaresolverr_can_hold_a_403_from_the_shop(): void
    {
        $result = $this->driver([self::ok(['status' => 403, 'response' => '<HTML>Access Denied</HTML>'])])->fetch('https://shop.example/p/1');

        $this->assertSame(Outcome::Blocked, $result->outcome);
        $this->assertSame(403, $result->shopStatus);
        $this->assertNull($result->body);
    }

    public function test_an_interstitial_inside_a_200_solution_is_blocked(): void
    {
        $result = $this->driver([self::ok(['response' => '<title>Just a moment...</title>'])])->fetch('https://shop.example/p/1');

        $this->assertSame(Outcome::Blocked, $result->outcome);
        $this->assertSame('just a moment', $result->blockSignal);
    }

    public function test_shop_404_is_a_shop_error_not_a_block(): void
    {
        $result = $this->driver([self::ok(['status' => 404, 'response' => '<h1>Access Denied</h1>'])])->fetch('https://shop.example/p/1');

        $this->assertSame(Outcome::ShopError, $result->outcome);
        $this->assertSame(404, $result->shopStatus);
    }

    public function test_unsolved_challenge_message_is_a_block_not_an_outage(): void
    {
        $breaker = new CircuitBreaker(new ArrayCache(), 'fs');
        $result = $this->driver([Http::json(['status' => 'error', 'message' => 'Error solving the challenge. Timeout after 60.0 seconds.'])], breaker: $breaker)->fetch('https://shop.example/p/1');

        $this->assertSame(Outcome::Blocked, $result->outcome);
        $this->assertFalse($breaker->isOpen(), 'a lost challenge is the shop\'s doing, FlareSolverr itself is healthy');
    }

    public function test_other_flaresolverr_errors_are_upstream_errors_and_open_the_breaker(): void
    {
        $breaker = new CircuitBreaker(new ArrayCache(), 'fs');
        $result = $this->driver([Http::json(['status' => 'error', 'message' => 'Chrome crashed'])], breaker: $breaker)->fetch('https://shop.example/p/1');

        $this->assertSame(Outcome::UpstreamError, $result->outcome);
        $this->assertStringContainsString('Chrome crashed', (string) $result->error);
        $this->assertTrue($breaker->isOpen());
    }

    public function test_unreachable_flaresolverr_is_an_upstream_error_and_the_next_call_skips_it_visibly(): void
    {
        $clock = new FakeClock();
        $breaker = new CircuitBreaker(new ArrayCache($clock), 'fs', $clock);
        $driver = $this->driver([new ConnectException('cURL error 7: connect to http://127.0.0.1:8191/v1 refused', new Request('POST', self::ENDPOINT.'/v1'))], $history, $breaker);

        $first = $driver->fetch('https://shop.example/p/1');
        $second = $driver->fetch('https://shop.example/p/1');

        $this->assertSame(Outcome::UpstreamError, $first->outcome);
        $this->assertStringNotContainsString('127.0.0.1', (string) $first->error);
        $this->assertSame(Outcome::UpstreamError, $second->outcome, 'an open breaker reports upstream_error, it never pretends');
        $this->assertSame(120, $second->retryAfterSeconds);
        $this->assertCount(1, $history, 'the second call never reached FlareSolverr');
    }

    public function test_flaresolverr_http_error_status_and_garbage_are_upstream_errors(): void
    {
        foreach ([new Response(500, [], 'boom'), new Response(200, [], 'not json')] as $response) {
            $this->assertSame(Outcome::UpstreamError, $this->driver([$response])->fetch('https://shop.example/p/1')->outcome);
        }
    }

    public function test_a_solution_without_status_or_page_is_not_a_success(): void
    {
        foreach ([['status' => 'ok', 'solution' => ['status' => 200]], ['status' => 'ok', 'solution' => ['response' => '<p>x</p>']], ['status' => 'ok']] as $reply) {
            $this->assertSame(Outcome::UpstreamError, $this->driver([Http::json($reply)])->fetch('https://shop.example/p/1')->outcome);
        }
    }

    public function test_oversized_pages_are_a_shop_error(): void
    {
        $result = $this->driver([self::ok(['response' => str_repeat('a', 1001)])], maxBytes: 1000)->fetch('https://shop.example/p/1');

        $this->assertSame(Outcome::ShopError, $result->outcome);
        $this->assertStringContainsString('content_too_large', (string) $result->error);
    }

    public function test_private_targets_are_never_forwarded_to_the_internal_flaresolverr(): void
    {
        $driver = $this->driver([self::ok([])], $history);

        try {
            $driver->fetch('http://169.254.169.254/latest/meta-data');
            $this->fail('Expected InvalidRequest.');
        } catch (InvalidRequest $e) {
            $this->assertSame('private_target', $e->errorCode);
            $this->assertSame([], $history);
        }
    }

    public function test_after_the_breaker_expires_flaresolverr_is_tried_again(): void
    {
        $clock = new FakeClock();
        $breaker = new CircuitBreaker(new ArrayCache($clock), 'fs', $clock);
        $driver = $this->driver([Http::json(['status' => 'error', 'message' => 'Chrome crashed']), self::ok([])], $history, $breaker);

        $this->assertSame(Outcome::UpstreamError, $driver->fetch('https://shop.example/p/1')->outcome);
        $clock->advance(120.0);
        $recovered = $driver->fetch('https://shop.example/p/1');

        $this->assertSame(Outcome::Fetched, $recovered->outcome);
        $this->assertCount(2, $history);
        $this->assertFalse($breaker->isOpen());
    }
}
