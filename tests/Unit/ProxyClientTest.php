<?php

declare(strict_types=1);

namespace Pluck\Tests\Unit;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Pluck\Client\InvalidRequest;
use Pluck\Client\Outcome;
use Pluck\Client\PluckException;
use Pluck\Client\ProxyClient;
use Pluck\Client\RateLimited;
use Pluck\Client\RenderRequest;
use Pluck\Tests\Support\Http;

final class ProxyClientTest extends TestCase
{
    private const BASE = 'https://scraper.example.test/S3CRET/v1';

    private function client(array $queue, ?array &$history = null): ProxyClient
    {
        return new ProxyClient(self::BASE, Http::client($queue, $history));
    }

    public function test_posts_the_contract_body_to_the_render_endpoint(): void
    {
        $client = $this->client([Http::json(Http::envelope())], $history);

        $envelope = $client->render(RenderRequest::xpath('https://shop.example/p/1', ['price' => '//b'], ttlSeconds: 0));

        $this->assertTrue($envelope->isFetched());
        $sent = $history[0]['request'];
        $this->assertSame('POST', $sent->getMethod());
        $this->assertSame(self::BASE.'/render', (string) $sent->getUri());
        $this->assertSame('application/json', $sent->getHeaderLine('Content-Type'));
        $body = json_decode((string) $sent->getBody(), true);
        $this->assertSame('xpath', $body['strip']);
        $this->assertSame(['price' => '//b'], $body['keep_xpaths']);
        $this->assertSame(60 + ProxyClient::HTTP_HEADROOM_SECONDS, $history[0]['options']['timeout']);
    }

    public function test_extra_headers_are_sent(): void
    {
        $client = new ProxyClient(self::BASE, Http::client([Http::json(Http::envelope())], $history), headers: ['X-Gateway' => 'g']);
        $client->render(new RenderRequest('https://shop.example/p/1'));

        $this->assertSame('g', $history[0]['request']->getHeaderLine('X-Gateway'));
    }

    public function test_blocked_is_returned_as_an_outcome_not_an_exception_and_not_as_data(): void
    {
        $client = $this->client([Http::json(Http::envelope(['outcome' => 'blocked', 'html' => null, 'retry_after_seconds' => 600, 'shop_status' => 403]))]);

        $envelope = $client->render(new RenderRequest('https://shop.example/p/1'));

        $this->assertSame(Outcome::Blocked, $envelope->outcome);
        $this->assertSame(600, $envelope->retryAfterSeconds);
        $this->expectException(PluckException::class);
        $envelope->html();
    }

    public function test_502_is_upstream_error(): void
    {
        $client = $this->client([Http::json(Http::envelope(['outcome' => 'upstream_error', 'html' => null]), 502)]);

        $this->assertSame(Outcome::UpstreamError, $client->render(new RenderRequest('https://shop.example/p/1'))->outcome);
    }

    public function test_502_carrying_another_outcome_is_a_contract_violation(): void
    {
        $client = $this->client([Http::json(Http::envelope(), 502)]);

        $this->expectException(PluckException::class);
        $client->render(new RenderRequest('https://shop.example/p/1'));
    }

    public function test_400_becomes_invalid_request_with_the_proxy_error_code(): void
    {
        $client = $this->client([Http::json(['error' => 'invalid_xpath'], 400)]);

        try {
            $client->render(new RenderRequest('https://shop.example/p/1'));
            $this->fail('Expected InvalidRequest.');
        } catch (InvalidRequest $e) {
            $this->assertSame('invalid_xpath', $e->errorCode);
        }
    }

    public function test_error_statuses_with_non_json_bodies_keep_their_meaning(): void
    {
        try {
            $this->client([new Response(400, [], '<html>Bad Request</html>')])->render(new RenderRequest('https://shop.example/p/1'));
            $this->fail('Expected InvalidRequest.');
        } catch (InvalidRequest $e) {
            $this->assertSame('unspecified', $e->errorCode);
        }

        try {
            $this->client([new Response(429, ['Retry-After' => '7'], 'slow down')])->render(new RenderRequest('https://shop.example/p/1'));
            $this->fail('Expected RateLimited.');
        } catch (RateLimited $e) {
            $this->assertSame(7, $e->retryAfterSeconds);
        }
    }

    public function test_429_carries_retry_after_from_header_or_body(): void
    {
        $fromHeader = $this->client([new Response(429, ['Retry-After' => '30'], '{}')]);
        $fromBody = $this->client([Http::json(['retry_after_seconds' => 45], 429, ['Retry-After' => '30'])]);

        try {
            $fromHeader->render(new RenderRequest('https://shop.example/p/1'));
            $this->fail('Expected RateLimited.');
        } catch (RateLimited $e) {
            $this->assertSame(30, $e->retryAfterSeconds);
        }

        try {
            $fromBody->render(new RenderRequest('https://shop.example/p/1'));
            $this->fail('Expected RateLimited.');
        } catch (RateLimited $e) {
            $this->assertSame(45, $e->retryAfterSeconds);
        }
    }

    public function test_404_points_at_the_secret_path_and_never_echoes_it(): void
    {
        $client = $this->client([new Response(404, [], 'not found')]);

        try {
            $client->render(new RenderRequest('https://shop.example/p/1'));
            $this->fail('Expected PluckException.');
        } catch (PluckException $e) {
            $this->assertStringContainsString('secret path', $e->getMessage());
            $this->assertStringNotContainsString('S3CRET', $e->getMessage());
        }
    }

    public function test_504_and_unexpected_statuses_throw(): void
    {
        foreach ([504, 413, 500, 302] as $status) {
            try {
                $this->client([new Response($status, [], '{}')])->render(new RenderRequest('https://shop.example/p/1'));
                $this->fail("HTTP {$status} must throw.");
            } catch (PluckException $e) {
                $this->assertSame($status, $e->getCode());
            }
        }
    }

    public function test_transport_errors_are_wrapped_and_the_secret_is_scrubbed(): void
    {
        $client = $this->client([new ConnectException('cURL error 7: Could not connect to '.self::BASE.'/render', new Request('POST', self::BASE.'/render'))]);

        try {
            $client->render(new RenderRequest('https://shop.example/p/1'));
            $this->fail('Expected PluckException.');
        } catch (PluckException $e) {
            $this->assertStringNotContainsString('S3CRET', $e->getMessage());
            $this->assertInstanceOf(ConnectException::class, $e->getPrevious());
        }
    }

    public function test_malformed_bodies_fail_closed(): void
    {
        foreach (['not json', '[]', '"text"', '[1,2]', '{}', json_encode(['contract_version' => 1, 'outcome' => 'fetched'])] as $body) {
            try {
                $this->client([new Response(200, [], $body)])->render(new RenderRequest('https://shop.example/p/1'));
                $this->fail("Body {$body} must not be accepted.");
            } catch (PluckException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_base_url_must_be_absolute_http(): void
    {
        $this->expectException(InvalidRequest::class);
        new ProxyClient('scraper/v1', Http::client([]));
    }

    public function test_the_interpreter_works_on_any_psr7_response_so_a_consumer_can_keep_its_own_transport(): void
    {
        $interpreter = new \Pluck\Client\ResponseInterpreter();

        $this->assertSame(Outcome::Blocked, $interpreter->interpret(Http::json(Http::envelope(['outcome' => 'blocked', 'html' => null])))->outcome);
        $this->assertSame(Outcome::UpstreamError, $interpreter->interpret(Http::json(Http::envelope(['outcome' => 'upstream_error', 'html' => null]), 502))->outcome);

        foreach ([[404, PluckException::class], [429, RateLimited::class], [400, InvalidRequest::class], [504, PluckException::class]] as [$status, $class]) {
            try {
                $interpreter->interpret(new Response($status, [], 'plain text'));
                $this->fail("HTTP {$status} must throw.");
            } catch (PluckException $e) {
                $this->assertInstanceOf($class, $e);
            }
        }
    }
}
