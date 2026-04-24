<?php

namespace App\Services\Trading;

use Illuminate\Support\Facades\Log;

/**
 * SignalActivationService
 *
 * Applies runtime aggressiveness tuning to MTF score and AI confidence.
 */
class SignalActivationService
{
    public function __construct(
        private readonly ConfigService $configService,
    ) {}

    /**
     * @return array{adjusted_score: float, adjusted_confidence: int, trigger_threshold: float, mode: string}
     */
    public function adjust(float $mtfScore, int $aiConfidence, string $mode): array
    {
        $normalizedMode = strtolower(trim($mode));

        $resolvedMode = in_array($normalizedMode, ['conservative', 'balanced', 'aggressive'], true)
            ? $normalizedMode
            : 'balanced';

        $adjustedScore = $mtfScore * 1.1;
        $adjustedConfidence = max(0, min(100, $aiConfidence));
        $triggerThreshold = 0.8;

        if ($resolvedMode === 'conservative') {
            $adjustedScore = $mtfScore * 0.8;
            $adjustedConfidence = max(0, min(100, $adjustedConfidence - 10));
            $triggerThreshold = 1.5;
        }

        if ($resolvedMode === 'aggressive') {
            $adjustedScore = $mtfScore * 1.2;
            $adjustedConfidence = max(0, min(100, $adjustedConfidence + 10));
            $triggerThreshold = 0.8;
        }

        return [
            'adjusted_score' => round($adjustedScore, 4),
            'adjusted_confidence' => $adjustedConfidence,
            'trigger_threshold' => $triggerThreshold,
            'mode' => $resolvedMode,
        ];
    }

    /**
     * @param  array<int, string>  $flags
     * @return array{adjusted_score: float, adjusted_confidence: int, trigger_threshold: float, mode: string}
     */
    public function adjustFromConfig(
        float $mtfScore,
        int $aiConfidence,
        array $flags = [],
        string $executionId = '',
        ?string $coin = null,
    ): array {
        $mode = $this->configService->getSignalActivationMode();
        $adjusted = $this->adjust($mtfScore, $aiConfidence, $mode);

        Log::info('[MTF] Adjusted score', [
            'execution_id' => $executionId,
            'coin' => $coin,
            'original_score' => round($mtfScore, 4),
            'adjusted_score' => $adjusted['adjusted_score'],
            'activation_mode' => $adjusted['mode'],
            'flags' => array_values(array_unique(array_merge($flags, ["signal_activation_{$adjusted['mode']}"]))),
        ]);

        return $adjusted;
    }
}
