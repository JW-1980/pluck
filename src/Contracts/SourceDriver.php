<?php

declare(strict_types=1);

namespace Pluck\Contracts;

use Pluck\Http\SourceResult;

/**
 * Fetches one shop page by some mechanism (plain HTTP, FlareSolverr, ...).
 *
 * Implementations never throw for a shop-side failure: they return a
 * {@see SourceResult} whose outcome says what happened. A blocked page is
 * {@see \Pluck\Client\Outcome::Blocked}, never an empty "fetched".
 */
interface SourceDriver
{
    /** Stable renderer name, e.g. `http` or `flaresolverr` (appears in logs and meta). */
    public function name(): string;

    public function fetch(string $url): SourceResult;
}
