<?php

namespace App\Services\Trading;

/**
 * TimeframeSignalDTO
 *
 * Immutable summary of one timeframe used by the deterministic MTF engine.
 */
readonly class TimeframeSignalDTO
{
    /**
     * @param  string  $timeframe  Supported values: 5m|15m|30m|60m
     * @param  float  $rsi  RSI value for the timeframe.
     * @param  string  $trend  Normalized trend: UP|DOWN|NEUTRAL.
     * @param  int  $mcpScore  MCP score for observability (0 when MCP did not pass).
     * @param  string  $signalType  neutral|trend_follow|reversal.
     */
    public function __construct(
        public string $timeframe,
        public float $rsi,
        public string $trend,
        public int $mcpScore,
        public string $signalType,
    ) {}

    /**
     * @return array{timeframe: string, rsi: float, trend: string, mcp_score: int, signal_type: string}
     */
    public function toArray(): array
    {
        return [
            'timeframe' => $this->timeframe,
            'rsi' => $this->rsi,
            'trend' => $this->trend,
            'mcp_score' => $this->mcpScore,
            'signal_type' => $this->signalType,
        ];
    }
}
