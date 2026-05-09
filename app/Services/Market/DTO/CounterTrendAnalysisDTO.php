<?php

namespace App\Services\Market\DTO;

/**
 * Specialized DTO for Counter Trend model analysis.
 *
 * Extends base CoinAnalysisResultDTO with Counter Trend-specific factory and field validation.
 */
final readonly class CounterTrendAnalysisDTO extends CoinAnalysisResultDTO
{
    /**
     * Construct a Counter Trend analysis DTO from service output.
     *
     * Handles both successful (analysis_status='passed') and failed (analysis_status='rejected')
     * cases from CounterTrendService::analyzeCoin() output.
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
     * Create a Counter Trend DTO for a passed coin.
     *
     * Convenience method to construct a passed analysis result.
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
            model: 'counter_trend',
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
     * Create a Counter Trend DTO for a rejected coin.
     *
     * Convenience method to construct a rejected analysis result.
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
            model: 'counter_trend',
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
