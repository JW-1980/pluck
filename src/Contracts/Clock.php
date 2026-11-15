<?php

declare(strict_types=1);

namespace Pluck\Contracts;

interface Clock
{
    /** Unix time in seconds, with fractions. */
    public function now(): float;
}
