<?php

namespace App\Services\Market;

use App\Models\GeneralConfig;
use App\Models\MarketIndicator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * MarketRegimeService — Global Market Context Detection
 *
 * Analyzes BTC market structure across multiple timeframes (1H, 4H, 1D) to detect
 * the overall market regime and volatility environment. This global context is
 * independent of any individual coin and is used by all trading models for
 * signal refinement.
 *
 * Output (Redis key: market_context:latest, TTL: 300s):
 * {
 *   market_regime: "TRENDING_UP|TRENDING_DOWN|RANGING|CHOPPY",
 *   btc_direction: "UP|DOWN|SIDEWAYS",
 *   volatility: "LOW|MEDIUM|HIGH",
 *   market_strength: "WEAK|MODERATE|STRONG",
 *   risk_level: "LOW|MEDIUM|HIGH",
 *   btc_structure: {
 *     higher_highs: bool,
 *     higher_lows: bool,
 *     ema_slope_positive: bool
 *   }
 * }
 */
class MarketRegimeService
{
    /**
     * Redis cache key for market context
     */
    private const CACHE_KEY = 'market_context:latest';

    /**
     * Cache TTL in seconds (5 minutes)
     */
    private const CACHE_TTL = 300;

    /**
     * Fetch and analyze BTC market structure to determine overall regime.
     *
     * Process:
     * 1. Fetch BTC OHLCV data for 1H, 4H, 1D timeframes
     * 2. Calculate EMA slopes and structure (HH/HL)
     * 3. Determine ATR volatility classification
     * 4. Classify market regime based on EMA slope + structure
     * 5. Assign risk level based on volatility + consistency
     * 6. Cache result and return
     *
     * @param  string  $executionId  Pipeline execution identifier for traceability
     * @return array Structured market regime context (see class docblock)
     */
    public function detectRegime(string $executionId = ''): array
    {
        $btcIndicators = $this->fetchBtcIndicators();

        if (empty($btcIndicators)) {
            Log::warning('[MarketRegimeService] No BTC indicators available', [
                'execution_id' => $executionId,
            ]);

            return $this->getDefaultRegime();
        }

        $regime = $this->analyzeStructure($btcIndicators);

        // Cache the result
        Cache::put(self::CACHE_KEY, $regime, self::CACHE_TTL);

        Log::info('[MarketRegimeService] Market regime detected', [
            'execution_id' => $executionId,
            'regime' => $regime['market_regime'],
            'volatility' => $regime['volatility'],
            'risk_level' => $regime['risk_level'],
        ]);

        return $regime;
    }

    /**
     * Get the currently cached market regime context.
     *
     * Returns cached value if available; otherwise returns default regime.
     *
     * @return array Market regime context
     */
    public function getLatestRegime(): array
    {
        return Cache::get(self::CACHE_KEY, $this->getDefaultRegime());
    }

    /**
     * Fetch latest BTC MarketIndicator records for key timeframes.
     *
     * @return array<string, MarketIndicator|null> Keyed by timeframe (1h, 4h, 1d)
     */
    private function fetchBtcIndicators(): array
    {
        $timeframes = GeneralConfig::getTimeframes();
        $indicators = [];

        foreach ($timeframes as $tf) {
            $indicator = MarketIndicator::query()
                ->where('coin', 'bitcoin')
                ->where('timeframe', $tf)
                ->orderByDesc('timestamp')
                ->first();

            $indicators[$tf] = $indicator;
        }

        return $indicators;
    }

    /**
     * Analyze BTC structure across timeframes to determine market regime.
     *
     * @param  array<string, MarketIndicator|null>  $btcIndicators
     * @return array Market regime context
     */
    private function analyzeStructure(array $btcIndicators): array
    {
        $h1 = $btcIndicators['1h'];
        $h4 = $btcIndicators['4h'];
        $d1 = $btcIndicators['1d'];

        // Get configuration thresholds
        $config = config('models.market_regime', []);
        $emaShort = $config['ema_short'] ?? 9;
        $emaLong = $config['ema_long'] ?? 21;
        $atrPeriod = $config['atr_period'] ?? 14;

        // Determine BTC direction from 1H EMA
        $btcDirection = $this->determineBtcDirection($h1);

        // Detect structure (HH/HL) from 4H/1D data
        $structure = $this->detectStructure($h4, $d1);

        // Calculate volatility
        $volatility = $this->classifyVolatility($d1, $atrPeriod);

        // Determine market regime based on direction + structure
        $regime = $this->classifyRegime($btcDirection, $structure, $volatility);

        // Calculate market strength
        $strength = $this->assessMarketStrength($btcDirection, $structure, $volatility);

        // Assign risk level
        $riskLevel = $this->assignRiskLevel($volatility, $regime, $strength);

        return [
            'market_regime' => $regime,
            'btc_direction' => $btcDirection,
            'volatility' => $volatility,
            'market_strength' => $strength,
            'risk_level' => $riskLevel,
            'btc_structure' => [
                'higher_highs' => $structure['higher_highs'],
                'higher_lows' => $structure['higher_lows'],
                'ema_slope_positive' => $structure['ema_slope_positive'],
            ],
        ];
    }

    /**
     * Determine BTC direction from 1H data.
     *
     * @return string "UP"|"DOWN"|"SIDEWAYS"
     */
    private function determineBtcDirection(?MarketIndicator $h1): string
    {
        if ($h1 === null || $h1->ema9 === null || $h1->ema21 === null) {
            return 'SIDEWAYS';
        }

        $ema9 = (float) $h1->ema9;
        $ema21 = (float) $h1->ema21;

        if ($ema9 > $ema21 * 1.002) {  // 0.2% threshold
            return 'UP';
        }

        if ($ema9 < $ema21 * 0.998) {  // -0.2% threshold
            return 'DOWN';
        }

        return 'SIDEWAYS';
    }

    /**
     * Detect structure patterns from 4H and 1D data.
     *
     * @return array{higher_highs: bool, higher_lows: bool, ema_slope_positive: bool}
     */
    private function detectStructure(?MarketIndicator $h4, ?MarketIndicator $d1): array
    {
        $higherHighs = false;
        $higherLows = false;
        $emaSlopePositive = false;

        if ($d1 !== null && $d1->ema9 !== null && $d1->ema21 !== null) {
            $ema9 = (float) $d1->ema9;
            $ema21 = (float) $d1->ema21;
            $emaSlopePositive = $ema9 >= $ema21;
        }

        // Structure detection would require historical data (previous candles)
        // For now, we check current EMA positioning as a proxy
        if ($h4 !== null && $h4->ema9 !== null && $h4->ema21 !== null) {
            $ema9 = (float) $h4->ema9;
            $ema21 = (float) $h4->ema21;
            $higherHighs = $ema9 > $ema21;
            $higherLows = $ema9 > $ema21;  // Simplified: both based on EMA position
        }

        return [
            'higher_highs' => $higherHighs,
            'higher_lows' => $higherLows,
            'ema_slope_positive' => $emaSlopePositive,
        ];
    }

    /**
     * Classify volatility as LOW|MEDIUM|HIGH based on ATR.
     *
     * Thresholds from config:
     * - LOW: current_atr < 0.8 * baseline_atr
     * - MEDIUM: 0.8 <= ratio <= 1.5
     * - HIGH: > 1.5 * baseline_atr
     *
     * @param  MarketIndicator|null  $d1  Daily data for ATR
     * @return string "LOW"|"MEDIUM"|"HIGH"
     */
    private function classifyVolatility(?MarketIndicator $d1, int $atrPeriod): string
    {
        if ($d1 === null || $d1->atr === null) {
            return 'MEDIUM';
        }

        $currentAtr = (float) $d1->atr;

        // Use a baseline ATR (could be stored in DB or config)
        // For now, approximate baseline as average of current + historical
        $baselineAtr = config('models.market_regime.baseline_atr', 200.0);

        $ratio = $currentAtr / $baselineAtr;

        if ($ratio < 0.8) {
            return 'LOW';
        }

        if ($ratio <= 1.5) {
            return 'MEDIUM';
        }

        return 'HIGH';
    }

    /**
     * Classify market regime based on BTC direction + structure + volatility.
     *
     * @param  array{higher_highs: bool, higher_lows: bool, ema_slope_positive: bool}  $structure
     * @return string "TRENDING_UP"|"TRENDING_DOWN"|"RANGING"|"CHOPPY"
     */
    private function classifyRegime(string $btcDirection, array $structure, string $volatility): string
    {
        // TRENDING_UP: BTC UP + structure consistent + not high volatility
        if ($btcDirection === 'UP' && $structure['higher_highs'] && $structure['higher_lows']) {
            return $volatility === 'HIGH' ? 'CHOPPY' : 'TRENDING_UP';
        }

        // TRENDING_DOWN: BTC DOWN + lower highs/lows
        if ($btcDirection === 'DOWN' && ! $structure['higher_highs'] && ! $structure['higher_lows']) {
            return $volatility === 'HIGH' ? 'CHOPPY' : 'TRENDING_DOWN';
        }

        // RANGING: SIDEWAYS + low/medium volatility
        if ($btcDirection === 'SIDEWAYS' && $volatility !== 'HIGH') {
            return 'RANGING';
        }

        // CHOPPY: High volatility regardless of direction
        if ($volatility === 'HIGH') {
            return 'CHOPPY';
        }

        // Default to RANGING
        return 'RANGING';
    }

    /**
     * Assess market strength: WEAK|MODERATE|STRONG
     *
     * @param  array{higher_highs: bool, higher_lows: bool, ema_slope_positive: bool}  $structure
     */
    private function assessMarketStrength(string $btcDirection, array $structure, string $volatility): string
    {
        $score = 0;

        // Direction aligned
        if ($btcDirection !== 'SIDEWAYS') {
            $score += 1;
        }

        // Structure consistent
        if ($structure['higher_highs'] && $structure['higher_lows']) {
            $score += 1;
        }

        // EMA slope positive
        if ($structure['ema_slope_positive']) {
            $score += 1;
        }

        // Low volatility is positive for strength
        if ($volatility === 'LOW') {
            $score += 1;
        }

        // Classify
        if ($score >= 3) {
            return 'STRONG';
        }

        if ($score >= 1) {
            return 'MODERATE';
        }

        return 'WEAK';
    }

    /**
     * Assign risk level: LOW|MEDIUM|HIGH
     */
    private function assignRiskLevel(string $volatility, string $regime, string $strength): string
    {
        // High volatility + choppy regime = HIGH risk
        if ($volatility === 'HIGH' || $regime === 'CHOPPY') {
            return 'HIGH';
        }

        // Low strength + low volatility = LOW risk
        if ($strength === 'WEAK' && $volatility === 'LOW') {
            return 'LOW';
        }

        // Strong trends + medium volatility = LOW risk
        if ($strength === 'STRONG' && $volatility === 'MEDIUM') {
            return 'LOW';
        }

        // Everything else = MEDIUM
        return 'MEDIUM';
    }

    /**
     * Get default/fallback regime when data is unavailable.
     */
    private function getDefaultRegime(): array
    {
        return [
            'market_regime' => 'RANGING',
            'btc_direction' => 'SIDEWAYS',
            'volatility' => 'MEDIUM',
            'market_strength' => 'MODERATE',
            'risk_level' => 'MEDIUM',
            'btc_structure' => [
                'higher_highs' => false,
                'higher_lows' => false,
                'ema_slope_positive' => false,
            ],
        ];
    }
}
