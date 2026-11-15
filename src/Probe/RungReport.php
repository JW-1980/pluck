<?php

declare(strict_types=1);

namespace Pluck\Probe;

use Pluck\Http\SourceResult;

final readonly class RungReport
{
    public function __construct(
        public string $rung,
        public SourceResult $result,
        public ?ContentSignals $signals,
        public float $elapsedSeconds,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'rung' => $this->rung,
            'outcome' => $this->result->outcome->value,
            'shop_status' => $this->result->shopStatus,
            'final_url' => $this->result->finalUrl,
            'block_signal' => $this->result->blockSignal,
            'error' => $this->result->error,
            'elapsed_seconds' => round($this->elapsedSeconds, 2),
            'signals' => $this->signals === null ? null : [
                'bytes' => $this->signals->bytes,
                'json_ld_product' => $this->signals->jsonLdProduct,
                'price_markup' => $this->signals->priceMarkup,
                'visible_text_chars' => $this->signals->visibleTextChars,
                'mount_node' => $this->signals->mountNode,
            ],
        ];
    }
}
