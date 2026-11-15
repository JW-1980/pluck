<?php

declare(strict_types=1);

namespace Pluck\Probe;

final readonly class ProbeReport
{
    /** @param list<RungReport> $rungs */
    public function __construct(
        public string $url,
        public ProbeVerdict $verdict,
        public array $rungs,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'verdict' => $this->verdict->value,
            'advice' => $this->verdict->advice(),
            'rungs' => array_map(static fn (RungReport $r): array => $r->toArray(), $this->rungs),
        ];
    }
}
