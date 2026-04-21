<?php

namespace App\Services\MCP;

use App\Enums\ActionCandidate;
use App\Enums\MarketTrend;
use App\Models\MarketIndicator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * MCPService — Market Context & Pre-filter
 *
 * Evaluates the latest market indicators for a coin/timeframe pair and decides
 * whether the data is worth sending to the AI service. This avoids unnecessary
 * LLM calls for coins that do not meet minimum signal quality thresholds.
 *
 * Pipeline:
 *   1. Resolve latest MarketIndicator for the given coin + timeframe.
 *   2. Validate required fields (rsi, ema9, ema21); skip with "missing_data" if absent.
 *   3. Determine market trend from EMA9 vs EMA21 alignment.
 *   4. Apply RSI-only pre-filter to identify BUY/SELL candidate; skip with "no_signal" if absent.
 *   5. Score the candidate (RSI extremity, trend alignment, reversal boost, volume boost).
 *   6. Enforce a minimum score threshold; skip with "low_score" if below.
 *   7. Enforce a 30-minute cooldown per symbol; skip with "cooldown" if active.
 *      Cooldown can be overridden when the signal is still strong (RSI extreme) or
 *      the score has not degraded since the last recorded signal.
 *   8. Return a structured McpResult, or null if the coin should be skipped.
 *
 * Volume is OPTIONAL: a missing or zero volume ratio does not block a signal;
 * it only contributes a +1 bonus when a spike is confirmed.
 *
 * Reversal logic: strong RSI extremes (< 30 BUY, > 70 SELL) in a counter-trend
 * context receive a +2 reversal boost, making them eligible to pass even without
 * trend alignment. Weak counter-trend entries (early RSI zone) are not boosted.
 *
 * This service does NOT call the AI and does NOT persist anything.
 */
class MCPService
{
    /**
     * Minimum cumulative score required to forward data to the AI service.
     * Lowered to 3 to allow early-entry signals for micro-profit (1–2%) strategies.
     */
    private const MIN_SCORE = 3;

    /**
     * RSI threshold below which a BUY candidate is identified.
     * Relaxed to 40 to capture early oversold entries.
     */
    private const RSI_BUY_THRESHOLD = 40;

    /**
     * RSI threshold above which a SELL candidate is identified.
     * Relaxed to 60 to capture early overbought entries.
     */
    private const RSI_SELL_THRESHOLD = 60;

    /**
     * RSI below this value is considered a strong oversold extreme (BUY).
     */
    private const RSI_STRONG_BUY = 30;

    /**
     * RSI above this value is considered a strong overbought extreme (SELL).
     */
    private const RSI_STRONG_SELL = 70;

    /**
     * Volume-to-average ratio that qualifies as a spike and grants a score bonus.
     * Volume is never a hard gate — only used for scoring when data is available.
     */
    private const VOLUME_SPIKE_RATIO = 1.5;

    /**
     * Cooldown duration in seconds between signals for the same symbol.
     */
    private const COOLDOWN_SECONDS = 1800; // 30 minutes

    /**
     * RSI below this value overrides an active BUY cooldown.
     * Captures signals deep in oversold territory that should never be blocked.
     */
    private const RSI_COOLDOWN_OVERRIDE_BUY = 35;

    /**
     * RSI above this value overrides an active SELL cooldown.
     * Captures signals deep in overbought territory that should never be blocked.
     */
    private const RSI_COOLDOWN_OVERRIDE_SELL = 65;

    /**
     * Evaluate whether a coin/timeframe should be forwarded to the AI service.
     *
     * Loads the latest MarketIndicator, validates required fields, applies pre-filter
     * and scoring rules, enforces cooldown, and returns a structured McpResult on
     * success or null if the coin should be skipped.
     *
     * @param  string  $coin  CoinGecko coin ID (e.g. 'bitcoin')
     * @param  string  $timeframe  Timeframe label (e.g. '5m', '15m')
     * @return McpResult|null Structured payload for the AI service, or null to skip
     */
    public function evaluate(string $coin, string $timeframe): ?McpResult
    {
        $indicator = MarketIndicator::query()
            ->where('coin', $coin)
            ->where('timeframe', $timeframe)
            ->orderByDesc('timestamp')
            ->first();

        if ($indicator === null) {
            $this->logEvaluation($coin, $timeframe, null, null, null, 0, 'none', 'neutral', false, false, 'no_indicator_data');

            return null;
        }

        // --- Defensive validation: required indicators must be non-null and non-zero ---
        $rsiRaw = $indicator->rsi;
        $ema9Raw = $indicator->ema9;
        $ema21Raw = $indicator->ema21;

        if ($rsiRaw === null || $ema9Raw === null || $ema21Raw === null) {
            $this->logEvaluation($coin, $timeframe, null, null, null, 0, 'none', 'neutral', false, false, 'missing_data');

            return null;
        }

        $rsi = (float) $rsiRaw;
        $ema9 = (float) $ema9Raw;
        $ema21 = (float) $ema21Raw;
        $currentPrice = (float) $indicator->price;

        // --- Volume ratio: optional, never blocks signal ---
        $volume = (float) ($indicator->volume ?? 0);
        $volumeMa = (float) ($indicator->volume_ma ?? 0);
        $volumeRatio = ($volumeMa > 0 && $volume > 0)
            ? round($volume / $volumeMa, 4)
            : null; // null = no volume data, treated as neutral

        $hasVolumeSpike = $volumeRatio !== null && $volumeRatio >= self::VOLUME_SPIKE_RATIO;

        $trend = $this->determineTrend($ema9, $ema21);

        // --- Pre-filter: RSI-only candidate identification (volume is NOT a gate) ---
        $candidate = $this->identifyCandidate($rsi);

        if ($candidate === null) {
            $this->logEvaluation($coin, $timeframe, $rsi, $trend, $volumeRatio, 0, 'none', 'neutral', false, false, 'no_signal');

            return null;
        }

        $entryType = $this->resolveEntryType($rsi, $candidate);
        $signalType = $this->resolveSignalType($rsi, $trend, $candidate);

        // --- Scoring ---
        $score = $this->calculateScore($rsi, $trend, $candidate, $hasVolumeSpike);

        if ($score < self::MIN_SCORE) {
            $this->logEvaluation($coin, $timeframe, $rsi, $trend, $volumeRatio, $score, $entryType, $signalType, false, false, 'low_score');

            return null;
        }

        // --- Cooldown check (with override) ---
        $cooldownOverride = false;
        $overrideReason = 'none';

        if ($this->isOnCooldown($coin)) {
            $overrideReason = $this->resolveCooldownOverride($coin, $rsi, $score, $candidate);

            if ($overrideReason === 'none') {
                $this->logEvaluation($coin, $timeframe, $rsi, $trend, $volumeRatio, $score, $entryType, $signalType, true, false, 'cooldown');

                return null;
            }

            $cooldownOverride = true;
        }

        // --- Record cooldown and log success ---
        $this->recordSignal($coin, $candidate, $score);

        $emaTrend = $ema9 >= $ema21 ? 'bullish' : 'bearish';

        $result = new McpResult(
            symbol: $coin,
            timeframe: $timeframe,
            actionCandidate: $candidate,
            score: $score,
            trend: $trend,
            rsi: $rsi,
            emaTrend: $emaTrend,
            volumeRatio: $volumeRatio ?? 0.0,
            currentPrice: $currentPrice,
        );

        $this->logEvaluation($coin, $timeframe, $rsi, $trend, $volumeRatio, $score, $entryType, $signalType, true, true, null, $cooldownOverride, $overrideReason);

        return $result;
    }

    /**
     * Determine market trend direction from EMA9 vs EMA21 alignment.
     *
     * UP when EMA9 > EMA21, DOWN when EMA9 < EMA21, SIDEWAYS when equal.
     *
     * @param  float  $ema9  Short-term EMA
     * @param  float  $ema21  Long-term EMA
     */
    private function determineTrend(float $ema9, float $ema21): MarketTrend
    {
        if ($ema9 > $ema21) {
            return MarketTrend::Up;
        }

        if ($ema9 < $ema21) {
            return MarketTrend::Down;
        }

        return MarketTrend::Sideways;
    }

    /**
     * Identify the candidate trade direction based on RSI alone.
     *
     * Volume is no longer a pre-filter gate — it is only used for scoring.
     * Returns null when RSI does not meet either threshold, causing the coin
     * to be skipped with reason "no_signal".
     *
     * @param  float  $rsi  Current RSI value
     * @return ActionCandidate|null Candidate direction, or null to skip
     */
    private function identifyCandidate(float $rsi): ?ActionCandidate
    {
        if ($rsi < self::RSI_BUY_THRESHOLD) {
            return ActionCandidate::Buy;
        }

        if ($rsi > self::RSI_SELL_THRESHOLD) {
            return ActionCandidate::Sell;
        }

        return null;
    }

    /**
     * Resolve the entry type label for logging based on RSI position.
     *
     * "strong" — RSI is in the extreme zone (< 30 BUY, > 70 SELL).
     * "early"  — RSI is between the mild and extreme zone (30–40 BUY, 60–70 SELL).
     * "none"   — RSI did not qualify (should not normally be reached after identifyCandidate).
     *
     * @param  float  $rsi  Current RSI value
     * @param  ActionCandidate  $candidate  Candidate trade direction
     */
    private function resolveEntryType(float $rsi, ActionCandidate $candidate): string
    {
        if ($candidate === ActionCandidate::Buy) {
            return $rsi < self::RSI_STRONG_BUY ? 'strong' : 'early';
        }

        return $rsi > self::RSI_STRONG_SELL ? 'strong' : 'early';
    }

    /**
     * Resolve the signal type label for logging based on trend alignment.
     *
     * "reversal"    — Strong RSI extreme in counter-trend context (reversal boost applied).
     *                 BUY with RSI < 30 in DOWN trend, or SELL with RSI > 70 in UP trend.
     * "trend_follow" — Candidate direction aligns with the current EMA trend.
     * "neutral"     — Neither aligned nor a qualifying reversal (counter-trend early entry).
     *
     * @param  float  $rsi  Current RSI value
     * @param  MarketTrend  $trend  Derived market trend
     * @param  ActionCandidate  $candidate  Candidate trade direction
     */
    private function resolveSignalType(float $rsi, MarketTrend $trend, ActionCandidate $candidate): string
    {
        if ($candidate === ActionCandidate::Buy) {
            if ($rsi < self::RSI_STRONG_BUY && $trend === MarketTrend::Down) {
                return 'reversal';
            }

            if ($trend === MarketTrend::Up) {
                return 'trend_follow';
            }
        } else {
            if ($rsi > self::RSI_STRONG_SELL && $trend === MarketTrend::Up) {
                return 'reversal';
            }

            if ($trend === MarketTrend::Down) {
                return 'trend_follow';
            }
        }

        return 'neutral';
    }

    /**
     * Calculate the cumulative signal score for a candidate.
     *
     * Scoring rules (micro-profit tuned, MIN_SCORE = 3):
     *   +2 — RSI in strong extreme zone (< 30 BUY or > 70 SELL)
     *   +1 — RSI in early zone (< 40 BUY or > 60 SELL)
     *   +2 — EMA trend aligns with candidate direction (UP for BUY, DOWN for SELL)
     *   +2 — Reversal boost: strong RSI extreme in counter-trend context
     *          (BUY RSI < 30 in DOWN trend, SELL RSI > 70 in UP trend)
     *   +0 — Weak counter-trend early entry (no penalty, no boost)
     *   +1 — Volume spike confirmed (volume_ratio >= 1.5); +0 if data absent
     *
     * Note: trend alignment and reversal boost are mutually exclusive — a coin
     * cannot be both aligned and counter-trend at the same time.
     *
     * Scoring examples:
     *   Strong oversold + UP trend      → 2 + 2 = 4 (trend_follow)
     *   Strong oversold + DOWN trend    → 2 + 2 = 4 (reversal)
     *   Early oversold + UP trend       → 1 + 2 = 3 (trend_follow, just passes)
     *   Early oversold + DOWN trend     → 1 + 0 = 1 (neutral, skipped)
     *   Strong overbought + DOWN trend  → 2 + 2 = 4 (trend_follow)
     *   Strong overbought + UP trend    → 2 + 2 = 4 (reversal)
     *
     * Maximum reachable score: 6 (strong RSI + trend/reversal + volume spike).
     * Minimum to pass: 3 (see MIN_SCORE).
     *
     * NOTE: MACD scoring is deferred until a macd/macd_signal column is added
     * to market_indicators. EMA9 vs EMA21 crossover is already captured by the
     * trend-alignment and reversal rules above.
     *
     * @param  float  $rsi  Current RSI value
     * @param  MarketTrend  $trend  Derived market trend
     * @param  ActionCandidate  $candidate  Candidate trade direction
     * @param  bool  $hasVolumeSpike  Whether volume_ratio >= 1.5 (false when data absent)
     * @return int Cumulative score (always >= 0)
     */
    private function calculateScore(
        float $rsi,
        MarketTrend $trend,
        ActionCandidate $candidate,
        bool $hasVolumeSpike,
    ): int {
        $score = 0;

        // RSI scoring — strong extreme +2, early entry +1
        if ($candidate === ActionCandidate::Buy) {
            if ($rsi < self::RSI_STRONG_BUY) {
                $score += 2; // strong oversold
            } elseif ($rsi < self::RSI_BUY_THRESHOLD) {
                $score += 1; // early oversold entry
            }
        } else {
            if ($rsi > self::RSI_STRONG_SELL) {
                $score += 2; // strong overbought
            } elseif ($rsi > self::RSI_SELL_THRESHOLD) {
                $score += 1; // early overbought entry
            }
        }

        // Trend alignment OR reversal boost (mutually exclusive)
        if ($candidate === ActionCandidate::Buy) {
            if ($trend === MarketTrend::Up) {
                $score += 2; // trend-following BUY
            } elseif ($rsi < self::RSI_STRONG_BUY && $trend === MarketTrend::Down) {
                $score += 2; // high-probability reversal: extreme oversold in downtrend
            }
            // early counter-trend (RSI 30–40 + DOWN): no boost, no penalty
        } else {
            if ($trend === MarketTrend::Down) {
                $score += 2; // trend-following SELL
            } elseif ($rsi > self::RSI_STRONG_SELL && $trend === MarketTrend::Up) {
                $score += 2; // high-probability reversal: extreme overbought in uptrend
            }
            // early counter-trend (RSI 60–70 + UP): no boost, no penalty
        }

        // Volume is optional — only boosts when confirmed, never penalises absence
        if ($hasVolumeSpike) {
            $score += 1;
        }

        return $score;
    }

    /**
     * Check whether the symbol is still within the 30-minute signal cooldown window.
     *
     * Uses Laravel Cache (Redis) to store the last signal timestamp per symbol.
     *
     * @param  string  $coin  CoinGecko coin ID
     * @return bool True if the signal should be suppressed
     */
    private function isOnCooldown(string $coin): bool
    {
        return Cache::has("signal:{$coin}");
    }

    /**
     * Determine whether an active cooldown should be overridden for this signal.
     *
     * Override conditions (evaluated in priority order):
     *   1. "strong_rsi" — RSI is deep in the extreme zone regardless of the last score.
     *        BUY:  current RSI < RSI_COOLDOWN_OVERRIDE_BUY  (35)
     *        SELL: current RSI > RSI_COOLDOWN_OVERRIDE_SELL (65)
     *   2. "score_improved" — current score is at least as high as the last recorded
     *        signal score, meaning signal quality has not degraded.
     *   3. "none" — neither condition met; cooldown is enforced.
     *
     * @param  string  $coin  CoinGecko coin ID
     * @param  float  $rsi  Current RSI value
     * @param  int  $score  Current cumulative score
     * @param  ActionCandidate  $candidate  Candidate trade direction
     * @return string strong_rsi | score_improved | none
     */
    private function resolveCooldownOverride(string $coin, float $rsi, int $score, ActionCandidate $candidate): string
    {
        if ($candidate === ActionCandidate::Buy && $rsi < self::RSI_COOLDOWN_OVERRIDE_BUY) {
            return 'strong_rsi';
        }

        if ($candidate === ActionCandidate::Sell && $rsi > self::RSI_COOLDOWN_OVERRIDE_SELL) {
            return 'strong_rsi';
        }

        $lastSignal = Cache::get("signal:{$coin}");
        $lastScore = $lastSignal['score'] ?? 0;

        if ($score >= $lastScore) {
            return 'score_improved';
        }

        return 'none';
    }

    /**
     * Record a new signal for a symbol, enforcing the cooldown window.
     *
     * Stores the candidate direction, score, and timestamp so the override logic
     * can compare quality on the next evaluation within the same cooldown window.
     *
     * @param  string  $coin  CoinGecko coin ID
     * @param  ActionCandidate  $candidate  The action that triggered the signal
     * @param  int  $score  Cumulative score of the signal being recorded
     */
    private function recordSignal(string $coin, ActionCandidate $candidate, int $score): void
    {
        Cache::put("signal:{$coin}", [
            'action' => $candidate->value,
            'score' => $score,
            'at' => now()->toIso8601String(),
        ], self::COOLDOWN_SECONDS);
    }

    /**
     * Write a structured log entry for every evaluated coin.
     *
     * Logged regardless of outcome so thresholds can be tuned by inspecting logs.
     * Fields emitted:
     *   symbol, timeframe, rsi, trend, volume_ratio, score,
     *   entry_type, signal_type, passed_mcp, sent_to_ai, skipped_reason,
     *   cooldown_override, override_reason
     *
     * score           — always int, never null.
     * volume_ratio    — null when no data, float otherwise.
     * entry_type      — strong | early | none.
     * signal_type     — reversal | trend_follow | neutral.
     * skipped_reason  — no_indicator_data | missing_data | no_signal |
     *                   low_score | cooldown | null (passed).
     * cooldown_override — true when an active cooldown was overridden.
     * override_reason   — strong_rsi | score_improved | none.
     */
    private function logEvaluation(
        string $coin,
        string $timeframe,
        ?float $rsi,
        ?MarketTrend $trend,
        ?float $volumeRatio,
        int $score,
        string $entryType,
        string $signalType,
        bool $passedMcp,
        bool $sentToAi,
        ?string $skippedReason,
        bool $cooldownOverride = false,
        string $overrideReason = 'none',
    ): void {
        Log::info('[MCPService] Evaluation', [
            'symbol' => $coin,
            'timeframe' => $timeframe,
            'rsi' => $rsi,
            'trend' => $trend?->value,
            'volume_ratio' => $volumeRatio,      // null = no data, 0+ = computed ratio
            'score' => $score,                   // always int, never null
            'entry_type' => $entryType,          // strong | early | none
            'signal_type' => $signalType,        // reversal | trend_follow | neutral
            'passed_mcp' => $passedMcp,
            'sent_to_ai' => $sentToAi,
            'skipped_reason' => $skippedReason,
            'cooldown_override' => $cooldownOverride, // true when cooldown was bypassed
            'override_reason' => $overrideReason,     // strong_rsi | score_improved | none
        ]);
    }
}
