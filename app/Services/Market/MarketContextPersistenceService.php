<?php

namespace App\Services\Market;

use App\Models\MarketContext;
use App\Services\Trading\DTO\MTFContextDTO;
use Illuminate\Support\Facades\Log;

/**
 * MarketContextPersistenceService
 *
 * Persists multi-timeframe context into the market_contexts table for
 * observability and dashboard usage.  This service is CONTEXT-only —
 * it never stores action, confidence, or AI output.
 */
class MarketContextPersistenceService
{
    /**
     * Persist each timeframe signal contained in the MTFContextDTO.
     *
     * One row is upserted per (coin, timeframe, source="mtf") tuple.
     *
     * @param  MTFContextDTO  $mtf  Aggregated multi-timeframe context DTO.
     * @param  string  $coin  The coin symbol (e.g. "BTC", "ETH").
     */
    public function persist(MTFContextDTO $mtf, string $coin): void
    {
        foreach ($mtf->timeframeSignals as $signal) {
            try {
                $payload = [
                    'market_regime' => $mtf->mode,
                    'sentiment' => $this->normalizeAlignment($mtf->alignment),
                    'timestamp' => now(),
                ];

                $supportLevel = $this->resolveOptionalLevel($signal, 'supportLevel');
                $resistanceLevel = $this->resolveOptionalLevel($signal, 'resistanceLevel');

                if ($supportLevel !== null) {
                    $payload['support_level'] = $supportLevel;
                }

                if ($resistanceLevel !== null) {
                    $payload['resistance_level'] = $resistanceLevel;
                }

                MarketContext::updateOrCreate(
                    [
                        'coin' => $coin,
                        'timeframe' => $signal->timeframe,
                        'source' => 'mtf',
                    ],
                    $payload,
                );
            } catch (\Throwable $e) {
                Log::warning('[MarketContextPersistenceService] Failed to persist MTF context', [
                    'coin' => $coin,
                    'timeframe' => $signal->timeframe,
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Backward-compatible alias for older pipeline call sites.
     */
    public function persistFromMTF(MTFContextDTO $mtf, string $coin): void
    {
        $this->persist($mtf, $coin);
    }

    private function normalizeAlignment(string $alignment): string
    {
        return match (strtolower(trim($alignment))) {
            'aligned' => 'aligned',
            'contradictory', 'opposed', 'conflict' => 'conflict',
            'neutral', 'mixed' => 'mixed',
            default => 'mixed',
        };
    }

    private function resolveOptionalLevel(object $signal, string $property): ?float
    {
        if (! property_exists($signal, $property)) {
            return null;
        }

        $value = $signal->{$property};

        return is_numeric($value) ? (float) $value : null;
    }
}
