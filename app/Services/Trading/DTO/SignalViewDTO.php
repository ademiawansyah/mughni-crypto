<?php

namespace App\Services\Trading\DTO;

use Illuminate\Support\Carbon;

/**
 * SignalViewDTO
 *
 * Read-only projection for dashboard display.  Aggregates the latest
 * ai_decisions, market_indicators, and market_contexts for one coin.
 */
readonly class SignalViewDTO
{
    public function __construct(
        public string $coin,
        public ?float $priceAtDecision,
        public ?float $rsi,
        /** UP|DOWN|NEUTRAL — derived from ema9 > ema21 */
        public string $trend,
        public string $action,
        public int $confidence,
        public string $riskLevel,
        public ?string $result,
        public ?string $mtfMode,
        public ?string $mtfAlignment,
        public ?float $priceAfter5m,
        public ?float $priceAfter15m,
        public ?float $maxProfit,
        public ?float $maxDrawdown,
        public ?Carbon $createdAt,
    ) {}
}
