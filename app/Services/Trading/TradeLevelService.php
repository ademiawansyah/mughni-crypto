<?php

namespace App\Services\Trading;

/**
 * TradeLevelService
 *
 * Deterministically appends entry, take-profit, and stop-loss levels to
 * confirmed BUY/SELL decisions.
 */
class TradeLevelService
{
    private const FALLBACK_VOLATILITY_PERCENT = 2.0;

    /**
     * Append actionable trade levels to a confirmed BUY/SELL decision.
     *
     * @param  array{action: string, confidence: int, risk_level: string, reason: string, entry?: float, take_profit?: float, stop_loss?: float}  $decision
     * @return array{action: string, confidence: int, risk_level: string, reason: string, entry?: float, take_profit?: float, stop_loss?: float}
     */
    public function appendTradeLevels(array $decision, float $entryPrice, ?float $priceChange24h, bool $isSignalConfirmed): array
    {
        if (! $isSignalConfirmed) {
            return $decision;
        }

        $action = strtoupper((string) $decision['action']);

        if (! in_array($action, ['BUY', 'SELL'], true)) {
            return $decision;
        }

        $volatilityPercent = $this->normalizeVolatilityPercent(
            $priceChange24h ?? self::FALLBACK_VOLATILITY_PERCENT
        );
        $volatilityRatio = $volatilityPercent / 100;

        if ($action === 'BUY') {
            $takeProfit = $entryPrice * (1 + ($volatilityRatio * 1.5));
            $stopLoss = $entryPrice * (1 - ($volatilityRatio * 1.0));
        } else {
            $takeProfit = $entryPrice * (1 - ($volatilityRatio * 1.5));
            $stopLoss = $entryPrice * (1 + ($volatilityRatio * 1.0));
        }

        $precision = $this->resolvePrecision($entryPrice);

        $decision['entry'] = round($entryPrice, $precision);
        $decision['take_profit'] = round(max($takeProfit, 0.0), $precision);
        $decision['stop_loss'] = round(max($stopLoss, 0.0), $precision);

        return $decision;
    }

    /**
     * Clamp volatility to the configured 1%-5% band.
     */
    private function normalizeVolatilityPercent(float $priceChangePercent): float
    {
        return max(1.0, min(5.0, abs($priceChangePercent)));
    }

    /**
     * Resolve decimal precision by asset price range.
     */
    private function resolvePrecision(float $entryPrice): int
    {
        $absolutePrice = abs($entryPrice);

        return match (true) {
            $absolutePrice >= 1000 => 2,
            $absolutePrice >= 100 => 3,
            $absolutePrice >= 1 => 4,
            $absolutePrice >= 0.1 => 5,
            $absolutePrice >= 0.01 => 6,
            default => 8,
        };
    }
}
