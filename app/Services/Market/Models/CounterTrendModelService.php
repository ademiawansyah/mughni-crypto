<?php

namespace App\Services\Market\Models;

use Illuminate\Support\Collection;

class CounterTrendModelService extends AbstractMarketModelService
{
    /**
     * Evaluate a single coin for a reversal setup.
     *
     * @param  array<string, array<string, mixed>|null>|null  $indicators
     */
    public function evaluateCoin(string $coin, ?array $indicators = null): ?ModelSignalDTO
    {
        $resolvedIndicators = $indicators ?? $this->resolveIndicators($coin);
        $entry = $resolvedIndicators['15m'] ?? null;
        $setup = $resolvedIndicators['1h'] ?? null;
        $macro = $resolvedIndicators['4h'] ?? $resolvedIndicators['1h'] ?? null;

        if ($entry === null || $setup === null || $macro === null) {
            return null;
        }

        $liquiditySweep = $this->detectLiquiditySweep($resolvedIndicators);

        if (! $liquiditySweep['detected']) {
            return null;
        }

        $componentScores = $this->calculateComponentScores($resolvedIndicators);
        $weights = config('models.counter_trend.scoring', []);
        $baseScore = $this->calculateWeightedScore($componentScores, $weights);
        $marketRegime = $this->resolveMarketRegime();
        $regimeAdjuster = (int) config(sprintf('models.counter_trend.market_confidence_adjusters.%s', $marketRegime['market_regime'] ?? 'RANGING'), 0);
        $score = $this->clampInt($baseScore + $regimeAdjuster);
        $minimumScore = (int) config('models.counter_trend.min_score_short_tf', 45);

        if ($score < $minimumScore) {
            return null;
        }

        return new ModelSignalDTO(
            model: $this->modelKey(),
            coin: $coin,
            action: $liquiditySweep['direction'],
            score: $score,
            primaryTimeframe: '15m',
            componentScores: $componentScores,
            context: [
                'market_regime' => $marketRegime['market_regime'] ?? 'RANGING',
                'btc_direction' => $marketRegime['btc_direction'] ?? 'SIDEWAYS',
                'entry_trend' => $entry['trend'],
                'setup_trend' => $setup['trend'],
                'macro_trend' => $macro['trend'],
            ],
            reasons: array_values(array_filter([
                $liquiditySweep['reason'],
                $this->detectMarketStructureShift($resolvedIndicators) ? 'market_structure_shift' : null,
                $this->calculateCVDDivergence($resolvedIndicators) ? 'cvd_divergence' : 'cvd_data_unavailable',
                ($componentScores['oi'] ?? 0.0) === 0.0 ? 'oi_data_unavailable' : null,
                ($componentScores['funding'] ?? 0.0) === 0.0 ? 'funding_data_unavailable' : null,
            ])),
        );
    }

    /**
     * Detect a reversal-style liquidity sweep from the entry timeframe.
     *
     * @param  array<string, array<string, mixed>|null>  $indicators
     * @return array{detected: bool, direction: string, strength: float, reason: string}
     */
    public function detectLiquiditySweep(array $indicators): array
    {
        $entry = $indicators['15m'] ?? null;
        $macro = $indicators['4h'] ?? $indicators['1h'] ?? null;

        if ($entry === null || ! is_numeric($entry['rsi'] ?? null)) {
            return [
                'detected' => false,
                'direction' => 'HOLD',
                'strength' => 0.0,
                'reason' => 'missing_entry_indicator',
            ];
        }

        $rsi = (float) $entry['rsi'];
        $volumeRatio = is_numeric($entry['volume_ratio'] ?? null) ? (float) $entry['volume_ratio'] : 0.0;
        $macroTrend = (string) ($macro['trend'] ?? 'sideways');

        if ($rsi <= 35.0) {
            return [
                'detected' => true,
                'direction' => 'BUY',
                'strength' => $volumeRatio >= 1.5 ? 1.0 : 0.8,
                'reason' => $macroTrend === 'downtrend' ? 'oversold_sweep_against_downtrend' : 'oversold_sweep',
            ];
        }

        if ($rsi >= 65.0) {
            return [
                'detected' => true,
                'direction' => 'SELL',
                'strength' => $volumeRatio >= 1.5 ? 1.0 : 0.8,
                'reason' => $macroTrend === 'uptrend' ? 'overbought_sweep_against_uptrend' : 'overbought_sweep',
            ];
        }

        if ($macroTrend === 'downtrend' && $rsi <= 48.0) {
            return [
                'detected' => true,
                'direction' => 'BUY',
                'strength' => 0.75,
                'reason' => 'early_counter_trend_buy',
            ];
        }

        if ($macroTrend === 'uptrend' && $rsi >= 52.0) {
            return [
                'detected' => true,
                'direction' => 'SELL',
                'strength' => 0.75,
                'reason' => 'early_counter_trend_sell',
            ];
        }

        return [
            'detected' => false,
            'direction' => 'HOLD',
            'strength' => 0.0,
            'reason' => 'no_reversal_extreme',
        ];
    }

    /**
     * Detect whether the entry timeframe is shifting against the macro trend.
     *
     * @param  array<string, array<string, mixed>|null>  $indicators
     */
    public function detectMarketStructureShift(array $indicators): bool
    {
        $entryTrend = $indicators['15m']['trend'] ?? null;
        $setupTrend = $indicators['1h']['trend'] ?? null;
        $macroTrend = $indicators['4h']['trend'] ?? $indicators['1h']['trend'] ?? null;

        if (! is_string($entryTrend) || ! is_string($setupTrend) || ! is_string($macroTrend)) {
            return false;
        }

        return $entryTrend !== 'sideways'
            && $entryTrend === $setupTrend
            && $entryTrend !== $macroTrend;
    }

    /**
     * Detect CVD divergence against short-term price direction.
     *
     * @param  array<string, array<string, mixed>|null>  $indicators
     */
    public function calculateCVDDivergence(array $indicators): bool
    {
        $entryPrice = is_numeric($indicators['15m']['price'] ?? null) ? (float) $indicators['15m']['price'] : null;
        $setupPrice = is_numeric($indicators['1h']['price'] ?? null) ? (float) $indicators['1h']['price'] : null;
        $cvdSlope = is_numeric($indicators['15m']['cvd_slope'] ?? null) ? (float) $indicators['15m']['cvd_slope'] : null;

        if ($entryPrice === null || $setupPrice === null || $cvdSlope === null) {
            return false;
        }

        $priceDelta = $entryPrice - $setupPrice;

        // Bullish divergence: price lower while CVD slope turns positive.
        if ($priceDelta < 0.0 && $cvdSlope > 0.0) {
            return true;
        }

        // Bearish divergence: price higher while CVD slope turns negative.
        return $priceDelta > 0.0 && $cvdSlope < 0.0;
    }

    /**
     * Calculate normalized component scores using available indicator data.
     *
     * Missing derivatives data remains explicit zero until Binance inputs exist.
     *
     * @param  array<string, array<string, mixed>|null>  $indicators
     * @return array<string, float>
     */
    public function calculateComponentScores(array $indicators): array
    {
        $liquiditySweep = $this->detectLiquiditySweep($indicators);
        $entry = $indicators['15m'] ?? [];
        $setup = $indicators['1h'] ?? [];
        $macro = $indicators['4h'] ?? $indicators['1h'] ?? [];
        $entryTrend = (string) ($entry['trend'] ?? 'sideways');
        $setupTrend = (string) ($setup['trend'] ?? 'sideways');
        $mssScore = $this->detectMarketStructureShift($indicators)
            ? 1.0
            : ($entryTrend !== 'sideways' && $entryTrend === $setupTrend ? 0.6 : 0.2);
        $volatility = is_numeric($macro['volatility'] ?? null) ? (float) $macro['volatility'] : null;
        $entryOpenInterest = is_numeric($entry['open_interest'] ?? null) ? (float) $entry['open_interest'] : null;
        $setupOpenInterest = is_numeric($setup['open_interest'] ?? null) ? (float) $setup['open_interest'] : null;
        $entryFunding = is_numeric($entry['funding_rate'] ?? null) ? (float) $entry['funding_rate'] : null;
        $macroFunding = is_numeric($macro['funding_rate'] ?? null) ? (float) $macro['funding_rate'] : null;

        $oiScore = 0.0;
        if ($entryOpenInterest !== null && $setupOpenInterest !== null && $setupOpenInterest > 0.0) {
            $change = ($entryOpenInterest - $setupOpenInterest) / $setupOpenInterest;
            $oiScore = $change <= -0.03 ? 1.0 : ($change <= 0.0 ? 0.6 : 0.2);
        }

        $fundingScore = 0.0;
        if ($entryFunding !== null && $macroFunding !== null) {
            $entryAbs = abs($entryFunding);
            $macroAbs = abs($macroFunding);
            $fundingScore = $entryAbs <= $macroAbs ? 1.0 : 0.25;
        }

        return [
            'sweep' => $liquiditySweep['strength'],
            'mss' => $mssScore,
            'oi' => $oiScore,
            'cvd' => $this->calculateCVDDivergence($indicators) ? 1.0 : 0.0,
            'funding' => $fundingScore,
            'atr' => $volatility === null ? 0.0 : ($volatility >= 0.04 ? 1.0 : 0.5),
        ];
    }

    /**
     * @param  Collection<int, ModelSignalDTO>  $signals
     * @return Collection<int, ModelSignalDTO>
     */
    public function rankTopCoins(Collection $signals): Collection
    {
        return $signals
            ->sortByDesc(fn (ModelSignalDTO $signal): int => $signal->score)
            ->take(10)
            ->values();
    }

    /**
     * @return array<int, string>
     */
    protected function requiredTimeframes(): array
    {
        return ['15m', '1h', '4h'];
    }

    protected function modelKey(): string
    {
        return 'counter_trend';
    }
}
