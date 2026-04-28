<?php

namespace App\Services\Market\Models;

/**
 * Immutable signal payload produced by a trading model.
 *
 * @param  array<string, float>  $componentScores
 * @param  array<string, mixed>  $context
 * @param  array<int, string>  $reasons
 */
class ModelSignalDTO
{
    public function __construct(
        public readonly string $model,
        public readonly string $coin,
        public readonly string $action,
        public readonly int $score,
        public readonly string $primaryTimeframe,
        public readonly array $componentScores,
        public readonly array $context,
        public readonly array $reasons,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'model' => $this->model,
            'coin' => $this->coin,
            'action' => $this->action,
            'score' => $this->score,
            'primary_timeframe' => $this->primaryTimeframe,
            'component_scores' => $this->componentScores,
            'context' => $this->context,
            'reasons' => $this->reasons,
        ];
    }
}
