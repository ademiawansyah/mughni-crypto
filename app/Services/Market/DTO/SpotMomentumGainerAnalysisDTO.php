<?php

namespace App\Services\Market\DTO;

/**
 * Specialized DTO for Spot Momentum Gainer model analysis.
 *
 * Extends base CoinAnalysisResultDTO with Spot Momentum Gainer-specific factory and field validation.
 * Note: This model uses float scores (normalized 0-100 with 2 decimal precision).
 */
final readonly class SpotMomentumGainerAnalysisDTO extends CoinAnalysisResultDTO
{
    /**
     * Construct a Spot Momentum Gainer analysis DTO from service output.
     *
     * Handles both successful (analysis_status='passed') and failed (analysis_status='rejected')
     * cases from SpotMomentumGainerService::analyzeCandidate() output.
     *
     * @param  array{
     *   execution_id: string,
     *   model: string,
     *   coin_id: int,
     *   symbol: string,
     *   score: float,
     *   price: ?float,
     *   signal: ?array,
     *   rejection_reason: ?string,
     *   rejection_context: array,
     *   components: array,
     * }  $data
     */
    public static function fromAnalysisOutput(array $data): self
    {
        $status = $data['signal'] !== null ? 'passed' : 'rejected';

        return new self(
            model: $data['model'],
            execution_id: $data['execution_id'],
            coin_id: $data['coin_id'],
            symbol: $data['symbol'],
            analysis_status: $status,
            score: (float) ($data['score'] ?? 0.0),
            price: $data['price'],
            signal: $data['signal'],
            rejection_reason: $data['rejection_reason'],
            rejection_context: $data['rejection_context'] ?? [],
            components: $data['components'] ?? [],
            metadata: $data['metadata'] ?? [],
        );
    }

    /**
     * Create a Spot Momentum Gainer DTO for a passed coin.
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
            model: 'spot_momentum_gainer',
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
     * Create a Spot Momentum Gainer DTO for a rejected coin.
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
            model: 'spot_momentum_gainer',
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
