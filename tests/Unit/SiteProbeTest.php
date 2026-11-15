<?php

declare(strict_types=1);

namespace Pluck\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pluck\Client\Outcome;
use Pluck\Contracts\SourceDriver;
use Pluck\Http\SourceResult;
use Pluck\Probe\ContentSignals;
use Pluck\Probe\ProbeVerdict;
use Pluck\Probe\RungReport;
use Pluck\Probe\SiteProbe;
use Pluck\Support\ArrayCache;
use Pluck\Tests\Support\FakeClock;
use Pluck\Throttle\PoliteDomainThrottle;

final class SiteProbeTest extends TestCase
{
    private const PRODUCT = '<script type="application/ld+json">{"@type":"Product","name":"x"}</script><p>Magnesium</p>';
    private const SHELL = '<html><body><div id="root"></div><script src="app.js"></script></body></html>';

    private static function rung(string $name, Outcome $outcome, ?string $html = null): RungReport
    {
        $result = new SourceResult($outcome, $name, $outcome === Outcome::Fetched ? 200 : 403, body: $outcome === Outcome::Fetched ? $html : null);

        return new RungReport($name, $result, $html !== null && $outcome === Outcome::Fetched ? ContentSignals::analyse($html) : null, 0.1);
    }

    private static function article(): string
    {
        return '<html><body><h1>Aanbiedingen</h1><p>'.str_repeat('Hagelslag deze week voor een euro minder. ', 30).'</p></body></html>';
    }

    /** @return iterable<string,array{0:list<RungReport>,1:ProbeVerdict}> */
    public static function scenarios(): iterable
    {
        yield 'plain works on product data' => [[self::rung('http', Outcome::Fetched, self::PRODUCT)], ProbeVerdict::PlainHttp];
        yield 'plain returns a JS shell' => [[self::rung('http', Outcome::Fetched, self::SHELL)], ProbeVerdict::NeedsJavascript];
        yield 'plain returns text without product data' => [[self::rung('http', Outcome::Fetched, self::article())], ProbeVerdict::Inconclusive];
        yield 'plain blocked, nothing else tried' => [[self::rung('http', Outcome::Blocked)], ProbeVerdict::PlainBlockedUntested];
        yield 'plain blocked, flaresolverr gets real content' => [[self::rung('http', Outcome::Blocked), self::rung('flaresolverr', Outcome::Fetched, self::article())], ProbeVerdict::NeedsFlareSolverr];
        yield 'plain blocked, only chromium gets through' => [[self::rung('http', Outcome::Blocked), self::rung('flaresolverr', Outcome::Blocked), self::rung('chromium', Outcome::Fetched, self::article())], ProbeVerdict::NeedsBrowser];
        yield 'flaresolverr beats chromium when both pass' => [[self::rung('http', Outcome::Blocked), self::rung('chromium', Outcome::Fetched, self::article()), self::rung('flaresolverr', Outcome::Fetched, self::article())], ProbeVerdict::NeedsFlareSolverr];
        yield 'stronger rung returns only a shell: not through' => [[self::rung('http', Outcome::Blocked), self::rung('chromium', Outcome::Fetched, self::SHELL)], ProbeVerdict::BlockedEverywhere];
        yield 'everything blocked' => [[self::rung('http', Outcome::Blocked), self::rung('flaresolverr', Outcome::Blocked), self::rung('chromium', Outcome::UpstreamError)], ProbeVerdict::BlockedEverywhere];
        yield 'plain redirected to another host' => [[new RungReport('http', new SourceResult(Outcome::ShopError, 'http', error: 'cross_host_redirect: Redirect leaves the shop host (to hoogvliet.nl).'), null, 0.1), self::rung('chromium', Outcome::Fetched, self::article())], ProbeVerdict::MovedHost];
        yield 'plain shop error' => [[self::rung('http', Outcome::ShopError)], ProbeVerdict::Unreachable];
    }

    /** @param list<RungReport> $reports */
    #[DataProvider('scenarios')]
    public function test_verdict(array $reports, ProbeVerdict $expected): void
    {
        $this->assertSame($expected, SiteProbe::decide($reports));
    }

    public function test_probe_runs_every_rung_and_books_a_politeness_slot_before_each(): void
    {
        $clock = new FakeClock();
        $throttle = new PoliteDomainThrottle(new ArrayCache($clock), $clock);
        $waits = [];
        $driver = new class('http', Outcome::Blocked) implements SourceDriver {
            public int $calls = 0;

            public function __construct(private string $n, private Outcome $o) {}

            public function name(): string
            {
                return $this->n;
            }

            public function fetch(string $url): SourceResult
            {
                $this->calls++;

                return new SourceResult($this->o, $this->n, 403);
            }
        };
        $second = new class('flaresolverr') implements SourceDriver {
            public function __construct(private string $n) {}

            public function name(): string
            {
                return $this->n;
            }

            public function fetch(string $url): SourceResult
            {
                return new SourceResult(Outcome::Fetched, $this->n, 200, body: '<p>'.str_repeat('tekst ', 200).'</p>');
            }
        };

        $probe = new SiteProbe([$driver, $second], $throttle, $clock, function (float $s) use (&$waits, $clock): void {
            $waits[] = $s;
            $clock->advance($s);
        });
        $report = $probe->probe('https://shop.example/p/1');

        $this->assertSame(1, $driver->calls);
        $this->assertCount(2, $report->rungs);
        $this->assertSame(ProbeVerdict::NeedsFlareSolverr, $report->verdict);
        $this->assertSame(0.0, $waits[0]);
        $this->assertSame(3.0, $waits[1], 'second shop touch waits the 3 s per-domain floor');
    }

    public function test_first_rung_must_be_plain_http(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SiteProbe([new class implements SourceDriver {
            public function name(): string
            {
                return 'flaresolverr';
            }

            public function fetch(string $url): SourceResult
            {
                return new SourceResult(Outcome::UpstreamError, 'flaresolverr');
            }
        }], new PoliteDomainThrottle(new ArrayCache()));
    }

    public function test_content_signals_distinguish_product_pages_from_shells(): void
    {
        $product = ContentSignals::analyse(self::PRODUCT);
        $shell = ContentSignals::analyse(self::SHELL);
        $inlineState = ContentSignals::analyse('<div id="root"></div><script>window.__S={"price":12.5}</script>');

        $this->assertTrue($product->jsonLdProduct);
        $this->assertTrue($product->hasProductData());
        $this->assertFalse($shell->hasProductData());
        $this->assertTrue($shell->looksLikeShell());
        $outSystems = ContentSignals::analyse('<html><head><script src="/scripts/OutSystems.js"></script></head><body><noscript>JavaScript is required</noscript></body></html>');
        $this->assertTrue($outSystems->javascriptNotice);
        $this->assertTrue($outSystems->looksLikeShell(), 'a shell without a conventional mount node is still a shell');
        $this->assertFalse(ContentSignals::analyse('<body>'.str_repeat('Wij verkopen vitamines. ', 40).'Schakel JavaScript in voor chat.</body>')->looksLikeShell(), 'a long server-rendered page that merely mentions JavaScript is not a shell');
        $this->assertFalse($inlineState->looksLikeShell(), 'price data embedded in the HTML means plain GET already carries it');
    }
}
