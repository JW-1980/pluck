<?php

declare(strict_types=1);

namespace Pluck\Tests\Integration;

use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Pluck\Client\InvalidRequest;
use Pluck\Client\Outcome;
use Pluck\Client\PluckException;
use Pluck\Client\ProxyClient;
use Pluck\Client\RenderRequest;
use Pluck\Client\StripMode;

/**
 * The cross-language contract test: the real PHP ProxyClient against the real Python proxy on a real
 * socket, in front of a real local shop. Skipped (and reported skipped) without a Python venv that has
 * the proxy's dependencies: set PLUCK_PROXY_PYTHON=/path/to/python.
 */
final class ProxyInteropTest extends TestCase
{
    private static mixed $process = null;
    private static ?ProxyClient $client = null;
    private static string $shop = '';

    public static function setUpBeforeClass(): void
    {
        $python = getenv('PLUCK_PROXY_PYTHON');
        if (! is_string($python) || $python === '' || ! is_file($python) || ! function_exists('proc_open')) {
            return;
        }

        self::$process = proc_open(
            [$python, __DIR__.'/../../proxy/tests/serve_for_php.py'],
            [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            __DIR__.'/../../proxy',
        );
        if (! is_resource(self::$process)) {
            return;
        }

        stream_set_blocking($pipes[1], false);
        $line = '';
        for ($i = 0; $i < 200 && $line === ''; $i++) {
            $line = trim((string) fgets($pipes[1]));
            usleep(50_000);
        }
        $ports = json_decode($line, true);
        if (! is_array($ports)) {
            self::tearDownAfterClass();

            return;
        }

        self::$shop = 'http://127.0.0.1:'.$ports['shop'];
        $base = 'http://127.0.0.1:'.$ports['proxy'];
        for ($i = 0; $i < 100; $i++) {
            if (@file_get_contents($base.'/healthz') !== false) {
                // the test proxy has no Caddy front door: talk to it directly
                self::$client = new ProxyClient($base.'/v1', new Client());

                return;
            }
            usleep(100_000);
        }
        self::tearDownAfterClass();
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$process)) {
            proc_terminate(self::$process);
            proc_close(self::$process);
        }
        self::$process = null;
        self::$client = null;
    }

    protected function setUp(): void
    {
        if (self::$client === null) {
            $this->markTestSkipped('Proxy not started: set PLUCK_PROXY_PYTHON to a Python with the proxy dependencies.');
        }
    }

    public function test_xpath_round_trip_through_the_typed_envelope(): void
    {
        $envelope = self::$client->render(RenderRequest::xpath(self::$shop.'/plain', [
            'price' => '//span[@itemprop="price"]',
            'missing' => '//div[@class="nope"]',
        ]));

        $this->assertSame(Outcome::Fetched, $envelope->outcome);
        $this->assertSame(['€ 12,95'], $envelope->fragment('price'));
        $this->assertSame(['missing'], $envelope->staleExpressions(), 'a stale expression is visible, not "no data"');
        $this->assertSame('Magnesium 400 mg', $envelope->title);
        $this->assertSame('Product', $envelope->jsonLd[0]['@type']);
        $this->assertSame('plain', $envelope->meta['renderer']);
    }

    public function test_conservative_html_round_trip(): void
    {
        $envelope = self::$client->render(new RenderRequest(self::$shop.'/plain'));

        $this->assertStringContainsString('itemprop="price"', $envelope->html());
        $this->assertStringNotContainsString('<nav', $envelope->html());
    }

    public function test_a_block_arrives_as_an_outcome_and_content_is_refused(): void
    {
        // `localhost` is a different domain from 127.0.0.1: the 10-minute cooldown it starts stays contained
        $envelope = self::$client->render(new RenderRequest(str_replace('127.0.0.1', 'localhost', self::$shop).'/denied'));

        $this->assertSame(Outcome::Blocked, $envelope->outcome);
        $this->assertSame(403, $envelope->shopStatus);
        $this->assertSame(600, $envelope->retryAfterSeconds);

        $this->expectException(PluckException::class);
        $envelope->html();
    }

    public function test_shop_error_and_upstream_error_are_distinct_outcomes(): void
    {
        $gone = self::$client->render(new RenderRequest(self::$shop.'/gone'));
        $this->assertSame(Outcome::ShopError, $gone->outcome);
        $this->assertSame(404, $gone->shopStatus);
    }

    public function test_the_proxy_400_becomes_invalid_request_with_its_code(): void
    {
        try {
            // bypass client-side validation of expression syntax: the client only checks the contract's limits
            self::$client->render(new RenderRequest(self::$shop.'/plain', StripMode::XPath, ['a' => "document('file:///etc/passwd')"]));
            $this->fail('Expected InvalidRequest.');
        } catch (InvalidRequest $e) {
            $this->assertSame('invalid_xpath', $e->errorCode);
        }

    }

    public function test_cache_status_is_visible_to_the_client(): void
    {
        $first = self::$client->render(new RenderRequest(self::$shop.'/many', ttlSeconds: 60));
        $second = self::$client->render(new RenderRequest(self::$shop.'/many', ttlSeconds: 60));

        $this->assertSame('miss', $first->cacheStatus());
        $this->assertSame('hit', $second->cacheStatus());
    }

    public function test_an_unreachable_proxy_is_a_pluck_exception_with_the_secret_scrubbed(): void
    {
        $unreachable = new ProxyClient('http://127.0.0.1:1/SECRET/v1', new Client(['connect_timeout' => 2]));

        try {
            $unreachable->render(new RenderRequest(self::$shop.'/plain'));
            $this->fail('Expected PluckException.');
        } catch (PluckException $e) {
            $this->assertStringNotContainsString('SECRET', $e->getMessage());
        }
    }
}
