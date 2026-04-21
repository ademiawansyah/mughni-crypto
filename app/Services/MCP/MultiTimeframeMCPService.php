<?php

namespace App\Services\MCP;

use App\Enums\ActionCandidate;
use App\Enums\MarketTrend;
use Illuminate\Support\Facades\Log;

/**
 * MultiTimeframeMCPService — Multi-Timeframe Signal Confirmation
 *
 * Combines two independent MCPService evaluations — 5m (entry trigger) and 15m
 * (confirmation filter) — to produce a higher-quality, lower-noise trading signal.
 *
 * This service does NOT modify MCPService logic. It only combines existing results.
 *
 * Pipeline:
 *   1. Require 5m.passed_mcp == true as a hard entry gate.
 *   2. Classify the 15m timeframe as CONFIRMED, NEUTRAL, or CONTRADICTION.
 *   3. Reject signals when 15m is a CONTRADICTION.
 *   4. Classify overall MTF signal strength (strong / moderate / weak).
 *   5. Return an MtfResult with the final decision and structured payload.
 *
 * --- 15m States ---
 *
 *   CONFIRMED    — 15m.passed_mcp == true → strong confirmation
 *   NEUTRAL      — 15m.passed_mcp == false BUT score >= 2 → weak but acceptable
 *   CONTRADICTION — 5m signal direction opposes 15m trend AND 15m RSI is not
 *                   in the extreme zone → reject
 *
 * --- Signal Strength ---
 *
 *   strong   — 5m.passed_mcp AND 15m.passed_mcp both true
 *   moderate — 5m.passed_mcp true AND 15m.score >= 2
 *   weak     — 5m.passed_mcp true AND 5m.score == 3 AND 15m is neutral
 *
 * This service does NOT call the AI and does NOT persist anything.
 */
class MultiTimeframeMCPService
{
    /**
     * Minimum 15m score for the NEUTRAL state (weak confirmation).
     */
    private const NEUTRAL_MIN_SCORE = 2;

    /**
     * 5m score at or below which a NEUTRAL 15m yields a "weak" signal strength.
     */
    private const WEAK_ENTRY_SCORE = 3;

    /**
     * RSI below this value is considered strongly oversold — qualifies BUY reversal
     * even when 15m trend is DOWN (prevents false CONTRADICTION classification).
     */
    private const RSI_STRONG_BUY = 30;

    /**
     * RSI above this value is considered strongly overbought — qualifies SELL reversal
     * even when 15m trend is UP (prevents false CONTRADICTION classification).
     */
    private const RSI_STRONG_SELL = 70;

    /**
     * Evaluate a symbol by combining 5m and 15m MCP results.
     *
     * Returns null when 5m did not pass (entry gate) or when 15m is a CONTRADICTION.
     * Returns an MtfResult for all other outcomes (confirmed or neutral).
     *
     * @param  string  $symbol  CoinGecko coin ID (e.g. 'bitcoin')
     * @param  McpResult|null  $result5m  Output from MCPService for the 5m timeframe
     * @param  McpResult|null  $result15m  Output from MCPService for the 15m timeframe
     * @return MtfResult|null Structured multi-timeframe decision, or null to skip
     */
    public function evaluate(string $symbol, ?McpResult $result5m, ?McpResult $result15m): ?MtfResult
    {
        // --- Hard gate: 5m must pass MCP ---
        if ($result5m === null) {
            $this->logEvaluation($symbol, false, false, 0, 0, null, false, 'rejected_5m_failed');

            return null;
        }

        $confirmationState = $this->classify15m($result5m, $result15m);

        if ($confirmationState === 'contradiction') {
            $this->logEvaluation(
                symbol: $symbol,
                mcp5mPassed: true,
                mcp15mPassed: $result15m !== null,
                score5m: $result5m->score,
                score15m: $result15m?->score ?? 0,
                mtfSignalStrength: null,
                finalDecision: false,
                reason: 'contradiction',
            );

            return null;
        }

        $strength = $this->classifyStrength($result5m, $result15m, $confirmationState);

        $this->logEvaluation(
            symbol: $symbol,
            mcp5mPassed: true,
            mcp15mPassed: $result15m !== null,
            score5m: $result5m->score,
            score15m: $result15m?->score ?? 0,
            mtfSignalStrength: $strength,
            finalDecision: true,
            reason: $confirmationState,
        );

        return new MtfResult(
            symbol: $symbol,
            actionCandidate: $result5m->actionCandidate,
            mtfSignalStrength: $strength,
            shouldSendToAi: true,
            reason: $confirmationState,
            data5m: $result5m->toArray(),
            data15m: $result15m?->toArray() ?? [],
        );
    }

    /**
     * Classify the 15m timeframe as confirmed, neutral, or contradiction.
     *
     * --- CONFIRMED:
     *   15m result exists and passed MCP.
     *
     * --- NEUTRAL:
     *   15m result exists, did not pass MCP, but score >= NEUTRAL_MIN_SCORE.
     *   OR 15m result is null (no data) — treated as neutral, not a hard reject.
     *
     * --- CONTRADICTION:
     *   15m trend strongly opposes the 5m signal direction AND the 15m RSI is
     *   not in the extreme zone (which would justify a reversal).
     *
     *   Specifically:
     *     5m = BUY  + 15m trend = DOWN + 15m RSI NOT < RSI_STRONG_BUY  → contradiction
     *     5m = SELL + 15m trend = UP   + 15m RSI NOT > RSI_STRONG_SELL → contradiction
     *
     * @param  McpResult  $result5m  Passing 5m result (non-null guaranteed by caller)
     * @param  McpResult|null  $result15m  15m result (may be null when data is unavailable)
     * @return string confirmed | neutral | contradiction
     */
    private function classify15m(McpResult $result5m, ?McpResult $result15m): string
    {
        // No 15m data — treat as neutral (do not hard-reject)
        if ($result15m === null) {
            return 'neutral';
        }

        // CONFIRMED: 15m passed MCP
        if ($result15m->score >= 1) { // passed_mcp is implied by a non-null McpResult
            return 'confirmed';
        }

        // Check for CONTRADICTION before allowing NEUTRAL
        if ($this->isContradiction($result5m, $result15m)) {
            return 'contradiction';
        }

        // NEUTRAL: failed MCP but score indicates some activity (weak confirmation)
        if ($result15m->score >= self::NEUTRAL_MIN_SCORE) {
            return 'neutral';
        }

        // 15m score is too low and not a contradiction — still treat as neutral
        // (a very weak 15m does not constitute a structural opposition)
        return 'neutral';
    }

    /**
     * Determine whether the 15m result structurally contradicts the 5m signal.
     *
     * A contradiction requires BOTH:
     *   (a) The 15m EMA trend is directly opposed to the 5m signal direction.
     *   (b) The 15m RSI is NOT in the extreme zone that would justify a reversal.
     *
     * Example:
     *   5m = BUY, 15m trend = DOWN, 15m RSI = 55 (not oversold) → CONTRADICTION
     *   5m = BUY, 15m trend = DOWN, 15m RSI = 25 (strongly oversold) → NOT contradiction (reversal possible)
     *
     * @param  McpResult  $result5m  5m evaluation (non-null)
     * @param  McpResult  $result15m  15m evaluation (non-null)
     */
    private function isContradiction(McpResult $result5m, McpResult $result15m): bool
    {
        $candidate = $result5m->actionCandidate;
        $trend15m = $result15m->trend;
        $rsi15m = $result15m->rsi;

        if ($candidate === ActionCandidate::Buy) {
            $trendOpposes = $trend15m === MarketTrend::Down;
            $rsiNotExtreme = $rsi15m >= self::RSI_STRONG_BUY;

            return $trendOpposes && $rsiNotExtreme;
        }

        // ActionCandidate::Sell
        $trendOpposes = $trend15m === MarketTrend::Up;
        $rsiNotExtreme = $rsi15m <= self::RSI_STRONG_SELL;

        return $trendOpposes && $rsiNotExtreme;
    }

    /**
     * Classify the overall MTF signal strength based on both timeframe results.
     *
     * Rules (evaluated in priority order):
     *
     *   strong   — 5m passed AND 15m passed MCP (confirmationState == confirmed)
     *   moderate — 5m passed AND 15m score >= NEUTRAL_MIN_SCORE
     *   weak     — 5m passed AND 5m.score == WEAK_ENTRY_SCORE AND 15m is neutral
     *
     * When the 15m result is null, strength degrades to "weak" regardless of 5m score.
     *
     * @param  McpResult  $result5m  Passing 5m result (non-null)
     * @param  McpResult|null  $result15m  15m result
     * @param  string  $confirmationState  confirmed | neutral (contradiction is never passed here)
     */
    private function classifyStrength(McpResult $result5m, ?McpResult $result15m, string $confirmationState): string
    {
        if ($confirmationState === 'confirmed') {
            return 'strong';
        }

        if ($result15m !== null && $result15m->score >= self::NEUTRAL_MIN_SCORE) {
            return 'moderate';
        }

        // 5m barely passes (score == WEAK_ENTRY_SCORE) with no meaningful 15m confirmation
        if ($result5m->score <= self::WEAK_ENTRY_SCORE) {
            return 'weak';
        }

        // 5m has a solid score but 15m is absent or score < NEUTRAL_MIN_SCORE — moderate
        return 'moderate';
    }

    /**
     * Write a structured log entry for every multi-timeframe evaluation.
     *
     * Logged regardless of outcome so thresholds can be tuned by inspecting logs.
     * Fields emitted:
     *   symbol, mcp_5m_passed, mcp_15m_passed, score_5m, score_15m,
     *   mtf_signal_strength, final_decision, reason
     *
     * mtf_signal_strength — null when the signal was rejected before classification.
     * reason — confirmed | neutral | contradiction | rejected_5m_failed.
     *
     * @param  string  $symbol  CoinGecko coin ID
     * @param  bool  $mcp5mPassed  Whether the 5m evaluation passed MCP
     * @param  bool  $mcp15mPassed  Whether the 15m evaluation passed MCP (non-null)
     * @param  int  $score5m  Score from the 5m evaluation
     * @param  int  $score15m  Score from the 15m evaluation (0 when null)
     * @param  string|null  $mtfSignalStrength  strong | moderate | weak | null
     * @param  bool  $finalDecision  True when the symbol is forwarded to AI
     * @param  string  $reason  confirmed | neutral | contradiction | rejected_5m_failed
     */
    private function logEvaluation(
        string $symbol,
        bool $mcp5mPassed,
        bool $mcp15mPassed,
        int $score5m,
        int $score15m,
        ?string $mtfSignalStrength,
        bool $finalDecision,
        string $reason,
    ): void {
        Log::info('[MultiTimeframeMCPService] Evaluation', [
            'symbol' => $symbol,
            'mcp_5m_passed' => $mcp5mPassed,
            'mcp_15m_passed' => $mcp15mPassed,
            'score_5m' => $score5m,
            'score_15m' => $score15m,
            'mtf_signal_strength' => $mtfSignalStrength, // null when rejected early
            'final_decision' => $finalDecision,
            'reason' => $reason,
        ]);
    }
}
