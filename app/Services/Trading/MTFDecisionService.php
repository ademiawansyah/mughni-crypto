<?php

namespace App\Services\Trading;

use App\Models\MarketIndicator;
use App\Services\MCP\McpResult;
use Illuminate\Support\Facades\Log;

/**
 * MTFDecisionService
 *
 * Deterministic multi-timeframe (MTF) decision engine that works with 1+ timeframes.
 *
 * Role assignment (flexible based on timeframe count):
 * - 1 TF: all roles use that timeframe
 * - 2 TF: trigger/setup=smallest, context/direction=largest
 * - 3 TF: trigger=smallest, setup=middle, context/direction=largest
 * - 4+ TF: trigger=smallest, setup=2nd, context=2nd_to_last, direction=largest
 */
class MTFDecisionService
{
    /**
     * Build timeframe signals and produce one deterministic MTF decision result.
     *
     * @param  string  $coin  Coin identifier.
     * @param  array<string, McpResult|null>  $mcpResults  Per-timeframe MCP results.
     * @param  array<string>  $timeframes  Requested timeframe list (e.g. ['5m', '10m', '15m', '30m']).
     * @param  string  $executionId  Traceability id.
     */
    public function evaluate(string $coin, array $mcpResults, array $timeframes, string $executionId = ''): MTFResultDTO
    {
        $sortedTimeframes = $this->sortTimeframes($timeframes);
        $roleTimeframes = $this->assignRoleTimeframes($sortedTimeframes);

        $signals = [];
        $flags = [];

        foreach ($sortedTimeframes as $timeframe) {
            $indicator = $this->fetchLatestIndicator($coin, $timeframe);

            if ($indicator === null || $indicator->rsi === null || $indicator->trend === null) {
                $signals[$timeframe] = new TimeframeSignalDTO(
                    timeframe: $timeframe,
                    rsi: 50.0,
                    trend: 'NEUTRAL',
                    mcpScore: 0,
                    signalType: 'neutral',
                );
                $flags[] = "missing_{$timeframe}_indicator";

                continue;
            }

            $normalizedTrend = $this->normalizeTrend((string) $indicator->trend);
            $rsi = (float) $indicator->rsi;
            $signalType = $this->resolveSignalType($rsi, $normalizedTrend);
            $mcpScore = (int) ($mcpResults[$timeframe]?->score ?? 0);

            $signals[$timeframe] = new TimeframeSignalDTO(
                timeframe: $timeframe,
                rsi: $rsi,
                trend: $normalizedTrend,
                mcpScore: $mcpScore,
                signalType: $signalType,
            );
        }

        $directionSignal = $signals[$roleTimeframes['direction']];
        $contextSignal = $signals[$roleTimeframes['context']];
        $setupSignal = $signals[$roleTimeframes['setup']];
        $triggerSignal = $signals[$roleTimeframes['trigger']];

        // Step 1: Detect mode (reversal if any signal has extreme RSI)
        $mode = $this->detectMode($signals);

        // Step 2: Resolve dynamic weights based on mode
        [$setupWeight, $contextWeight, $triggerWeight] = $this->resolveWeights($mode);

        // Raw component scores — setup uses ×2 scale to preserve [-4, 4] range
        $rawSetupScore = $this->scoreSetup($setupSignal) * 2.0;
        $rawContextScore = (float) $this->scoreContext($contextSignal);
        $rawTriggerScore = $this->scoreTrigger($triggerSignal);

        // Step 3: Compute weighted mtf_score
        $mtfScore = ($rawSetupScore * $setupWeight)
            + ($rawContextScore * $contextWeight)
            + ($rawTriggerScore * $triggerWeight);

        // Step 4: Hard reversal override — non-negotiable when setup is at maximum
        $reversalOverrideActive = false;
        $reversalOverrideAction = null;

        if (abs($rawSetupScore) >= 4.0 && $setupSignal->signalType === 'reversal') {
            if ($rawSetupScore >= 4.0) {
                $mtfScore = max($mtfScore, 3.0);
                $reversalOverrideAction = 'BUY';
            } else {
                $mtfScore = min($mtfScore, -3.0);
                $reversalOverrideAction = 'SELL';
            }
            $reversalOverrideActive = true;
            $flags[] = 'mtf_reversal_override';
        }

        // Step 5: Extreme RSI boost — directional, first qualifying signal wins
        foreach ($signals as $signal) {
            if ($signal->rsi <= 20.0) {
                $mtfScore += 1.0;
                $flags[] = 'extreme_rsi_boost';
                break;
            }

            if ($signal->rsi >= 80.0) {
                $mtfScore -= 1.0;
                $flags[] = 'extreme_rsi_boost';
                break;
            }
        }

        $mtfScore = round($mtfScore, 4);

        // Step 6: Resolve preliminary action using new HOLD-reducing thresholds
        $preliminaryAction = $reversalOverrideAction ?? $this->resolvePreliminaryAction($mtfScore, $flags);

        // Direction filter — skipped when reversal override is active
        if (! $reversalOverrideActive) {
            if ($directionSignal->trend === 'DOWN' && $preliminaryAction === 'BUY') {
                $preliminaryAction = 'HOLD';
                $flags[] = 'direction_filter_buy_blocked';
            }

            if ($directionSignal->trend === 'UP' && $preliminaryAction === 'SELL') {
                $preliminaryAction = 'HOLD';
                $flags[] = 'direction_filter_sell_blocked';
            }
        }

        $baseConfidence = $this->deriveBaseConfidence($mtfScore);

        Log::info('[MTFDecisionService] MTF decision computed', [
            'execution_id' => $executionId,
            'coin' => $coin,
            'mtf_score' => $mtfScore,
            'preliminary_action' => $preliminaryAction,
            'base_confidence' => $baseConfidence,
            'mode' => $mode,
            'weights' => [
                'setup' => $setupWeight,
                'context' => $contextWeight,
                'trigger' => $triggerWeight,
            ],
            'raw_scores' => [
                'setup' => $rawSetupScore,
                'context' => $rawContextScore,
                'trigger' => $rawTriggerScore,
            ],
            'role_timeframes' => $roleTimeframes,
            'reversal_override_active' => $reversalOverrideActive,
            'flags' => array_values(array_unique($flags)),
            'timeframe_signals' => $this->serializeSignals($signals),
        ]);

        return new MTFResultDTO(
            mtfScore: $mtfScore,
            preliminaryAction: $preliminaryAction,
            baseConfidence: $baseConfidence,
            mode: $mode,
            flags: array_values(array_unique($flags)),
            timeframeSignals: $this->serializeSignals($signals),
            roleTimeframes: $roleTimeframes,
        );
    }

    /**
     * Build a human-readable summary used by AI refinement prompts.
     *
     * @param  array<string, array{timeframe: string, rsi: float, trend: string, mcp_score: int, signal_type: string}>  $signals
     */
    public function buildTimeframeSummary(array $signals): string
    {
        $parts = [];

        $timeframes = $this->sortTimeframes(array_keys($signals));

        foreach ($timeframes as $timeframe) {
            $signal = $signals[$timeframe] ?? null;

            if ($signal === null) {
                $parts[] = "{$timeframe}:missing";

                continue;
            }

            $parts[] = sprintf(
                '%s:rsi=%.2f,trend=%s,mcp=%d,type=%s',
                $timeframe,
                (float) $signal['rsi'],
                (string) $signal['trend'],
                (int) $signal['mcp_score'],
                (string) $signal['signal_type'],
            );
        }

        return implode(' | ', $parts);
    }

    private function fetchLatestIndicator(string $coin, string $timeframe): ?MarketIndicator
    {
        return MarketIndicator::query()
            ->where('coin', $coin)
            ->where('timeframe', $timeframe)
            ->orderByDesc('timestamp')
            ->first();
    }

    private function normalizeTrend(string $trend): string
    {
        $normalized = strtoupper(trim($trend));

        return match ($normalized) {
            'UP', 'UPTREND' => 'UP',
            'DOWN', 'DOWNTREND' => 'DOWN',
            default => 'NEUTRAL',
        };
    }

    private function resolveSignalType(float $rsi, string $trend): string
    {
        if ($rsi < 25.0 || $rsi > 75.0) {
            return 'reversal';
        }

        if ($trend === 'UP' || $trend === 'DOWN') {
            return 'trend_follow';
        }

        return 'neutral';
    }

    private function scoreContext(TimeframeSignalDTO $signal): int
    {
        return match ($signal->trend) {
            'UP' => 1,
            'DOWN' => -1,
            default => 0,
        };
    }

    private function scoreSetup(TimeframeSignalDTO $signal): int
    {
        $rsi = $signal->rsi;

        if ($rsi < 25.0) {
            return 2;
        }

        if ($rsi > 75.0) {
            return -2;
        }

        if ($rsi >= 25.0 && $rsi <= 30.0) {
            return 1;
        }

        if ($rsi >= 70.0 && $rsi <= 75.0) {
            return -1;
        }

        if ($rsi >= 55.0 && $rsi <= 65.0 && $signal->trend === 'UP') {
            return 1;
        }

        if ($rsi >= 35.0 && $rsi <= 45.0 && $signal->trend === 'DOWN') {
            return -1;
        }

        return 0;
    }

    private function scoreTrigger(TimeframeSignalDTO $signal): float
    {
        return match ($signal->trend) {
            'UP' => 0.5,
            'DOWN' => -0.5,
            default => 0.0,
        };
    }

    /**
     * Resolve dynamic scoring weights based on detected mode.
     *
     * Reversal mode amplifies setup (RSI extremes) and reduces context dominance.
     * Trend-follow mode balances setup and context equally.
     *
     * @return array{0: float, 1: float, 2: float} [setupWeight, contextWeight, triggerWeight]
     */
    private function resolveWeights(string $mode): array
    {
        if ($mode === 'reversal') {
            return [0.70, 0.10, 0.20];
        }

        return [0.40, 0.40, 0.20];
    }

    /**
     * Resolve preliminary action from weighted mtf_score using HOLD-reducing thresholds.
     *
     * Thresholds: ≥2.5 → strong directional, 1.0–2.5 → weak directional (flagged), <1.0 → HOLD.
     *
     * @param  array<int, string>  $flags  Passed by reference so weak-signal flags can be appended.
     */
    private function resolvePreliminaryAction(float $mtfScore, array &$flags): string
    {
        $absScore = abs($mtfScore);
        $direction = $mtfScore >= 0.0 ? 'BUY' : 'SELL';

        if ($absScore >= 2.5) {
            return $direction;
        }

        if ($absScore >= 1.0) {
            $flags[] = $direction === 'BUY' ? 'weak_buy_signal' : 'weak_sell_signal';

            return $direction;
        }

        return 'HOLD';
    }

    /**
     * @param  array<string>  $timeframes
     * @return array<string>
     */
    private function sortTimeframes(array $timeframes): array
    {
        $unique = array_values(array_unique($timeframes));

        usort($unique, fn (string $a, string $b): int => $this->timeframeToMinutes($a) <=> $this->timeframeToMinutes($b));

        return $unique;
    }

    /**
     * Assign roles to timeframes based on their duration.
     *
     * Works with any number of timeframes:
     * - 1 TF: all roles assigned to that timeframe
     * - 2 TF: trigger/setup use smallest, context/direction use largest
     * - 3+ TF: trigger=smallest, setup=2nd, context=2nd_to_last, direction=largest
     *
     * @param  array<string>  $sortedTimeframes
     * @return array{trigger: string, setup: string, context: string, direction: string}
     */
    private function assignRoleTimeframes(array $sortedTimeframes): array
    {
        $count = count($sortedTimeframes);

        if ($count === 0) {
            throw new \InvalidArgumentException('At least 1 timeframe is required.');
        }

        if ($count === 1) {
            // All roles use the single timeframe
            $tf = $sortedTimeframes[0];

            return [
                'trigger' => $tf,
                'setup' => $tf,
                'context' => $tf,
                'direction' => $tf,
            ];
        }

        if ($count === 2) {
            // trigger/setup=smallest, context/direction=largest
            return [
                'trigger' => $sortedTimeframes[0],
                'setup' => $sortedTimeframes[0],
                'context' => $sortedTimeframes[1],
                'direction' => $sortedTimeframes[1],
            ];
        }

        if ($count === 3) {
            // trigger=smallest, setup=middle, context/direction=largest
            return [
                'trigger' => $sortedTimeframes[0],
                'setup' => $sortedTimeframes[1],
                'context' => $sortedTimeframes[2],
                'direction' => $sortedTimeframes[2],
            ];
        }

        // 4+ timeframes: trigger=smallest, setup=2nd, context=2nd_to_last, direction=largest
        $lastIndex = $count - 1;

        return [
            'trigger' => $sortedTimeframes[0],
            'setup' => $sortedTimeframes[1],
            'context' => $sortedTimeframes[$lastIndex - 1],
            'direction' => $sortedTimeframes[$lastIndex],
        ];
    }

    private function timeframeToMinutes(string $timeframe): int
    {
        if (preg_match('/^(\d+)m$/i', trim($timeframe), $matches) === 1) {
            return (int) $matches[1];
        }

        if (preg_match('/^(\d+)h$/i', trim($timeframe), $matches) === 1) {
            return ((int) $matches[1]) * 60;
        }

        return PHP_INT_MAX;
    }

    /**
     * @param  array<string, TimeframeSignalDTO>  $signals
     */
    private function detectMode(array $signals): string
    {
        foreach ($signals as $signal) {
            if ($signal->rsi < 25.0 || $signal->rsi > 75.0) {
                return 'reversal';
            }
        }

        return 'trend_follow';
    }

    private function deriveBaseConfidence(float $mtfScore): int
    {
        return (int) min(85, max(50, 50 + (abs($mtfScore) * 10)));
    }

    /**
     * @param  array<string, TimeframeSignalDTO>  $signals
     * @return array<string, array{timeframe: string, rsi: float, trend: string, mcp_score: int, signal_type: string}>
     */
    private function serializeSignals(array $signals): array
    {
        $serialized = [];

        foreach ($signals as $timeframe => $signal) {
            $serialized[$timeframe] = $signal->toArray();
        }

        return $serialized;
    }
}
