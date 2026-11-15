<?php

declare(strict_types=1);

namespace Pluck\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pluck\Client\InvalidRequest;
use Pluck\Client\RenderRequest;
use Pluck\Client\StripMode;

final class RenderRequestTest extends TestCase
{
    public function test_defaults_match_the_contract(): void
    {
        $body = (new RenderRequest('https://shop.example/p/1'))->toArray();

        $this->assertSame('conservative', $body['strip']);
        $this->assertSame(60_000, $body['max_timeout_ms']);
        $this->assertSame(0, $body['ttl_seconds']);
        $this->assertTrue($body['follow_redirects']);
        $this->assertArrayNotHasKey('keep_xpaths', $body);
    }

    public function test_xpath_request_carries_the_named_expressions(): void
    {
        $body = RenderRequest::xpath('https://shop.example/p/1', ['price' => '//span[@itemprop="price"]'])->toArray();

        $this->assertSame('xpath', $body['strip']);
        $this->assertSame(['price' => '//span[@itemprop="price"]'], $body['keep_xpaths']);
    }

    /** @return iterable<string,array{0:\Closure():RenderRequest,1:string}> */
    public static function invalid(): iterable
    {
        yield 'empty url' => [fn () => new RenderRequest(''), 'invalid_url'];
        yield 'ftp scheme' => [fn () => new RenderRequest('ftp://shop.example/x'), 'invalid_url'];
        yield 'relative url' => [fn () => new RenderRequest('/p/1'), 'invalid_url'];
        yield 'credentials' => [fn () => new RenderRequest('https://user:pw@shop.example/'), 'invalid_url'];
        yield 'url too long' => [fn () => new RenderRequest('https://shop.example/'.str_repeat('a', 2048)), 'invalid_url'];
        yield 'timeout over cap' => [fn () => new RenderRequest('https://shop.example/', maxTimeoutMs: 90_001), 'invalid_options'];
        yield 'wait over cap' => [fn () => new RenderRequest('https://shop.example/', waitMs: 5_001), 'invalid_options'];
        yield 'ttl over cap' => [fn () => new RenderRequest('https://shop.example/', ttlSeconds: 86_401), 'invalid_options'];
        yield 'preserve scripts with xpath' => [fn () => new RenderRequest('https://shop.example/', StripMode::XPath, ['a' => '//a'], preserveScripts: true), 'invalid_options'];
        yield 'xpath mode without expressions' => [fn () => new RenderRequest('https://shop.example/', StripMode::XPath), 'invalid_xpath'];
        yield 'expressions without xpath mode' => [fn () => new RenderRequest('https://shop.example/', keepXpaths: ['a' => '//a']), 'invalid_options'];
        yield 'too many xpaths' => [fn () => new RenderRequest('https://shop.example/', StripMode::XPath, array_fill_keys(array_map(fn ($i) => "n{$i}", range(1, 17)), '//a')), 'too_many_xpaths'];
        yield 'bad xpath name' => [fn () => new RenderRequest('https://shop.example/', StripMode::XPath, ['Price' => '//a']), 'invalid_xpath'];
        yield 'xpath too long' => [fn () => new RenderRequest('https://shop.example/', StripMode::XPath, ['a' => '//'.str_repeat('a', 500)]), 'invalid_xpath'];
        yield 'blank xpath' => [fn () => new RenderRequest('https://shop.example/', StripMode::XPath, ['a' => '  ']), 'invalid_xpath'];
    }

    /** @param \Closure():RenderRequest $build */
    #[DataProvider('invalid')]
    public function test_contract_violations_fail_fast(\Closure $build, string $code): void
    {
        try {
            $build();
            $this->fail('Expected InvalidRequest.');
        } catch (InvalidRequest $e) {
            $this->assertSame($code, $e->errorCode);
        }
    }

    public function test_limits_are_inclusive(): void
    {
        $request = new RenderRequest('https://shop.example/', maxTimeoutMs: 90_000, waitMs: 5_000, ttlSeconds: 86_400);

        $this->assertSame(90_000, $request->maxTimeoutMs);
    }
}
