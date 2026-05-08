<?php

namespace App\Services\Market;

use App\Models\Coin;
use Illuminate\Support\Facades\Log;

/**
 * PreFilterCoinService (Layer 2 - Shared Pre-Filter)
 *
 * Applies deterministic, explainable filtering rules to the Layer 1 coin set
 * already persisted in the coins table.
 *
 * Rules implemented from section 9.4:
 * - Exclude stablecoins by symbol.
 * - Exclude wrapped tokens by name keywords.
 * - Enforce minimum volume ($1,000,000).
 * - Enforce minimum market cap ($50,000,000).
 * - Require non-empty price and volume data.
 *
 * Output is persisted by updating coins.is_valid.
 */
class PreFilterCoinService
{
    /** @var array<int, string> */
    private const STABLE_COINS = [
        'usdt',
        'usdc',
        'dai',
        'busd',
        'tusd',
        'frax',
        'usdd',
        'usdp',
        'gusd',
        'lusd',
    ];

    /** @var array<int, string> */
    private const WRAPPED_TOKENS = ['wrapped', 'wbtc', 'weth', 'steth', 'reth', 'cbeth'];

    private const MINIMUM_VOLUME = 1_000_000;

    private const MINIMUM_MARKET_CAP = 50000000;

    /**
     * Run Layer 2 filtering and persist validity flags to coins table.
     *
     * @return array{processed: int, valid: int, invalid: int, updated: int}
     */
    public function filterCoins(?string $executionId = null): array
    {
        $processed = 0;
        $valid = 0;
        $invalid = 0;
        $updated = 0;

        $coins = Coin::get();
        Log::info('[PreFilterCoinService] Starting Layer 2 filtering', [
            'execution_id' => $executionId,
            'total_coins' => $coins->count(),
        ]);
        foreach ($coins as $coin) {
            $processed++;

            $validation = $this->validateCoinWithReason($coin->toArray());
            $isValid = $validation['valid'];
            $reason = $validation['reason'];

            Log::info('[PreFilterCoinService] Validating coin', [
                'execution_id' => $executionId,
                'coin_id' => $coin->id,
                'symbol' => $coin->symbol,
                'name' => $coin->name,
                'market_cap' => $coin->market_cap,
                'volume_24h' => $coin->volume_24h,
                'current_price' => $coin->current_price,
                'is_valid' => $isValid,
                'reason' => $reason,
            ]);

            if ($isValid) {
                $valid++;
            } else {
                $invalid++;
            }

            $isValidChanged = $coin->is_valid !== $isValid;

            // Persist reason even when validity status stays the same.
            $additionalData = is_array($coin->additional_data) ? $coin->additional_data : [];
            $reasonChanged = ($additionalData['shared_pre_filter_reason'] ?? null) !== $reason;

            if ($reasonChanged) {
                $additionalData['shared_pre_filter_reason'] = $reason;
                $coin->additional_data = $additionalData;
            }

            if ($isValidChanged) {
                $coin->is_valid = $isValid;
            }

            if ($isValidChanged || $reasonChanged) {
                $coin->save();
                $updated++;
            }
        }

        Log::info('[PreFilterCoinService] Layer 2 filtering completed', [
            'execution_id' => $executionId,
            'processed' => $processed,
            'valid' => $valid,
            'invalid' => $invalid,
            'updated' => $updated,
        ]);

        return [
            'processed' => $processed,
            'valid' => $valid,
            'invalid' => $invalid,
            'updated' => $updated,
        ];
    }

    /**
     * Backward-compatible alias for old callers.
     *
     * @return array{processed: int, valid: int, invalid: int, updated: int}
     */
    public function filterCoin(?string $executionId = null): array
    {
        return $this->filterCoins($executionId);
    }

    /**
     * Evaluate a single coin against Layer 2 shared pre-filter rules.
     *
     * @param  array<string, mixed>  $coin
     */
    public function isValidCoin(array $coin): bool
    {
        return $this->validateCoinWithReason($coin)['valid'];
    }

    /**
     * Validate a coin and return both validity status and reason.
     *
     * @param  array<string, mixed>  $coin
     * @return array{valid: bool, reason: string}
     */
    private function validateCoinWithReason(array $coin): array
    {
        $symbol = strtolower($coin['symbol'] ?? '');
        $name = strtolower($coin['name'] ?? '');
        $marketCap = $this->toFloat($coin['market_cap'] ?? null);

        // Layer 1 stores volume in total_volume and volume_24h for compatibility.
        $volume = $this->toFloat($coin['total_volume'] ?? null);
        if ($volume <= 0) {
            $volume = $this->toFloat($coin['volume_24h'] ?? null);
        }

        $currentPrice = $this->toFloat($coin['current_price'] ?? null);

        // Exclude stable coins
        if (in_array($symbol, self::STABLE_COINS, true)) {
            return ['valid' => false, 'reason' => 'Stablecoin detected'];
        }

        // Exclude wrapped tokens
        foreach (self::WRAPPED_TOKENS as $wrapped) {
            if (strpos($name, $wrapped) !== false) {
                return ['valid' => false, 'reason' => 'Wrapped token detected'];
            }
        }

        // Data completeness check: current_price and volume must be present and positive.
        if ($currentPrice <= 0 || $volume <= 0) {
            return ['valid' => false, 'reason' => 'Missing or invalid price/volume data'];
        }

        // Exclude low volume coins
        if ($volume < self::MINIMUM_VOLUME) {
            return ['valid' => false, 'reason' => sprintf('Volume below minimum (%.2f < %d)', $volume, self::MINIMUM_VOLUME)];
        }

        // Exclude low market cap coins
        if ($marketCap < self::MINIMUM_MARKET_CAP) {
            return ['valid' => false, 'reason' => sprintf('Market cap below minimum (%.2f < %d)', $marketCap, self::MINIMUM_MARKET_CAP)];
        }

        return ['valid' => true, 'reason' => 'Passed all pre-filter rules'];
    }

    /**
     * Convert numeric-like values to float, fallback to zero.
     */
    private function toFloat(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
