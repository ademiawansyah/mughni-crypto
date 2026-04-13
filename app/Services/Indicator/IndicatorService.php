<?php

namespace App\Services\Indicator;

use App\Models\MarketIndicator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * IndicatorService
 *
 * Calculates RSI, EMA9, EMA21, and trend for a specific coin + timeframe.
 * It updates only the latest market_indicators row using recent historical data.
 */
class IndicatorService
{
    private const LOAD_LIMIT = 100;

    private const RSI_PERIOD = 14;

    /**
     * Calculate indicators for the latest row of the given coin and timeframe.
     *
     * @param  string  $coin  Coin identifier (e.g. bitcoin).
     * @param  string  $timeframe  Timeframe key (e.g. 5m).
     */
    public function process(string $coin, string $timeframe): void
    {
        $rows = MarketIndicator::query()
            ->where('coin', $coin)
            ->where('timeframe', $timeframe)
            ->orderByDesc('timestamp')
            ->limit(self::LOAD_LIMIT)
            ->get(['id', 'price', 'timestamp', 'rsi', 'ema9', 'ema21'])
            ->sortBy('timestamp')
            ->values();

        /** @var MarketIndicator|null $latestRow */
        $latestRow = $rows->last();

        if ($latestRow === null) {
            return;
        }

        $prices = $rows
            ->pluck('price')
            ->map(static fn(mixed $price): float => (float) $price)
            ->values();

        $rsi = $this->calculateRsi($prices);
        $ema9 = $this->calculateEma($rows, $prices, 9, 'ema9');
        $ema21 = $this->calculateEma($rows, $prices, 21, 'ema21');
        $trend = $this->calculateTrend($ema9, $ema21);

        $payload = [
            'rsi' => $rsi,
            'ema9' => $ema9,
            'ema21' => $ema21,
        ];

        if ($trend !== null) {
            $payload['trend'] = $trend;
        }

        MarketIndicator::query()
            ->whereKey($latestRow->id)
            ->update($payload);
    }

    /**
     * Calculate RSI for the latest point using recent price differences.
     *
     * @param  Collection<int, float>  $prices
     */
    private function calculateRsi(Collection $prices): ?float
    {
        if ($prices->count() < self::RSI_PERIOD) {
            return null;
        }

        $diffs = collect();

        for ($index = 1; $index < $prices->count(); $index++) {
            $diffs->push($prices[$index] - $prices[$index - 1]);
        }

        /** @var Collection<int, float> $recentDiffs */
        $recentDiffs = $diffs
            ->slice(-self::RSI_PERIOD)
            ->values();

        if ($recentDiffs->isEmpty()) {
            return null;
        }

        $averageGain = $recentDiffs
            ->map(static fn(float $diff): float => max($diff, 0.0))
            ->avg();

        $averageLoss = $recentDiffs
            ->map(static fn(float $diff): float => max(-$diff, 0.0))
            ->avg();

        if ($averageGain === 0.0 && $averageLoss === 0.0) {
            return 50.0;
        }

        if ($averageLoss === 0.0) {
            return 100.0;
        }

        $relativeStrength = $averageGain / $averageLoss;

        return 100.0 - (100.0 / (1.0 + $relativeStrength));
    }

    /**
     * Calculate EMA value for the latest row.
     *
     * Uses previous row EMA if available; otherwise initializes from SMA.
     *
     * @param  Collection<int, MarketIndicator>  $rows
     * @param  Collection<int, float>  $prices
     */
    private function calculateEma(Collection $rows, Collection $prices, int $period, string $emaField): ?float
    {
        if ($prices->count() < $period) {
            return null;
        }

        $latestPrice = (float) $prices->last();
        $previousRow = $rows->get($rows->count() - 2);

        if ($previousRow !== null && $previousRow->{$emaField} !== null) {
            $previousEma = (float) $previousRow->{$emaField};
            $multiplier = 2.0 / ($period + 1.0);

            return ($latestPrice - $previousEma) * $multiplier + $previousEma;
        }

        /** @var Collection<int, float> $seedWindow */
        $seedWindow = $prices->slice(-$period)->values();

        return $seedWindow->avg();
    }

    /**
     * Determine trend from EMA9 and EMA21.
     */
    private function calculateTrend(?float $ema9, ?float $ema21): ?string
    {
        if ($ema9 === null || $ema21 === null) {
            return null;
        }

        if ($ema9 > $ema21) {
            return 'uptrend';
        }

        if ($ema9 < $ema21) {
            return 'downtrend';
        }

        return 'sideways';
    }
}
