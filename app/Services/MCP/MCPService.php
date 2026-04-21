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
 *   2. Determine market trend from EMA9 vs EMA21 alignment.
 *   3. Apply BUY/SELL pre-filter rules (RSI + volume spike).
 *   4. Score the candidate across multiple signal dimensions.
 *   5. Enforce a minimum score threshold before passing forward.
 *   6. Enforce a 30-minute cooldown per symbol to prevent duplicate signals.
 *   7. Return a structured McpResult, or null if the coin should be skipped.
 *
 * This service does NOT call the AI and does NOT persist anything.
 */
class MCPService
{
    /**
     * Minimum cumulative score required to forward data to the AI service.
     */
    private const MIN_SCORE = 4;

    /**
     * RSI threshold below which a BUY candidate is identified.
     */
    private const RSI_BUY_THRESHOLD = 35;

    /**
     * RSI threshold above which a SELL candidate is identified.
     */
    private const RSI_SELL_THRESHOLD = 65;

    /**
     * Minimum volume-to-average-volume ratio required (volume spike).
     */
    private const VOLUME_SPIKE_RATIO = 1.5;

    /**
     * Cooldown duration in seconds between signals for the same symbol.
     */
    private const COOLDOWN_SECONDS = 1800; // 30 minutes

    /**
     * Evaluate whether a coin/timeframe should be forwarded to the AI service.
     *
     * Loads the latest MarketIndicator, applies pre-filter and scoring rules,
     * enforces cooldown, and returns a structured McpResult on success or null
     * if the coin should be skipped.
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
            $this->logEvaluation($coin, $timeframe, null, null, null, null, false, false, 'no_indicator_data');

            return null;
        }

        $rsi = (float) $indicator->rsi;
        $ema9 = (float) $indicator->ema9;
        $ema21 = (float) $indicator->ema21;
        $volume = (float) $indicator->volume;
        $volumeMa = (float) ($indicator->volume_ma ?? 0);
        $currentPrice = (float) $indicator->price;

        $trend = $this->determineTrend($ema9, $ema21);
        $volumeRatio = $volumeMa > 0 ? round($volume / $volumeMa, 4) : 0.0;
        $hasVolumeSpike = $volumeRatio >= self::VOLUME_SPIKE_RATIO;

        // --- Pre-filter: identify candidate direction or skip entirely ---
        $candidate = $this->identifyCandidate($rsi, $hasVolumeSpike);

        if ($candidate === null) {
            $this->logEvaluation($coin, $timeframe, $rsi, $trend, $volumeRatio, null, false, false, 'no_signal');

            return null;
        }

        // --- Scoring ---
        $score = $this->calculateScore($rsi, $trend, $candidate, $hasVolumeSpike);

        if ($score < self::MIN_SCORE) {
            $this->logEvaluation($coin, $timeframe, $rsi, $trend, $volumeRatio, $score, false, false, 'low_score');

            return null;
        }

        // --- Cooldown check ---
        if ($this->isOnCooldown($coin)) {
            $this->logEvaluation($coin, $timeframe, $rsi, $trend, $volumeRatio, $score, true, false, 'cooldown');

            return null;
        }

        // --- Record cooldown and log success ---
        $this->recordSignal($coin, $candidate);

        $emaTrend = $ema9 >= $ema21 ? 'bullish' : 'bearish';

        $result = new McpResult(
            symbol: $coin,
            timeframe: $timeframe,
            actionCandidate: $candidate,
            score: $score,
            trend: $trend,
            rsi: $rsi,
            emaTrend: $emaTrend,
            volumeRatio: $volumeRatio,
            currentPrice: $currentPrice,
        );

        $this->logEvaluation($coin, $timeframe, $rsi, $trend, $volumeRatio, $score, true, true, null);

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
     * Identify the candidate trade direction based on RSI and volume conditions.
     *
     * Returns null if neither BUY nor SELL conditions are met, which causes the
     * coin to be skipped entirely without any scoring.
     *
     * @param  float  $rsi  Current RSI value
     * @param  bool  $hasVolumeSpike  Whether volume exceeds 1.5x the average
     * @return ActionCandidate|null Candidate direction, or null to skip
     */
    private function identifyCandidate(float $rsi, bool $hasVolumeSpike): ?ActionCandidate
    {
        if ($rsi < self::RSI_BUY_THRESHOLD && $hasVolumeSpike) {
            return ActionCandidate::Buy;
        }

        if ($rsi > self::RSI_SELL_THRESHOLD && $hasVolumeSpike) {
            return ActionCandidate::Sell;
        }

        return null;
    }

    /**
     * Calculate the cumulative signal score for a candidate.
     *
     * Scoring rules:
     *   +2 — RSI is in extreme zone (< 30 or > 70)
     *   +1 — RSI is in moderate zone (< 35 or > 65)
     *   +2 — EMA trend aligns with candidate direction
     *   +1 — Volume spike present
     *
     * @param  float  $rsi  Current RSI value
     * @param  MarketTrend  $trend  Derived market trend
     * @param  ActionCandidate  $candidate  Candidate trade direction
     * @param  bool  $hasVolumeSpike  Whether volume exceeds 1.5x the average
     * @return int Cumulative score
     */
    private function calculateScore(
        float $rsi,
        MarketTrend $trend,
        ActionCandidate $candidate,
        bool $hasVolumeSpike,
    ): int {
        $score = 0;

        // RSI extreme zones
        if ($candidate === ActionCandidate::Buy) {
            if ($rsi < 30) {
                $score += 2;
            } elseif ($rsi < 35) {
                $score += 1;
            }
        } else {
            if ($rsi > 70) {
                $score += 2;
            } elseif ($rsi > 65) {
                $score += 1;
            }
        }

        // EMA trend alignment
        if ($candidate === ActionCandidate::Buy && $trend === MarketTrend::Up) {
            $score += 2;
        } elseif ($candidate === ActionCandidate::Sell && $trend === MarketTrend::Down) {
            $score += 2;
        }

        // Volume spike bonus
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
     * Record a new signal for a symbol, enforcing the cooldown window.
     *
     * Stores the candidate direction alongside the timestamp so same-direction
     * spam can be detected by callers inspecting the cache value.
     *
     * @param  string  $coin  CoinGecko coin ID
     * @param  ActionCandidate  $candidate  The action that triggered the signal
     */
    private function recordSignal(string $coin, ActionCandidate $candidate): void
    {
        Cache::put("signal:{$coin}", [
            'action' => $candidate->value,
            'at' => now()->toIso8601String(),
        ], self::COOLDOWN_SECONDS);
    }

    /**
     * Write a structured log entry for every evaluated coin.
     *
     * Logged regardless of outcome so thresholds can be tuned by inspecting logs.
     */
    private function logEvaluation(
        string $coin,
        string $timeframe,
        ?float $rsi,
        ?MarketTrend $trend,
        ?float $volumeRatio,
        ?int $score,
        bool $passedMcp,
        bool $sentToAi,
        ?string $skippedReason,
    ): void {
        Log::info('[MCPService] Evaluation', [
            'symbol' => $coin,
            'timeframe' => $timeframe,
            'rsi' => $rsi,
            'trend' => $trend?->value,
            'volume_ratio' => $volumeRatio,
            'score' => $score,
            'passed_mcp' => $passedMcp,
            'sent_to_ai' => $sentToAi,
            'skipped_reason' => $skippedReason,
        ]);
    }
}
