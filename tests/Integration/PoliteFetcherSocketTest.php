<?php

declare(strict_types=1);

namespace Pluck\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Pluck\Contracts\TargetValidator;
use Pluck\Http\FetchFailed;
use Pluck\Http\PoliteFetcher;

/**
 * Real sockets against `php -S`. The mock-handler unit tests cannot prove the
 * cap works mid-transfer or that the connection pin takes effect; these can.
 * They are skipped (and reported as skipped) when no server can be started.
 */
final class PoliteFetcherSocketTest extends TestCase
{
    private static mixed $process = null;
    private static int $port = 0;

    public static function setUpBeforeClass(): void
    {
        if (! function_exists('proc_open')) {
            return;
        }

        $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $err);
        if ($probe === false) {
            return;
        }
        self::$port = (int) parse_url('tcp://'.stream_socket_get_name($probe, false), PHP_URL_PORT);
        fclose($probe);

        self::$process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:'.self::$port, __DIR__.'/router.php'],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );

        for ($i = 0; $i < 100; $i++) {
            $c = @fsockopen('127.0.0.1', self::$port, $e, $s, 0.1);
            if ($c !== false) {
                fclose($c);

                return;
            }
            usleep(50_000);
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
    }

    protected function setUp(): void
    {
        if (! is_resource(self::$process)) {
            $this->markTestSkipped('Could not start the local test server (proc_open unavailable or no free port).');
        }
    }

    /** Accepts any host and pins it to the local server — what a resolver would do for a public shop. */
    private function validator(): TargetValidator
    {
        return new class implements TargetValidator {
            public function vet(string $url): array
            {
                $p = parse_url($url);

                return ['scheme' => $p['scheme'], 'host' => $p['host'], 'port' => $p['port'] ?? 80, 'ip' => '127.0.0.1'];
            }
        };
    }

    private function url(string $path, string $host = 'shop.test'): string
    {
        return 'http://'.$host.':'.self::$port.$path;
    }

    private function fetcher(int $maxBytes = 1_048_576, ?bool $pin = null): PoliteFetcher
    {
        return new PoliteFetcher(guard: $this->validator(), maxBytes: $maxBytes, timeoutSeconds: 20, pinConnection: $pin);
    }

    public function test_the_connection_is_pinned_to_the_vetted_ip(): void
    {
        if (! extension_loaded('curl')) {
            $this->markTestSkipped('Pinning is cURL-only.');
        }

        // shop.test does not exist in DNS: this only works if CURLOPT_RESOLVE took effect.
        $page = $this->fetcher(pin: true)->fetch($this->url('/host'));

        $this->assertSame(200, $page->status);
        $this->assertSame('shop.test:'.self::$port, $page->body);
    }

    public function test_negative_control_without_the_pin_the_same_request_cannot_resolve(): void
    {
        $this->expectException(FetchFailed::class);
        try {
            $this->fetcher(pin: false)->fetch($this->url('/small'));
        } catch (FetchFailed $e) {
            $this->assertSame('transport', $e->reason);
            throw $e;
        }
    }

    public function test_small_page_and_error_status_come_back_intact(): void
    {
        $fetcher = $this->fetcher();

        $this->assertSame('<p>hello</p>', $fetcher->fetch($this->url('/small'))->body);
        $this->assertSame(403, $fetcher->fetch($this->url('/403'))->status);
        $this->assertSame(404, $fetcher->fetch($this->url('/nope'))->status);
        $this->assertSame('<p>hello gzip</p>', $fetcher->fetch($this->url('/gzip-small'))->body, 'transparent decoding still works');
    }

    public function test_declared_oversize_is_refused(): void
    {
        $this->expectException(FetchFailed::class);
        $this->expectExceptionMessage('larger than');
        $this->fetcher(1_048_576)->fetch($this->url('/big'));
    }

    public function test_chunked_oversize_without_content_length_is_cut_mid_transfer(): void
    {
        try {
            $this->fetcher(1_048_576)->fetch($this->url('/chunked'));
            $this->fail('A 5 MB chunked body must not pass a 1 MB cap.');
        } catch (FetchFailed $e) {
            $this->assertSame('content_too_large', $e->reason);
        }
    }

    public function test_oversize_is_cut_mid_transfer_not_after_the_whole_body_arrived(): void
    {
        $started = microtime(true);

        try {
            $this->fetcher(1_048_576)->fetch($this->url('/slow'));
            $this->fail('A 5 MB body must not pass a 1 MB cap.');
        } catch (FetchFailed $e) {
            $this->assertSame('content_too_large', $e->reason);
        }

        // The full body takes ~4 s to arrive; the 1 MB cap is reached after ~0.8 s.
        $this->assertLessThan(2.5, microtime(true) - $started, 'the transfer must be abandoned as soon as the cap is passed');
    }

    public function test_a_gzip_bomb_is_abandoned_without_buffering_the_decoded_body(): void
    {
        $before = memory_get_peak_usage(true);

        try {
            $this->fetcher(1_048_576)->fetch($this->url('/bomb'));
            $this->fail('A 40 MB decoded body must not pass a 1 MB cap.');
        } catch (FetchFailed $e) {
            $this->assertSame('content_too_large', $e->reason);
        }

        $growth = memory_get_peak_usage(true) - $before;
        $this->assertLessThan(16 * 1024 * 1024, $growth, 'peak memory must stay far below the 40 MB decoded size');
    }

    public function test_same_host_redirect_followed_and_cross_host_refused_on_a_real_socket(): void
    {
        $fetcher = $this->fetcher();

        $this->assertSame('<p>hello</p>', $fetcher->fetch($this->url('/redirect'))->body);

        try {
            $fetcher->fetch($this->url('/redirect-out'));
            $this->fail('Expected FetchFailed.');
        } catch (FetchFailed $e) {
            $this->assertSame('cross_host_redirect', $e->reason);
            $this->assertStringContainsString('other.test', $e->getMessage());
        }
    }
}
