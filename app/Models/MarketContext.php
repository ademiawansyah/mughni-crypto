<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MarketContext extends Model
{
    use HasFactory;

    protected $table = 'market_contexts';

    protected $fillable = [
        'coin',
        'timeframe',
        'market_regime',
        'support_level',
        'resistance_level',
        'sentiment',
        'source',
        'timestamp',
    ];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'timestamp' => 'datetime',
            'support_level' => 'decimal:8',
            'resistance_level' => 'decimal:8',
        ];
    }
}
