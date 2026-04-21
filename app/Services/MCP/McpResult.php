<?php

namespace App\Services\MCP;

use App\Enums\ActionCandidate;
use App\Enums\MarketTrend;

/**
 * McpResult
 *
 * Immutable value object returned by MCPService when a coin passes all pre-filter rules.
 *
 * Contains the structured payload that will be forwarded to the AI service,
 * along with scoring metadata used for logging and future threshold tuning.
 */
readonly class McpResult
{
    /**
     * @param  string  $symbol  CoinGecko coin ID (e.g. 'bitcoin')
     * @param  string  $timeframe  Timeframe label (e.g. '5m', '15m')
     * @param  ActionCandidate  $actionCandidate  Candidate trade direction
     * @param  int  $score  Cumulative score (must be >= 4 to pass)
     * @param  MarketTrend  $trend  Derived market trend
     * @param  float  $rsi  Latest RSI value
     * @param  string  $emaTrend  Human-readable EMA alignment label
     * @param  float  $volumeRatio  volume / avg_volume ratio
     * @param  float  $currentPrice  Latest market price
     */
    public function __construct(
        public string $symbol,
        public string $timeframe,
        public ActionCandidate $actionCandidate,
        public int $score,
        public MarketTrend $trend,
        public float $rsi,
        public string $emaTrend,
        public float $volumeRatio,
        public float $currentPrice,
    ) {}

    /**
     * Serialize to the structured array forwarded to the AI service.
     *
     * @return array{
     *   symbol: string,
     *   timeframe: string,
     *   action_candidate: string,
     *   score: int,
     *   market_context: array{trend: string},
     *   indicators: array{rsi: float, ema_trend: string, volume_ratio: float},
     *   price: array{current: float},
     * }
     */
    public function toArray(): array
    {
        return [
            'symbol' => $this->symbol,
            'timeframe' => $this->timeframe,
            'action_candidate' => $this->actionCandidate->value,
            'score' => $this->score,
            'market_context' => [
                'trend' => $this->trend->value,
            ],
            'indicators' => [
                'rsi' => $this->rsi,
                'ema_trend' => $this->emaTrend,
                'volume_ratio' => $this->volumeRatio,
            ],
            'price' => [
                'current' => $this->currentPrice,
            ],
        ];
    }
}
