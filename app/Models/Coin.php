<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Coin extends Model
{
    /**
     * Mass assignable attributes.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'symbol',
        'name',
        'image',
        'coin_gecko_id',
        'coin_data_last_updated',
        'last_fetched_at',
        'market_cap',
        'total_volume',
        'volume_24h',
        'current_price',
        'raw_data',
        'is_valid',
    ];

    /**
     * Attribute casts.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'raw_data' => 'array',
        'coin_data_last_updated' => 'datetime',
        'last_fetched_at' => 'datetime',
        'is_valid' => 'boolean',
    ];
}
