<?php

namespace App\Services\Market\DTO;

/**
 * Specialized DTO for Daily Safe Momentum model analysis.
 */
final readonly class DailySafeMomentumAnalysisDTO extends CoinAnalysisResultDTO
{
    /**
     * Create a Daily Safe Momentum DTO for a passed coin.
     */
    public static function passed(
        string $executionId,
        int $coinId,
        string $symbol,
        float $score,
        float $price,
        array $signal,
        array $components,
        array $metadata,
    ): self {
        return new self(
            model: 'daily_safe_momentum',
            execution_id: $executionId,
            coin_id: $coinId,
            symbol: $symbol,
            analysis_status: 'passed',
            score: $score,
            price: $price,
            signal: $signal,
            rejection_reason: null,
            rejection_context: [],
            components: $components,
            metadata: $metadata,
        );
    }

    /**
     * Create a Daily Safe Momentum DTO for a rejected coin.
     */
    public static function rejected(
        string $executionId,
        int $coinId,
        string $symbol,
        string $rejectionReason,
        array $rejectionContext,
        ?float $score = null,
        ?float $price = null,
    ): self {
        return new self(
            model: 'daily_safe_momentum',
            execution_id: $executionId,
            coin_id: $coinId,
            symbol: $symbol,
            analysis_status: 'rejected',
            score: $score ?? 0.0,
            price: $price,
            signal: null,
            rejection_reason: $rejectionReason,
            rejection_context: $rejectionContext,
            components: [],
            metadata: [],
        );
    }
}
