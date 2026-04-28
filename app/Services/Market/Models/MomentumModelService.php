<?php

namespace App\Services\Market\Models;

use Illuminate\Support\Collection;

class MomentumModelService extends AbstractMarketModelService
{
    /**
     * Evaluate one coin for trend-continuation momentum.
     *
     * @param  array<string, array<string, mixed>|null>|null  $indicators
     */
    public function evaluateCoin(string $coin, ?array $indicators = null): ?ModelSignalDTO
    {
        $resolvedIndicators = $indicators ?? $this->resolveIndicators($coin);
        $entry = $resolvedIndicators['5m'] ?? null;
        $setup = $resolvedIndicators['10m'] ?? null;
        $macro = $resolvedIndicators['15m'] ?? null;
        $context = $resolvedIndicators['15m'] ?? null;

        if ($entry === null || $setup === null || $macro === null || $context === null) {
            return null;
        }

        $componentScores = $this->calculateComponentScores($resolvedIndicators);
        $action = $this->determineAction($resolvedIndicators);

        if ($action === 'HOLD') {
            return null;
        }

        $weights = config('models.momentum.scoring', []);
        $baseScore = $this->calculateWeightedScore($componentScores, $weights);
        $marketRegime = $this->resolveMarketRegime();
        $regimeAdjuster = (int) config(sprintf('models.momentum.market_confidence_adjusters.%s', $marketRegime['market_regime'] ?? 'RANGING'), 0);
        $score = $this->clampInt($baseScore + $regimeAdjuster);
        $minimumScore = (int) config('models.momentum.min_score_short_tf', 45);

        if ($score < $minimumScore) {
            return null;
        }

        return new ModelSignalDTO(
            model: $this->modelKey(),
            coin: $coin,
            action: $action,
            score: $score,
            primaryTimeframe: '15m',
            componentScores: $componentScores,
            context: [
                'market_regime' => $marketRegime['market_regime'] ?? 'RANGING',
                'btc_direction' => $marketRegime['btc_direction'] ?? 'SIDEWAYS',
                'entry_trend' => $entry['trend'],
                'setup_trend' => $setup['trend'],
                'macro_trend' => $macro['trend'],
                'context_trend' => $context['trend'],
            ],
            reasons: array_values(array_filter([
                $this->detectBreakOfStructure($resolvedIndicators) ? 'break_of_structure' : null,
                ($componentScores['macd'] ?? 0.0) === 0.0 ? 'macd_data_unavailable' : null,
                ($componentScores['cvd'] ?? 0.0) === 0.0 ? 'cvd_data_unavailable' : null,
                ($componentScores['oi'] ?? 0.0) >= 0.6 ? 'volume_expansion_proxy' : null,
            ])),
        );
    }

    /**
     * Calculate normalized momentum component scores.
     *
     * @param  array<string, array<string, mixed>|null>  $indicators
     * @return array<string, float>
     */
    public function calculateComponentScores(array $indicators): array
    {
        $setup = $indicators['10m'] ?? [];
        $macro = $indicators['15m'] ?? [];
        $context = $indicators['15m'] ?? [];
        $macroTrend = (string) ($macro['trend'] ?? 'sideways');
        $contextTrend = (string) ($context['trend'] ?? 'sideways');
        $macroRsi = is_numeric($macro['rsi'] ?? null) ? (float) $macro['rsi'] : 50.0;
        $volumeRatio = is_numeric($setup['volume_ratio'] ?? null) ? (float) $setup['volume_ratio'] : 0.0;

        $emaScore = 0.0;
        if (
            ($this->bullishTrend($macroTrend) && $this->bullishTrend($contextTrend))
            || ($this->bearishTrend($macroTrend) && $this->bearishTrend($contextTrend))
        ) {
            $emaScore = 1.0;
        }

        $rsiScore = 0.0;
        if ($this->bullishTrend($macroTrend)) {
            if ($macroRsi >= 50.0 && $macroRsi <= 65.0) {
                $rsiScore = 1.0;
            } elseif ($macroRsi >= 45.0 && $macroRsi <= 70.0) {
                $rsiScore = 0.6;
            }
        }

        if ($this->bearishTrend($macroTrend)) {
            if ($macroRsi >= 35.0 && $macroRsi <= 50.0) {
                $rsiScore = 1.0;
            } elseif ($macroRsi >= 30.0 && $macroRsi <= 55.0) {
                $rsiScore = 0.6;
            }
        }

        return [
            'ema' => $emaScore,
            'macd' => 0.5,
            'rsi' => $rsiScore,
            'oi' => $volumeRatio >= 1.5 ? 1.0 : ($volumeRatio >= 1.2 ? 0.6 : 0.0),
            'bos' => $this->detectBreakOfStructure($indicators) ? 1.0 : 0.0,
            'cvd' => 0.5,
        ];
    }

    /**
     * Detect whether lower timeframes are aligned with the established trend.
     *
     * @param  array<string, array<string, mixed>|null>  $indicators
     */
    public function detectBreakOfStructure(array $indicators): bool
    {
        $entryTrend = $indicators['5m']['trend'] ?? null;
        $setupTrend = $indicators['10m']['trend'] ?? null;
        $macroTrend = $indicators['15m']['trend'] ?? null;

        if (! is_string($entryTrend) || ! is_string($setupTrend) || ! is_string($macroTrend)) {
            return false;
        }

        if ($macroTrend === 'sideways') {
            return false;
        }

        return ($entryTrend !== 'sideways' && $entryTrend === $setupTrend && $setupTrend === $macroTrend)
            || ($setupTrend !== 'sideways' && $setupTrend === $macroTrend);
    }

    /**
     * Determine BUY / SELL / HOLD from trend alignment and momentum zone checks.
     *
     * @param  array<string, array<string, mixed>|null>  $indicators
     */
    public function determineAction(array $indicators): string
    {
        $macro = $indicators['15m'] ?? [];
        $macroTrend = (string) ($macro['trend'] ?? 'sideways');
        $macroRsi = is_numeric($macro['rsi'] ?? null) ? (float) $macro['rsi'] : 50.0;

        if ($this->detectBreakOfStructure($indicators)) {
            if ($this->bullishTrend($macroTrend) && $macroRsi >= 45.0 && $macroRsi <= 72.0) {
                return 'BUY';
            }

            if ($this->bearishTrend($macroTrend) && $macroRsi >= 28.0 && $macroRsi <= 55.0) {
                return 'SELL';
            }
        }

        return 'HOLD';
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
        return 'momentum';
    }
}
