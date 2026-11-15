<?php

declare(strict_types=1);

namespace Pluck\Http;

use Pluck\Client\Outcome;
use Pluck\Contracts\DomainThrottle;
use Pluck\Contracts\SourceDriver;

/**
 * Ordered renderer chain for consumers that fetch directly (transition period,
 * or sites that never adopt the proxy): plain HTTP -> FlareSolverr -> browser —
 * the shape of Inktomi's three-layer fetch (FUNCTIONAL_DESIGN §4 #3).
 *
 * Rules (Inktomi `fetcher.py` behaviours):
 *  - every shop touch first books a politeness slot (3 s/domain, 1 s global);
 *  - a domain in block cooldown is NOT contacted: the answer is `blocked` with
 *    `retryAfterSeconds`, never a retry storm;
 *  - `blocked` and `upstream_error` move to the next rung; `shop_error` stops
 *    (a 404/410 is not cured by a bigger browser);
 *  - when every rung fails, the most informative failure is returned
 *    (blocked beats upstream_error) and a block starts the domain cooldown.
 */
final class DirectSourceChain implements SourceDriver
{
    /** @var list<SourceDriver> */
    private readonly array $rungs;

    /** @var callable(float):void */
    private $sleeper;

    /**
     * @param list<SourceDriver>             $rungs   in escalation order
     * @param null|callable(float):void      $sleeper receives seconds to wait; defaults to usleep
     */
    public function __construct(array $rungs, private readonly DomainThrottle $throttle, ?callable $sleeper = null)
    {
        if ($rungs === []) {
            throw new \InvalidArgumentException('A source chain needs at least one driver.');
        }

        $this->rungs = $rungs;
        $this->sleeper = $sleeper ?? static function (float $seconds): void {
            if ($seconds > 0) {
                usleep((int) round($seconds * 1_000_000));
            }
        };
    }

    public function name(): string
    {
        return 'chain';
    }

    public function fetch(string $url): SourceResult
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if (($left = $this->throttle->cooldownRemaining($host)) !== null) {
            return new SourceResult(
                Outcome::Blocked,
                $this->name(),
                blockSignal: 'cooldown',
                retryAfterSeconds: $left,
                error: "Domain {$host} is in block cooldown; the shop was not contacted.",
            );
        }

        $blocked = null;
        $failed = null;

        foreach ($this->rungs as $rung) {
            ($this->sleeper)($this->throttle->reserve($host));
            $result = $rung->fetch($url);

            switch ($result->outcome) {
                case Outcome::Fetched:
                case Outcome::ShopError:
                    return $result;
                case Outcome::Blocked:
                    $blocked = $result;
                    break;
                case Outcome::UpstreamError:
                    $failed = $result;
                    break;
            }
        }

        if ($blocked !== null) {
            $this->throttle->markBlocked($host);

            return $blocked;
        }

        return $failed ?? throw new \LogicException('A chain with rungs produced no result.');
    }
}
