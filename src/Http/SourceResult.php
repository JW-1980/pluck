<?php

declare(strict_types=1);

namespace Pluck\Http;

use Pluck\Client\Outcome;

/**
 * What a {@see \Pluck\Contracts\SourceDriver} found. Content exists only for
 * {@see Outcome::Fetched}; the constructor enforces it so a blocked page can
 * never travel onward looking like an empty page.
 */
final readonly class SourceResult
{
    public function __construct(
        public Outcome $outcome,
        public string $renderer,
        public ?int $shopStatus = null,
        public ?string $finalUrl = null,
        public ?string $body = null,
        public ?string $blockSignal = null,
        public ?int $retryAfterSeconds = null,
        public ?string $error = null,
    ) {
        if ($outcome === Outcome::Fetched && $body === null) {
            throw new \LogicException('A fetched result must carry a body.');
        }
        if ($outcome !== Outcome::Fetched && $body !== null) {
            throw new \LogicException("A '{$outcome->value}' result must not carry a body.");
        }
    }

    public function isFetched(): bool
    {
        return $this->outcome === Outcome::Fetched;
    }
}
