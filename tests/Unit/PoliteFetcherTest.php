<?php

declare(strict_types=1);

namespace Pluck\Tests\Unit;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Pluck\Http\FetchFailed;
use Pluck\Http\PoliteFetcher;
use Pluck\Support\TargetGuard;
use Pluck\Tests\Support\Http;

final class PoliteFetcherTest extends TestCase
{
    private function fetcher(array $queue, ?array &$history = null, int $maxBytes = 1000, int $maxRedirects = 3, ?TargetGuard $guard = null): PoliteFetcher
    {
        return new PoliteFetcher(Http::client($queue, $history), $guard ?? Http::publicGuard(), maxBytes: $maxBytes, maxRedirects: $maxRedirects);
    }

    public function test_returns_status_body_and_lowercased_headers(): void
    {
        $page = $this->fetcher([new Response(200, ['X-Thing' => 'a'], '<p>hi</p>')], $history)->fetch('https://shop.example/p/1');

        $this->assertSame(200, $page->status);
        $this->assertSame('<p>hi</p>', $page->body);
        $this->assertSame('a', $page->headers['x-thing']);
        $this->assertSame('https://shop.example/p/1', $page->finalUrl);
    }

    public function test_sends_browser_user_agent_dutch_language_and_pins_the_vetted_ip(): void
    {
        $this->fetcher([new Response(200, [], 'x')], $history)->fetch('https://shop.example/p/1');

        $options = $history[0]['options'];
        $this->assertFalse($options['allow_redirects']);
        $this->assertArrayNotHasKey('stream', $options, 'stream=>true would route through the stream handler and bypass the pin');
        $this->assertInstanceOf(\Pluck\Http\CappedStream::class, $options['sink']);
        $this->assertSame(5, $options['connect_timeout']);
        $this->assertStringContainsString('Chrome/', $history[0]['request']->getHeaderLine('User-Agent'));
        $this->assertSame('nl-NL,nl;q=0.9', $history[0]['request']->getHeaderLine('Accept-Language'));
        $this->assertSame(['shop.example:443:93.184.216.34'], $options['curl'][CURLOPT_RESOLVE]);
    }

    public function test_identified_bot_hosts_get_the_identifying_user_agent(): void
    {
        $this->fetcher([new Response(200, [], 'x')], $history)->fetch('https://world.openfoodfacts.org/api');

        $this->assertSame('InktomiSuperRaten/1.0', $history[0]['request']->getHeaderLine('User-Agent'));
    }

    public function test_error_statuses_are_returned_not_thrown(): void
    {
        $page = $this->fetcher([new Response(403, [], 'Access Denied')])->fetch('https://shop.example/p/1');

        $this->assertSame(403, $page->status);
    }

    public function test_body_over_the_cap_is_abandoned(): void
    {
        $this->expectException(FetchFailed::class);
        $this->expectExceptionMessage('larger than 1000 bytes');
        $this->fetcher([new Response(200, [], str_repeat('a', 1001))])->fetch('https://shop.example/p/1');
    }

    public function test_declared_content_length_over_the_cap_is_refused_before_reading(): void
    {
        try {
            $this->fetcher([new Response(200, ['Content-Length' => '5000'], 'tiny')])->fetch('https://shop.example/p/1');
            $this->fail('Expected FetchFailed.');
        } catch (FetchFailed $e) {
            $this->assertSame('content_too_large', $e->reason);
        }
    }

    public function test_body_exactly_at_the_cap_is_accepted(): void
    {
        $page = $this->fetcher([new Response(200, [], str_repeat('a', 1000))])->fetch('https://shop.example/p/1');

        $this->assertSame(1000, strlen($page->body));
    }

    public function test_same_host_redirects_are_followed_and_relative_locations_resolved(): void
    {
        $page = $this->fetcher([
            new Response(301, ['Location' => '/nieuw'], ''),
            new Response(302, ['Location' => 'https://www.shop.example/nieuw2'], ''),
            new Response(200, [], 'final'),
        ], $history)->fetch('https://shop.example/oud');

        $this->assertSame('final', $page->body);
        $this->assertSame('https://www.shop.example/nieuw2', $page->finalUrl);
        $this->assertSame(2, $page->redirects);
        $this->assertSame('https://shop.example/nieuw', (string) $history[1]['request']->getUri());
    }

    public function test_cross_host_redirect_is_refused(): void
    {
        try {
            $this->fetcher([new Response(302, ['Location' => 'https://evil.example/'], '')])->fetch('https://shop.example/p/1');
            $this->fail('Expected FetchFailed.');
        } catch (FetchFailed $e) {
            $this->assertSame('cross_host_redirect', $e->reason);
        }
    }

    public function test_https_to_http_downgrade_is_refused(): void
    {
        try {
            $this->fetcher([new Response(302, ['Location' => 'http://shop.example/p/1'], '')])->fetch('https://shop.example/p/1');
            $this->fail('Expected FetchFailed.');
        } catch (FetchFailed $e) {
            $this->assertSame('redirect_downgrade', $e->reason);
        }
    }

    public function test_redirect_chain_is_bounded(): void
    {
        $hop = new Response(302, ['Location' => '/again'], '');

        try {
            $this->fetcher([$hop, $hop, $hop, $hop], maxRedirects: 3)->fetch('https://shop.example/p/1');
            $this->fail('Expected FetchFailed.');
        } catch (FetchFailed $e) {
            $this->assertSame('too_many_redirects', $e->reason);
        }
    }

    public function test_every_hop_is_vetted_again(): void
    {
        $calls = 0;
        $guard = new TargetGuard(function () use (&$calls): array {
            return ++$calls === 1 ? ['93.184.216.34'] : ['10.0.0.1'];
        });

        try {
            $this->fetcher([new Response(302, ['Location' => '/x'], ''), new Response(200, [], 'secret')], guard: $guard)->fetch('https://shop.example/p/1');
            $this->fail('Expected FetchFailed.');
        } catch (FetchFailed $e) {
            $this->assertSame('blocked_target', $e->reason);
        }
    }

    public function test_private_target_makes_no_request_at_all(): void
    {
        try {
            $this->fetcher([], $history)->fetch('http://169.254.169.254/latest/meta-data');
            $this->fail('Expected FetchFailed.');
        } catch (FetchFailed $e) {
            $this->assertSame('blocked_target', $e->reason);
            $this->assertSame([], $history);
        }
    }

    public function test_transport_errors_are_wrapped_with_the_url_scrubbed(): void
    {
        $url = 'https://shop.example/p/1?token=SECRET';

        try {
            $this->fetcher([new ConnectException("cURL error 6: Could not resolve host for {$url}", new Request('GET', $url))])->fetch($url);
            $this->fail('Expected FetchFailed.');
        } catch (FetchFailed $e) {
            $this->assertSame('transport', $e->reason);
            $this->assertStringNotContainsString('SECRET', $e->getMessage());
        }
    }
}
