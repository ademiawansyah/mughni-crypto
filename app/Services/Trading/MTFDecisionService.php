<?php

namespace App\Services\Trading;

use App\Models\MarketIndicator;
use App\Services\MCP\McpResult;
use Illuminate\Support\Facades\Log;

/**
 * MTFDecisionService
 *
 * Deterministic role-based MTF decision engine:
 * - 60m: Direction (hard filter)
 * - 30m: Context score (weight 2.0)
 * - 15m: Setup score (weight 2.0)
 * - 5m: Trigger score (weight 1.0)
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

        $contextScore = $this->scoreContext($contextSignal) * 2.0;
        $setupScore = $this->scoreSetup($setupSignal) * 2.0;
        $triggerScore = $this->scoreTrigger($triggerSignal) * 1.0;
        $mtfScore = round($contextScore + $setupScore + $triggerScore, 4);

        $directionMinutes = $this->timeframeToMinutes($roleTimeframes['direction']);
        $thresholdAdjustment = $directionMinutes < 60 ? 0.5 : 0.0;
        $preliminaryAction = $this->resolvePreliminaryAction($mtfScore, $thresholdAdjustment);

        if ($directionSignal->trend === 'DOWN' && $preliminaryAction === 'BUY') {
            $preliminaryAction = 'HOLD';
            $flags[] = 'direction_filter_buy_blocked';
        }

        if ($directionSignal->trend === 'UP' && $preliminaryAction === 'SELL') {
            $preliminaryAction = 'HOLD';
            $flags[] = 'direction_filter_sell_blocked';
        }

        $mode = $this->detectMode($signals);
        $baseConfidence = $this->deriveBaseConfidence($mtfScore);

        Log::info('[MTFDecisionService] MTF decision computed', [
            'execution_id' => $executionId,
            'coin' => $coin,
            'mtf_score' => $mtfScore,
            'preliminary_action' => $preliminaryAction,
            'base_confidence' => $baseConfidence,
            'mode' => $mode,
            'context_score' => $contextScore,
            'setup_score' => $setupScore,
            'trigger_score' => $triggerScore,
            'role_timeframes' => $roleTimeframes,
            'threshold_adjustment' => $thresholdAdjustment,
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

    private function resolvePreliminaryAction(float $mtfScore, float $thresholdAdjustment = 0.0): string
    {
        $buyThreshold = 2.0 + $thresholdAdjustment;
        $sellThreshold = -2.0 - $thresholdAdjustment;

        if ($mtfScore >= $buyThreshold) {
            return 'BUY';
        }

        if ($mtfScore <= $sellThreshold) {
            return 'SELL';
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
     * @param  array<string>  $sortedTimeframes
     * @return array{trigger: string, setup: string, context: string, direction: string}
     */
    private function assignRoleTimeframes(array $sortedTimeframes): array
    {
        if (count($sortedTimeframes) < 4) {
            throw new \InvalidArgumentException('MTFDecisionService requires at least 4 timeframes.');
        }

        $lastIndex = count($sortedTimeframes) - 1;

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
