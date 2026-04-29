<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Coin extends Model
{
    // Mass assignable attributes
    protected $fillable = [
        'symbol',
        'name',
        'image',
        'coin_gecko_id',
        'coin_data_last_updated',
        'last_fetched_at',
        'market_cap',
        'volume_24h',
        'current_price',
        'raw_data',
    ];

    protected $casts = [
        'raw_data' => 'array',
    ];
}
