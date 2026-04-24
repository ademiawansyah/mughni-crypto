<?php

namespace App\Services\Trading;

use App\Services\Trading\DTO\MTFContextDTO;
use App\Services\Trading\DTO\TimeframeSignalDTO as PipelineTimeframeSignalDTO;

/**
 * MTFContextService
 *
 * Converts deterministic MTF scoring output into contextual metadata for the
 * AI-first decision pipeline. This service does not decide action.
 */
class MTFContextService
{
    private const SOFT_CONFLICT_PENALTY = -0.3;

    /**
     * Default timeframe weights for the baseline 4-TF setup.
     *
     * @var array<string, float>
     */
    private const DEFAULT_TIMEFRAME_WEIGHTS = [
        '5m' => 0.35,
        '15m' => 0.25,
        '30m' => 0.20,
        '60m' => 0.20,
    ];

    public function __construct(
        private readonly ConfigService $configService,
    ) {}

    /**
     * Build MTFContextDTO from deterministic MTF result output.
     */
    public function buildDto(MTFResultDTO $mtfResult): MTFContextDTO
    {
        $signals = $this->buildPipelineSignals($mtfResult);
        $computedContext = $this->computeContext($mtfResult);
        $contextBias = $this->resolveContextBias($computedContext['mtf_score']);

        return new MTFContextDTO(
            mtfScore: $computedContext['mtf_score'],
            direction: $computedContext['direction'],
            mode: $computedContext['mode'],
            alignment: $computedContext['alignment'],
            bias: $contextBias,
            timeframeSignals: $signals,
            flags: $computedContext['flags'],
        );
    }

    /**
     * Build soft MTF context from an MTF result.
     *
     * @return array{
     *   mtf_score: float,
     *   direction: string,
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
        $computedContext = $this->computeContext($mtfResult);
        $contextBias = $this->resolveContextBias($computedContext['mtf_score']);

        return [
            'mtf_score' => $computedContext['mtf_score'],
            'direction' => $computedContext['direction'],
            'alignment' => $computedContext['alignment'],
            'context_bias' => $contextBias,
            'mode' => $computedContext['mode'],
            'base_confidence' => $mtfResult->baseConfidence,
            'flags' => $computedContext['flags'],
            'role_timeframes' => $mtfResult->roleTimeframes,
            'timeframe_signals' => $mtfResult->timeframeSignals,
        ];
    }

    /**
     * @return array<int, PipelineTimeframeSignalDTO>
     */
    private function buildPipelineSignals(MTFResultDTO $mtfResult): array
    {
        $signals = [];

        foreach ($mtfResult->timeframeSignals as $signal) {
            $signals[] = new PipelineTimeframeSignalDTO(
                timeframe: (string) $signal['timeframe'],
                rsi: (float) $signal['rsi'],
                trend: (string) $signal['trend'],
                mcpScore: (int) $signal['mcp_score'],
                signalType: (string) $signal['signal_type'],
            );
        }

        return $signals;
    }

    /**
     * @return array{mtf_score: float, direction: string, mode: string, alignment: string, flags: array<int, string>}
     */
    private function computeContext(MTFResultDTO $mtfResult): array
    {
        $timeframes = $this->resolveConfiguredTimeframes($mtfResult);
        $weights = $this->resolveNormalizedWeights($timeframes, $mtfResult->timeframeSignals);

        $mtfScore = 0.0;
        $directionScore = 0.0;
        $directionByTimeframe = [];

        foreach ($weights as $timeframe => $weight) {
            $signal = $mtfResult->timeframeSignals[$timeframe] ?? null;

            if (! is_array($signal)) {
                continue;
            }

            $score = $this->resolveScore($signal);
            $directionValue = $this->resolveDirectionValue($signal);

            $mtfScore += $score * $weight;
            $directionScore += $directionValue * $weight * $score;
            $directionByTimeframe[$timeframe] = $directionValue;
        }

        $resolvedDirection = $this->resolveDirection($directionScore);
        $alignment = $this->resolveSignalAlignment($directionByTimeframe);
        $flags = array_values(array_unique($mtfResult->flags));

        if ($this->hasHighTimeframeConflict($weights, $mtfResult->timeframeSignals, $resolvedDirection)) {
            $mtfScore -= 1.0;
            $flags[] = 'htf_conflict';
        }

        if ($alignment === 'conflict') {
            $mtfScore += self::SOFT_CONFLICT_PENALTY;
            $flags[] = 'mtf_conflict_soft';
        }

        $lowestTimeframe = $this->resolveLowestTimeframe($mtfResult->timeframeSignals);

        if ($lowestTimeframe !== null) {
            $entrySignal = $mtfResult->timeframeSignals[$lowestTimeframe] ?? null;
            $entryScore = is_array($entrySignal) ? $this->resolveScore($entrySignal) : 0.0;

            if ($entryScore >= 3.0) {
                $mtfScore += 1.0;
                $flags[] = 'entry_trigger_boost';
            }
        }

        if (abs($mtfScore) >= 0.8 && abs($mtfScore) < 1.5) {
            $flags[] = 'weak_signal_zone';
        }

        return [
            'mtf_score' => round($mtfScore, 4),
            'direction' => $resolvedDirection,
            'mode' => $this->detectMode($mtfResult->timeframeSignals),
            'alignment' => $alignment,
            'flags' => array_values(array_unique($flags)),
        ];
    }

    /**
     * @return array<string>
     */
    private function resolveConfiguredTimeframes(MTFResultDTO $mtfResult): array
    {
        $configured = $this->configService->getTimeframes();

        if ($configured !== []) {
            return array_values(array_unique($configured));
        }

        return array_values(array_unique(array_keys($mtfResult->timeframeSignals)));
    }

    /**
     * @param  array<string>  $timeframes
     * @param  array<string, array{timeframe: string, rsi: float, trend: string, mcp_score: int, signal_type: string}>  $signals
     * @return array<string, float>
     */
    private function resolveNormalizedWeights(array $timeframes, array $signals): array
    {
        $configuredWeights = $this->configuredTimeframeWeights();
        $weights = [];

        foreach ($timeframes as $timeframe) {
            if (! array_key_exists($timeframe, $signals)) {
                continue;
            }

            $weights[$timeframe] = (float) ($configuredWeights[$timeframe] ?? self::DEFAULT_TIMEFRAME_WEIGHTS[$timeframe] ?? 0.0);
        }

        $weights = array_filter($weights, static fn (float $weight): bool => $weight > 0.0);

        if ($weights === []) {
            $count = count($signals);

            if ($count === 0) {
                return [];
            }

            $equalWeight = 1.0 / $count;

            foreach (array_keys($signals) as $timeframe) {
                $weights[$timeframe] = $equalWeight;
            }

            return $weights;
        }

        $totalWeight = array_sum($weights);

        if ($totalWeight <= 0.0) {
            return [];
        }

        foreach ($weights as $timeframe => $weight) {
            $weights[$timeframe] = $weight / $totalWeight;
        }

        return $weights;
    }

    /**
     * @return array<string, float>
     */
    private function configuredTimeframeWeights(): array
    {
        $dynamicWeights = $this->configService->getTimeframeWeights();

        if ($dynamicWeights !== []) {
            return $dynamicWeights;
        }

        try {
            return (array) config('trading.timeframe_weights', []);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param  array<string, array{timeframe: string, rsi: float, trend: string, mcp_score: int, signal_type: string}>  $signals
     */
    private function resolveLowestTimeframe(array $signals): ?string
    {
        if ($signals === []) {
            return null;
        }

        $timeframes = array_keys($signals);

        usort($timeframes, fn (string $a, string $b): int => $this->timeframeToMinutes($a) <=> $this->timeframeToMinutes($b));

        return $timeframes[0] ?? null;
    }

    /**
     * @param  array{timeframe: string, rsi: float, trend: string, mcp_score: int, signal_type: string}|array<string, mixed>  $signal
     */
    private function resolveScore(array $signal): float
    {
        $rawScore = $signal['score'] ?? $signal['mcp_score'] ?? 0;

        return (float) max(0.0, min(4.0, (float) $rawScore));
    }

    /**
     * @param  array{timeframe: string, rsi: float, trend: string, mcp_score: int, signal_type: string}|array<string, mixed>  $signal
     */
    private function resolveDirectionValue(array $signal): int
    {
        $direction = strtoupper(trim((string) ($signal['direction'] ?? '')));

        if ($direction === '') {
            $trend = strtoupper(trim((string) ($signal['trend'] ?? 'NEUTRAL')));
            $direction = match ($trend) {
                'UP', 'BUY' => 'BUY',
                'DOWN', 'SELL' => 'SELL',
                default => 'HOLD',
            };
        }

        return match ($direction) {
            'BUY' => 1,
            'SELL' => -1,
            default => 0,
        };
    }

    private function resolveDirection(float $directionScore): string
    {
        if ($directionScore > 0.5) {
            return 'BUY';
        }

        if ($directionScore < -0.5) {
            return 'SELL';
        }

        return 'HOLD';
    }

    /**
     * @param  array<string, array{timeframe: string, rsi: float, trend: string, mcp_score: int, signal_type: string}>  $signals
     */
    private function detectMode(array $signals): string
    {
        foreach ($signals as $signal) {
            $rsi = (float) ($signal['rsi'] ?? 50.0);

            if ($rsi <= 25.0 || $rsi >= 75.0) {
                return 'reversal';
            }
        }

        return 'trend_follow';
    }

    /**
     * @param  array<string, int>  $directionByTimeframe
     */
    private function resolveSignalAlignment(array $directionByTimeframe): string
    {
        if ($directionByTimeframe === []) {
            return 'mixed';
        }

        $uniqueDirections = array_values(array_unique(array_values($directionByTimeframe)));

        if (count($uniqueDirections) === 1) {
            return 'aligned';
        }

        if (in_array(1, $uniqueDirections, true) && in_array(-1, $uniqueDirections, true)) {
            return 'conflict';
        }

        return 'mixed';
    }

    /**
     * @param  array<string, float>  $weights
     * @param  array<string, array{timeframe: string, rsi: float, trend: string, mcp_score: int, signal_type: string}>  $signals
     */
    private function hasHighTimeframeConflict(array $weights, array $signals, string $resolvedDirection): bool
    {
        if ($weights === [] || ! in_array($resolvedDirection, ['BUY', 'SELL'], true)) {
            return false;
        }

        $highestTimeframe = null;
        $highestMinutes = -1;

        foreach (array_keys($weights) as $timeframe) {
            $minutes = $this->timeframeToMinutes($timeframe);

            if ($minutes > $highestMinutes) {
                $highestMinutes = $minutes;
                $highestTimeframe = $timeframe;
            }
        }

        if ($highestTimeframe === null) {
            return false;
        }

        $signal = $signals[$highestTimeframe] ?? null;

        if (! is_array($signal)) {
            return false;
        }

        $htfDirectionValue = $this->resolveDirectionValue($signal);
        $htfScore = $this->resolveScore($signal);

        if ($htfScore < 3.0) {
            return false;
        }

        $resolvedDirectionValue = $resolvedDirection === 'BUY' ? 1 : -1;

        return $htfDirectionValue !== 0 && $htfDirectionValue === ($resolvedDirectionValue * -1);
    }

    private function timeframeToMinutes(string $timeframe): int
    {
        if (preg_match('/^(\d+)m$/i', trim($timeframe), $matches) === 1) {
            return (int) $matches[1];
        }

        if (preg_match('/^(\d+)h$/i', trim($timeframe), $matches) === 1) {
            return ((int) $matches[1]) * 60;
        }

        return 0;
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
}
