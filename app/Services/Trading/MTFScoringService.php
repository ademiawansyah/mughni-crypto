<?php

namespace App\Services\Trading;

use App\Services\MCP\McpResult;
use Illuminate\Support\Facades\Log;

/**
 * MTFScoringService — Multi-Timeframe Scoring Engine
 *
 * Aggregates per-timeframe MCP signals into a single deterministic weighted score
 * and derives a preliminary trading action with base confidence.
 *
 * Algorithm (per the PART 1 specification):
 *   1. Normalize direction from action candidate: BUY → +1, SELL → -1, other → 0.
 *   2. Normalize MCP score to strength: 0→0, 1→0.25, 2→0.5, 3→0.75, 4+→1.0.
 *   3. Derive signal_type from action vs trend: trend_follow→1.0, reversal→1.2, neutral→0.5.
 *   4. Apply configured timeframe weight from config('trading.timeframe_weights').
 *   5. tf_score = direction × strength × multiplier × weight.
 *   6. mtf_score = sum of all tf_scores.
 *   7. preliminary_action: ≥ +2 → BUY, ≤ -2 → SELL, else → HOLD.
 *   8. base_confidence = min(85, 50 + abs(mtf_score) × 10).
 *
 * Null McpResult entries (timeframe did not pass MCP) are skipped silently and
 * contribute zero to the aggregate — they do not block scoring.
 *
 * This service is stateless and does not call external APIs or persist anything.
 */
class MTFScoringService
{
    /**
     * Aggregate per-timeframe MCP results into an MTFResultDTO.
     *
     * @param  array<string, McpResult|null>  $results  Keyed by timeframe label (e.g. '5m', '15m').
     * @param  string  $executionId  Pipeline traceability ID.
     */
    public function score(array $results, string $executionId = ''): MTFResultDTO
    {
        $weights = (array) config('trading.timeframe_weights', []);
        $mtfScore = 0.0;
        $breakdown = [];

        foreach ($results as $timeframe => $mcpResult) {
            if ($mcpResult === null) {
                $breakdown[$timeframe] = ['skipped' => true, 'tf_score' => 0.0];

                continue;
            }

            $weight = (float) ($weights[$timeframe] ?? 1.0);
            $direction = $this->normalizeDirection($mcpResult);
            $strength = $this->normalizeStrength($mcpResult->score);
            $multiplier = $this->signalMultiplier($mcpResult);
            $tfScore = $direction * $strength * $multiplier * $weight;

            $mtfScore += $tfScore;

            $breakdown[$timeframe] = [
                'direction' => $direction,
                'strength' => $strength,
                'multiplier' => $multiplier,
                'weight' => $weight,
                'tf_score' => round($tfScore, 4),
            ];
        }

        $mtfScore = round($mtfScore, 4);
        $preliminaryAction = $this->derivePreliminaryAction($mtfScore);
        $baseConfidence = $this->deriveBaseConfidence($mtfScore);

        Log::info('[MTFScoringService] MTF score computed', [
            'execution_id' => $executionId,
            'mtf_score' => $mtfScore,
            'preliminary_action' => $preliminaryAction,
            'base_confidence' => $baseConfidence,
            'breakdown' => $breakdown,
        ]);

        return new MTFResultDTO(
            mtfScore: $mtfScore,
            preliminaryAction: $preliminaryAction,
            baseConfidence: $baseConfidence,
        );
    }

    /**
     * Build a human-readable timeframe summary for prompt injection.
     *
     * @param  array<string, McpResult|null>  $results
     * @return string e.g. "5m:BUY(score=3,trend=UP) 15m:skipped"
     */
    public function buildTimeframeSummary(array $results): string
    {
        $parts = [];

        foreach ($results as $timeframe => $mcpResult) {
            if ($mcpResult === null) {
                $parts[] = "{$timeframe}:skipped";
            } else {
                $action = $mcpResult->actionCandidate->value;
                $score = $mcpResult->score;
                $trend = $mcpResult->trend->value;
                $parts[] = "{$timeframe}:{$action}(score={$score},trend={$trend})";
            }
        }

        return implode(' ', $parts);
    }

    /**
     * Normalize action candidate to a directional integer.
     *
     * BUY → +1 (bullish signal), SELL → -1 (bearish signal), other → 0.
     */
    private function normalizeDirection(McpResult $result): int
    {
        return match ($result->actionCandidate->value) {
            'BUY' => 1,
            'SELL' => -1,
            default => 0,
        };
    }

    /**
     * Normalize MCP score to a 0–1 strength value.
     *
     * 0→0.0, 1→0.25, 2→0.5, 3→0.75, 4+→1.0
     */
    private function normalizeStrength(int $score): float
    {
        return match (true) {
            $score <= 0 => 0.0,
            $score === 1 => 0.25,
            $score === 2 => 0.5,
            $score === 3 => 0.75,
            default => 1.0,
        };
    }

    /**
     * Return signal multiplier based on derived signal type.
     *
     * trend_follow → 1.0, reversal → 1.2, neutral → 0.5
     */
    private function signalMultiplier(McpResult $result): float
    {
        return match ($this->deriveSignalType($result)) {
            'trend_follow' => 1.0,
            'reversal' => 1.2,
            default => 0.5,
        };
    }

    /**
     * Derive signal type from action candidate vs market trend.
     *
     * - BUY in UP trend or SELL in DOWN trend → trend_follow
     * - BUY in DOWN trend or SELL in UP trend → reversal
     * - All other combinations (e.g. SIDEWAYS trend) → neutral
     */
    private function deriveSignalType(McpResult $result): string
    {
        $isBuy = $result->actionCandidate->value === 'BUY';
        $isSell = $result->actionCandidate->value === 'SELL';
        $isUp = $result->trend->value === 'UP';
        $isDown = $result->trend->value === 'DOWN';

        if (($isBuy && $isUp) || ($isSell && $isDown)) {
            return 'trend_follow';
        }

        if (($isBuy && $isDown) || ($isSell && $isUp)) {
            return 'reversal';
        }

        return 'neutral';
    }

    /**
     * Map aggregate MTF score to a preliminary action string.
     */
    private function derivePreliminaryAction(float $mtfScore): string
    {
        $buyThreshold = (float) config('trading.mtf_buy_threshold', 2.0);
        $sellThreshold = (float) config('trading.mtf_sell_threshold', -2.0);

        if ($mtfScore >= $buyThreshold) {
            return 'BUY';
        }

        if ($mtfScore <= $sellThreshold) {
            return 'SELL';
        }

        return 'HOLD';
    }

    /**
     * Derive base confidence from absolute MTF score, capped at 85.
     */
    private function deriveBaseConfidence(float $mtfScore): int
    {
        return (int) min(85, 50 + (abs($mtfScore) * 10));
    }
}
