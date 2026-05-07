<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CoinMarketData extends Model
{
    protected $fillable = [
        'coin_id',
        'data_type',
        'source',
        'interval',
        'data',
    ];

    protected $casts = [
        'data' => 'array',
    ];

    public function coin()
    {
        return $this->belongsTo(Coin::class);
    }
}
