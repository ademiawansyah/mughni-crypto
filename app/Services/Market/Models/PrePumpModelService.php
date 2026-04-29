<?php

namespace App\Services\Market\Models;

use Illuminate\Support\Collection;

class PrePumpModelService extends AbstractMarketModelService
{
    /**
     * Evaluate one coin for a squeeze / pre-pump setup.
     *
     * @param  array<string, array<string, mixed>|null>|null  $indicators
     */
    public function evaluateCoin(string $coin, ?array $indicators = null): ?ModelSignalDTO
    {
        $resolvedIndicators = $indicators ?? $this->resolveIndicators($coin);
        $setup = $resolvedIndicators['1h'] ?? null;
        $macro = $resolvedIndicators['4h'] ?? $resolvedIndicators['1h'] ?? null;

        if ($setup === null || $macro === null) {
            return null;
        }

        $bitcoinIndicator = $this->fetchLatestIndicator('bitcoin', '1h');
        $componentScores = $this->calculateComponentScores($resolvedIndicators, $bitcoinIndicator);
        $action = $this->determineAction($componentScores, $resolvedIndicators, $bitcoinIndicator);

        if ($action === 'HOLD') {
            return null;
        }

        $weights = config('models.pre_pump.scoring', []);
        $baseScore = $this->calculateWeightedScore($componentScores, $weights);
        $marketRegime = $this->resolveMarketRegime();
        $regimeAdjuster = (int) config(sprintf('models.pre_pump.market_confidence_adjusters.%s', $marketRegime['market_regime'] ?? 'RANGING'), 0);
        $score = $this->clampInt($baseScore + $regimeAdjuster);
        $minimumScore = (int) config('models.pre_pump.min_score_short_tf', 50);

        if ($score < $minimumScore) {
            return null;
        }

        return new ModelSignalDTO(
            model: $this->modelKey(),
            coin: $coin,
            action: $action,
            score: $score,
            primaryTimeframe: '1h',
            componentScores: $componentScores,
            context: [
                'market_regime' => $marketRegime['market_regime'] ?? 'RANGING',
                'btc_direction' => $marketRegime['btc_direction'] ?? 'SIDEWAYS',
                'setup_trend' => $setup['trend'],
                'macro_trend' => $macro['trend'],
                'bitcoin_trend' => $bitcoinIndicator['trend'] ?? 'sideways',
            ],
            reasons: array_values(array_filter([
                ($componentScores['funding'] ?? 0.0) === 0.0 ? 'funding_data_unavailable' : null,
                ($componentScores['cvd'] ?? 0.0) === 0.0 ? 'cvd_data_unavailable' : null,
                ($componentScores['atr_compression'] ?? 0.0) >= 0.7 ? 'atr_compression_detected' : null,
                ($componentScores['oi'] ?? 0.0) >= 0.6 ? 'volume_expansion_proxy' : null,
                ($componentScores['rs'] ?? 0.0) >= 0.6 ? 'relative_strength_vs_btc' : null,
            ])),
        );
    }

    /**
     * Calculate normalized pre-pump component scores.
     *
     * @param  array<string, array<string, mixed>|null>  $indicators
     * @param  array<string, mixed>|null  $bitcoinIndicator
     * @return array<string, float>
     */
    public function calculateComponentScores(array $indicators, ?array $bitcoinIndicator = null): array
    {
        $setup = $indicators['1h'] ?? [];
        $macro = $indicators['4h'] ?? $indicators['1h'] ?? [];
        $setupTrend = (string) ($setup['trend'] ?? 'sideways');
        $setupRsi = is_numeric($setup['rsi'] ?? null) ? (float) $setup['rsi'] : 50.0;
        $volumeRatio = is_numeric($setup['volume_ratio'] ?? null) ? (float) $setup['volume_ratio'] : 0.0;
        $volatility = is_numeric($macro['volatility'] ?? null) ? (float) $macro['volatility'] : null;
        $setupOpenInterest = is_numeric($setup['open_interest'] ?? null) ? (float) $setup['open_interest'] : null;
        $macroOpenInterest = is_numeric($macro['open_interest'] ?? null) ? (float) $macro['open_interest'] : null;
        $fundingRate = is_numeric($setup['funding_rate'] ?? null) ? (float) $setup['funding_rate'] : null;
        $cvdSlope = is_numeric($setup['cvd_slope'] ?? null) ? (float) $setup['cvd_slope'] : null;
        $bitcoinTrend = (string) ($bitcoinIndicator['trend'] ?? 'sideways');
        $bitcoinRsi = is_numeric($bitcoinIndicator['rsi'] ?? null) ? (float) $bitcoinIndicator['rsi'] : 50.0;

        $atrCompression = 0.0;
        if ($volatility !== null) {
            if ($volatility <= 0.02) {
                $atrCompression = 1.0;
            } elseif ($volatility <= 0.04) {
                $atrCompression = 0.75;
            } else {
                $atrCompression = 0.2;
            }
        }

        $relativeStrength = 0.4;
        if ($this->bullishTrend($setupTrend) && ! $this->bullishTrend($bitcoinTrend)) {
            $relativeStrength = 1.0;
        } elseif ($this->bullishTrend($setupTrend) && $setupRsi > $bitcoinRsi) {
            $relativeStrength = 0.75;
        } elseif ($this->bearishTrend($setupTrend) && ! $this->bearishTrend($bitcoinTrend)) {
            $relativeStrength = 0.7;
        }

        $fundingScore = 0.0;
        if ($fundingRate !== null) {
            if ($fundingRate <= -0.0005) {
                $fundingScore = 1.0;
            } elseif ($fundingRate <= -0.0002) {
                $fundingScore = 0.6;
            } elseif ($fundingRate < 0.0) {
                $fundingScore = 0.3;
            }
        }

        $oiScore = 0.0;
        if ($setupOpenInterest !== null && $macroOpenInterest !== null && $macroOpenInterest > 0.0) {
            $oiExpansion = ($setupOpenInterest - $macroOpenInterest) / $macroOpenInterest;
            $oiScore = $oiExpansion >= 0.05 ? 1.0 : ($oiExpansion >= 0.02 ? 0.7 : 0.2);
        } elseif ($volumeRatio >= 1.5) {
            $oiScore = 0.5;
        }

        $cvdScore = 0.0;
        if ($cvdSlope !== null) {
            $cvdScore = $cvdSlope > 0.0 ? 1.0 : 0.1;
        }

        return [
            'funding' => $fundingScore,
            'atr_compression' => $atrCompression,
            'oi' => $oiScore,
            'rs' => $relativeStrength,
            'cvd' => $cvdScore,
        ];
    }

    /**
     * Resolve the model action from the available pre-pump proxies.
     *
     * @param  array<string, float>  $componentScores
     * @param  array<string, array<string, mixed>|null>  $indicators
     * @param  array<string, mixed>|null  $bitcoinIndicator
     */
    public function determineAction(array $componentScores, array $indicators, ?array $bitcoinIndicator = null): string
    {
        $setupTrend = (string) ($indicators['1h']['trend'] ?? 'sideways');

        if (
            ($componentScores['atr_compression'] ?? 0.0) >= 0.7
            && (
                ($componentScores['oi'] ?? 0.0) >= 0.6
                || ($componentScores['rs'] ?? 0.0) >= 0.6
            )
        ) {
            if ($this->bullishTrend($setupTrend)) {
                return 'BUY';
            }

            if ($this->bearishTrend($setupTrend)) {
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
        return 'pre_pump';
    }
}
