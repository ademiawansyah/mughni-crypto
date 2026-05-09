<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Represents an exchange rate between two currencies.
 *
 * @property int $id
 * @property string $from_currency Source currency (e.g., 'USD', 'USDT')
 * @property string $to_currency Target currency (e.g., 'IDR')
 * @property float $rate Exchange rate value
 * @property string $source Source of the rate (e.g., 'indodax', 'coingecko')
 * @property Carbon|null $refreshed_at When the rate was last fetched
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class ExchangeRate extends Model
{
    protected $fillable = [
        'from_currency',
        'to_currency',
        'rate',
        'source',
        'refreshed_at',
    ];

    protected $casts = [
        'rate' => 'float',
        'refreshed_at' => 'datetime',
    ];

    /**
     * Get the exchange rate for a specific currency pair.
     *
     * @param  string  $from  Source currency (e.g., 'USD')
     * @param  string  $to  Target currency (e.g., 'IDR')
     * @return float|null The exchange rate, or null if not found
     */
    public static function getRate(string $from, string $to): ?float
    {
        $rate = static::where('from_currency', strtoupper($from))
            ->where('to_currency', strtoupper($to))
            ->first();

        return $rate?->rate;
    }

    /**
     * Check if a rate is recent (updated within the last hour).
     */
    public function isRecent(): bool
    {
        return $this->refreshed_at && $this->refreshed_at->isAfter(now()->subHour());
    }
}
