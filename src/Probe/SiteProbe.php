<?php

declare(strict_types=1);

namespace Pluck\Probe;

use Pluck\Client\Outcome;
use Pluck\Contracts\Clock;
use Pluck\Contracts\DomainThrottle;
use Pluck\Contracts\SourceDriver;
use Pluck\Support\SystemClock;

/**
 * Answers "does this site need FlareSolverr / a browser, or does a plain GET
 * from this host work?" with evidence rather than a guess.
 *
 * The first rung MUST be the plain HTTP driver and it is judged from the vantage
 * point of the machine running the probe — run it on the webhost (mijn.host)
 * whose behaviour you want to know; a result from another IP says nothing about
 * that host (WAFs judge IP reputation and TLS fingerprint, not just headers).
 *
 * Every rung is run (not just until the first success) so the report shows what
 * each one gets, but each shop touch books a politeness slot first.
 */
final class SiteProbe
{
    /** @var callable(float):void */
    private $sleeper;

    /**
     * @param list<SourceDriver>        $rungs   plain HTTP first, then stronger rungs
     * @param null|callable(float):void $sleeper defaults to usleep
     */
    public function __construct(
        private readonly array $rungs,
        private readonly DomainThrottle $throttle,
        private readonly Clock $clock = new SystemClock(),
        ?callable $sleeper = null,
    ) {
        if ($rungs === [] || $rungs[0]->name() !== 'http') {
            throw new \InvalidArgumentException('The first probe rung must be the plain "http" driver.');
        }

        $this->sleeper = $sleeper ?? static function (float $seconds): void {
            if ($seconds > 0) {
                usleep((int) round($seconds * 1_000_000));
            }
        };
    }

    public function probe(string $url): ProbeReport
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $reports = [];

        foreach ($this->rungs as $rung) {
            ($this->sleeper)($this->throttle->reserve($host));
            $started = $this->clock->now();
            $result = $rung->fetch($url);

            $reports[] = new RungReport(
                $rung->name(),
                $result,
                $result->isFetched() ? ContentSignals::analyse((string) $result->body) : null,
                $this->clock->now() - $started,
            );
        }

        return new ProbeReport($url, self::decide($reports), $reports);
    }

    /** @param list<RungReport> $reports */
    public static function decide(array $reports): ProbeVerdict
    {
        $plain = $reports[0];
        $stronger = array_slice($reports, 1);

        if ($plain->result->outcome === Outcome::Fetched) {
            $signals = $plain->signals;
            if ($signals !== null && $signals->hasProductData()) {
                return ProbeVerdict::PlainHttp;
            }

            if ($signals !== null && $signals->looksLikeShell()) {
                return ProbeVerdict::NeedsJavascript;
            }

            return ProbeVerdict::Inconclusive;
        }

        if ($plain->result->outcome === Outcome::Blocked) {
            if ($stronger === []) {
                return ProbeVerdict::PlainBlockedUntested;
            }

            // "Got through" = real content came back. Product markup is NOT required of a
            // stronger rung: promo and category pages legitimately carry none.
            $through = array_values(array_filter(
                $stronger,
                static fn (RungReport $r): bool => $r->result->isFetched() && $r->signals !== null && ! $r->signals->looksLikeShell() && $r->signals->visibleTextChars >= ContentSignals::SHELL_TEXT_CHARS,
            ));

            foreach ($through as $report) {
                if ($report->rung === 'flaresolverr') {
                    return ProbeVerdict::NeedsFlareSolverr;
                }
            }

            if ($through !== []) {
                return ProbeVerdict::NeedsBrowser;
            }

            return ProbeVerdict::BlockedEverywhere;
        }

        if (str_starts_with((string) $plain->result->error, 'cross_host_redirect')) {
            return ProbeVerdict::MovedHost;
        }

        // shop_error / upstream_error on the plain rung: nothing proves a block or a page.
        return ProbeVerdict::Unreachable;
    }
}
