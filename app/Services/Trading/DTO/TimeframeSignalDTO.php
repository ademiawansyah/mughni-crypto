<?php

namespace App\Services\Trading\DTO;

/**
 * TimeframeSignalDTO
 *
 * Immutable MCP result shape per timeframe for MTF aggregation.
 */
readonly class TimeframeSignalDTO
{
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
