<?php

namespace App\Services\Market\DTO;

/**
 * Standard Data Transfer Object for post-Layer-2 coin analysis results.
 *
 * This DTO encapsulates both successful and failed analysis outcomes from all 4 market models.
 * It serves as the canonical contract between model services and downstream consumers
 * (persistence layer, notifications, Filament UI).
 *
 * Properties:
 * - model: The model name (e.g., 'counter_trend', 'pre_pump', 'trend_momentum', 'spot_momentum_gainer')
 * - execution_id: Unique identifier for this analysis execution (for traceability)
 * - coin_id: CoinGecko coin ID (e.g., 'akash-network')
 * - symbol: Coin symbol in uppercase (e.g., 'AKT')
 * - analysis_status: Either 'passed' (passed pre-filter) or 'rejected' (failed pre-filter)
 * - score: Final calculated score (0–100, float to support SpotMomentumGainer precision)
 * - price: Current price at analysis time (nullable for rejected coins without price data)
 * - signal: Structured signal output object if passed; null if rejected
 * - rejection_reason: Reason for rejection if analysis_status='rejected'; null if passed
 * - rejection_context: Additional context/diagnostics for rejection (or empty array if passed)
 * - components: Component-level scoring breakdown (raw indicators, sub-scores)
 * - metadata: Model-specific and shared metadata (timeframes, strategy, stop_loss, derivatives, etc.)
 */
readonly class CoinAnalysisResultDTO
{
    public function __construct(
        public string $model,
        public string $execution_id,
        public int $coin_id,
        public string $symbol,
        public string $analysis_status,  // 'passed' | 'rejected'
        public float $score,
        public ?float $price,
        public ?array $signal,
        public ?string $rejection_reason,
        public array $rejection_context,
        public array $components,
        public array $metadata,
    ) {}

    /**
     * Serialize the DTO to an associative array.
     *
     * This method ensures deterministic serialization for storage, transmission, and UI rendering.
     * The output structure is designed to be compatible with existing persistence layer (JSON casts)
     * and downstream consumers (NotificationService, Filament widgets) via dot notation access.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'model' => $this->model,
            'execution_id' => $this->execution_id,
            'coin_id' => $this->coin_id,
            'symbol' => $this->symbol,
            'analysis_status' => $this->analysis_status,
            'score' => $this->score,
            'price' => $this->price,
            'signal' => $this->signal,
            'rejection_reason' => $this->rejection_reason,
            'rejection_context' => $this->rejection_context,
            'components' => $this->components,
            'metadata' => $this->metadata,
        ];
    }
}
