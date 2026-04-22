<?php

namespace App\Services\Indicator;

use App\Models\MarketIndicator;
use App\Services\Trading\DTO\CandleDTO;
use App\Services\Trading\DTO\IndicatorDTO;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * IndicatorService
 *
 * Calculates RSI, EMA9, EMA21, and trend from a time-series prices array.
 *
 * Primary entry point is `calculateFromPrices()`, which accepts a flat array of
 * float prices (oldest to newest) and returns all computed indicator values.
 *
 * The `process()` method is retained for backward compatibility but is
 * superseded by the new price-array-based calculation flow.
 */
class IndicatorService
{
    private const LOAD_LIMIT = 100;

    private const RSI_PERIOD = 14;

    /**
     * Calculate indicators from candle DTOs grouped by timeframe.
     *
     * @param  array<int, CandleDTO>  $candles
     * @param  array<string>  $timeframes
     * @return array<int, IndicatorDTO>
     */
    public function calculateFromCandles(array $candles, array $timeframes): array
    {
        $results = [];

        foreach ($timeframes as $timeframe) {
            $timeframeCandles = array_values(array_filter(
                $candles,
                static fn (CandleDTO $candle): bool => $candle->timeframe === $timeframe,
            ));

            if ($timeframeCandles === []) {
                continue;
            }

            $prices = array_map(
                static fn (CandleDTO $candle): float => $candle->close,
                $timeframeCandles,
            );

            $indicators = $this->calculateFromPrices($prices);

            if ($indicators === null) {
                continue;
            }

            $results[] = new IndicatorDTO(
                timeframe: $timeframe,
                rsi: (float) $indicators['rsi'],
                trend: (string) $indicators['trend'],
                volumeRatio: 0.0,
                price: (float) $indicators['price'],
            );
        }

        return $results;
    }

    /**
     * Calculate RSI, EMA9, EMA21, trend, and latest price from a prices array.
     *
     * Prices must be ordered oldest-to-newest. Returns null if there are fewer
     * than 21 prices (minimum required for EMA21) or if any indicator is null.
     *
     * @param  array<int, float>  $prices  Time-ordered price array (oldest first)
     * @return array{price: float, rsi: float, ema9: float, ema21: float, trend: string}|null
     */
    public function calculateFromPrices(array $prices): ?array
    {
        if (count($prices) < 21) {
            return null;
        }

        /** @var Collection<int, float> $priceCollection */
        $priceCollection = collect(array_values($prices))
            ->map(fn (mixed $p): float => (float) $p);

        $rsi = $this->calculateRsi($priceCollection);
        $ema9 = $this->calculateEmaFromPrices($priceCollection, 9);
        $ema21 = $this->calculateEmaFromPrices($priceCollection, 21);

        if ($rsi === null || $ema9 === null || $ema21 === null) {
            return null;
        }

        $trend = $this->calculateTrend($ema9, $ema21) ?? 'sideways';

        return [
            'price' => (float) $priceCollection->last(),
            'rsi' => $rsi,
            'ema9' => $ema9,
            'ema21' => $ema21,
            'trend' => $trend,
        ];
    }

    /**
     * Calculate indicators for the latest row of the given coin and timeframe.
     *
     * Reads recent historical rows from market_indicators and updates the latest row.
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

        /** @var array<int, float> $prices */
        $prices = $rows
            ->pluck('price')
            ->map(static fn (mixed $price): float => (float) $price)
            ->values()
            ->all();

        $indicators = $this->calculateFromPrices($prices);

        if ($indicators === null) {
            Log::warning('[IndicatorService] Not enough data for indicator calculation', [
                'coin' => $coin,
                'timeframe' => $timeframe,
                'count' => count($prices),
            ]);

            return;
        }

        MarketIndicator::query()
            ->whereKey($latestRow->id)
            ->update([
                'rsi' => $indicators['rsi'],
                'ema9' => $indicators['ema9'],
                'ema21' => $indicators['ema21'],
                'trend' => $indicators['trend'],
            ]);
    }

    /**
     * Calculate RSI using a standard 14-period SMA of gains and losses.
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
        $recentDiffs = $diffs->slice(-self::RSI_PERIOD)->values();

        if ($recentDiffs->isEmpty()) {
            return null;
        }

        $averageGain = $recentDiffs->map(static fn (float $d): float => max($d, 0.0))->avg();
        $averageLoss = $recentDiffs->map(static fn (float $d): float => max(-$d, 0.0))->avg();

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
     * Calculate EMA from scratch using the full price series.
     *
     * Seeds the EMA with the SMA of the first N periods, then applies the
     * standard EMA formula iteratively for all remaining prices.
     *
     * @param  Collection<int, float>  $prices  Full price series (oldest first)
     * @param  int  $period  EMA period (e.g. 9, 21)
     */
    private function calculateEmaFromPrices(Collection $prices, int $period): ?float
    {
        if ($prices->count() < $period) {
            return null;
        }

        $multiplier = 2.0 / ($period + 1.0);

        // Seed: SMA of the first N prices.
        $ema = (float) $prices->slice(0, $period)->avg();

        // Iteratively apply EMA formula to all prices after the seed window.
        foreach ($prices->slice($period)->values() as $price) {
            $ema = ($price - $ema) * $multiplier + $ema;
        }

        return $ema;
    }

    /**
     * Determine trend direction from EMA9 and EMA21.
     *
     * Considers "sideways" when the relative difference is less than 0.1%.
     */
    private function calculateTrend(?float $ema9, ?float $ema21): ?string
    {
        if ($ema9 === null || $ema21 === null || $ema21 === 0.0) {
            return null;
        }

        $relativeDiff = abs($ema9 - $ema21) / $ema21;

        if ($relativeDiff < 0.001) {
            return 'sideways';
        }

        return $ema9 > $ema21 ? 'uptrend' : 'downtrend';
    }
}
