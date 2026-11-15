<?php

declare(strict_types=1);

namespace Pluck\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pluck\Client\Outcome;
use Pluck\Http\HttpSourceDriver;
use Pluck\Http\PoliteFetcher;
use Pluck\Http\SourceResult;
use Pluck\Support\UserAgent;
use Pluck\Tests\Support\Http;
use GuzzleHttp\Psr7\Response;

final class SourceResultTest extends TestCase
{
    public function test_a_fetched_result_without_a_body_cannot_exist(): void
    {
        $this->expectException(\LogicException::class);
        new SourceResult(Outcome::Fetched, 'http', 200);
    }

    public function test_a_blocked_result_cannot_carry_a_body(): void
    {
        $this->expectException(\LogicException::class);
        new SourceResult(Outcome::Blocked, 'http', 403, body: '<p>captcha</p>');
    }

    public function test_empty_string_body_is_still_a_fetched_body(): void
    {
        $this->assertTrue((new SourceResult(Outcome::Fetched, 'http', 200, body: ''))->isFetched());
    }

    public function test_http_driver_end_to_end_classification(): void
    {
        $driver = static fn (Response $r): HttpSourceDriver => new HttpSourceDriver(new PoliteFetcher(Http::client([$r]), Http::publicGuard()));

        $this->assertSame(Outcome::Fetched, $driver(new Response(200, [], '<p>x</p>'))->fetch('https://shop.example/p')->outcome);
        $this->assertSame(Outcome::Blocked, $driver(new Response(403, [], '<TITLE>Access Denied</TITLE>'))->fetch('https://shop.example/p')->outcome);
        $this->assertSame(Outcome::Blocked, $driver(new Response(200, [], '<title>Just a moment...</title>'))->fetch('https://shop.example/p')->outcome);
        $this->assertSame(Outcome::ShopError, $driver(new Response(404, [], 'nope'))->fetch('https://shop.example/p')->outcome);
        $this->assertSame(Outcome::ShopError, $driver(new Response(500, [], 'oops'))->fetch('https://shop.example/p')->outcome);
        $blocked = $driver(new Response(429, ['Retry-After' => '120'], ''))->fetch('https://shop.example/p');
        $this->assertSame(120, $blocked->retryAfterSeconds);
    }

    public function test_user_agent_policy(): void
    {
        $ua = new UserAgent();

        $this->assertStringStartsWith('Mozilla/5.0', $ua->forUrl('https://www.ah.nl/'));
        $this->assertSame('InktomiSuperRaten/1.0', $ua->forUrl('https://world.openfoodfacts.org/x'));
        $this->assertSame('InktomiSuperRaten/1.0', $ua->forHost('OPENFOODFACTS.ORG'));
        $this->assertStringStartsWith('Mozilla/5.0', $ua->forHost('notopenfoodfacts.org'));
        $this->assertSame('custom', (new UserAgent('custom'))->forHost('shop.example'));
    }
}
