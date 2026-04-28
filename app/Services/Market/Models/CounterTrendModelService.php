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
        $entry = $resolvedIndicators['5m'] ?? null;
        $setup = $resolvedIndicators['10m'] ?? null;
        $macro = $resolvedIndicators['15m'] ?? null;

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
            primaryTimeframe: '5m',
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
        $entry = $indicators['5m'] ?? null;
        $macro = $indicators['15m'] ?? null;

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
        $entryTrend = $indicators['5m']['trend'] ?? null;
        $setupTrend = $indicators['10m']['trend'] ?? null;
        $macroTrend = $indicators['15m']['trend'] ?? null;

        if (! is_string($entryTrend) || ! is_string($setupTrend) || ! is_string($macroTrend)) {
            return false;
        }

        return $entryTrend !== 'sideways'
            && $entryTrend === $setupTrend
            && $entryTrend !== $macroTrend;
    }

    /**
     * Placeholder until trade-flow data is stored alongside indicators.
     *
     * @param  array<string, array<string, mixed>|null>  $indicators
     */
    public function calculateCVDDivergence(array $indicators): bool
    {
        return false;
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
        $entry = $indicators['5m'] ?? [];
        $setup = $indicators['10m'] ?? [];
        $macro = $indicators['15m'] ?? [];
        $entryTrend = (string) ($entry['trend'] ?? 'sideways');
        $setupTrend = (string) ($setup['trend'] ?? 'sideways');
        $mssScore = $this->detectMarketStructureShift($indicators)
            ? 1.0
            : ($entryTrend !== 'sideways' && $entryTrend === $setupTrend ? 0.6 : 0.2);
        $volatility = is_numeric($macro['volatility'] ?? null) ? (float) $macro['volatility'] : null;

        return [
            'sweep' => $liquiditySweep['strength'],
            'mss' => $mssScore,
            'oi' => 0.5,
            'cvd' => $this->calculateCVDDivergence($indicators) ? 1.0 : 0.5,
            'funding' => 0.5,
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
            ->sortByDesc(fn(ModelSignalDTO $signal): int => $signal->score)
            ->take(10)
            ->values();
    }

    /**
     * @return array<int, string>
     */
    protected function requiredTimeframes(): array
    {
        return ['5m', '10m', '15m'];
    }

    protected function modelKey(): string
    {
        return 'counter_trend';
    }
}
