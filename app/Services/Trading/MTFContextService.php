<?php

namespace App\Services\Trading;

/**
 * MTFContextService
 *
 * Converts deterministic MTF scoring output into contextual metadata for the
 * AI-first decision pipeline. This service does not decide action.
 */
class MTFContextService
{
    /**
     * Build soft MTF context from an MTF result.
     *
     * @return array{
     *   mtf_score: float,
     *   alignment: string,
     *   context_bias: string,
     *   mode: string,
     *   base_confidence: int,
     *   flags: array<int, string>,
     *   role_timeframes: array{trigger: string, setup: string, context: string, direction: string},
     *   timeframe_signals: array<string, array{timeframe: string, rsi: float, trend: string, mcp_score: int, signal_type: string}>
     * }
     */
    public function build(MTFResultDTO $mtfResult): array
    {
        $contextBias = $this->resolveContextBias($mtfResult->mtfScore);

        return [
            'mtf_score' => $mtfResult->mtfScore,
            'alignment' => $this->resolveAlignment($mtfResult->preliminaryAction, $contextBias),
            'context_bias' => $contextBias,
            'mode' => $mtfResult->mode,
            'base_confidence' => $mtfResult->baseConfidence,
            'flags' => array_values(array_unique($mtfResult->flags)),
            'role_timeframes' => $mtfResult->roleTimeframes,
            'timeframe_signals' => $mtfResult->timeframeSignals,
        ];
    }

    private function resolveContextBias(float $mtfScore): string
    {
        if ($mtfScore >= 1.0) {
            return 'bullish';
        }

        if ($mtfScore <= -1.0) {
            return 'bearish';
        }

        return 'neutral';
    }

    private function resolveAlignment(string $preliminaryAction, string $contextBias): string
    {
        $action = strtoupper(trim($preliminaryAction));

        if ($action === 'HOLD' || $contextBias === 'neutral') {
            return 'neutral';
        }

        if (($action === 'BUY' && $contextBias === 'bullish') || ($action === 'SELL' && $contextBias === 'bearish')) {
            return 'aligned';
        }

        return 'contradictory';
    }
}
